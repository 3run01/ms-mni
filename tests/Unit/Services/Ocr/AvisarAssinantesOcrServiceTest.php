<?php

use App\Jobs\EnviarAvisoOcrJob;
use App\Models\Processo;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Models\Tribunal;
use App\Services\Ocr\AvisarAssinantesOcrService;
use App\Services\Ocr\ContagemOcr;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

/*
 * Geração dos avisos ocr_progresso (T13, RF-14, RF-15, CT-02): um aviso por
 * assinante ativo, payload exato de AvisoDeProgressoDoOcr, `codigo` por papel
 * só no final `falhou` com recusa, despacho afterCommit na fila ocr-aviso e
 * nada depois do final (Q-03).
 */

uses(DatabaseTransactions::class);

const NUMERO_AVISAR_OCR = '12345674220268260100';
const CONTAGENS_AVISAR_OCR = ['total' => 300, 'processados' => 120, 'com_erro' => 1, 'ignorados' => 4];

beforeEach(function () {
    Queue::fake();

    $tribunal = Tribunal::factory()->create(['ativo' => true]);
    $processo = Processo::factory()->create([
        'tribunal_id' => $tribunal->id,
        'numero_processo' => NUMERO_AVISAR_OCR,
    ]);

    $this->tribunal = $tribunal;
    $this->processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);
    $this->dono = assinanteAvisarOcr($this->processamento, 'https://sistema.example/mni/p/1/progresso?signature=dono', 'tok-dono', true);
    $this->carona1 = assinanteAvisarOcr($this->processamento, 'https://sistema.example/mni/p/2/progresso?signature=c1', 'tok-carona-1');
    $this->carona2 = assinanteAvisarOcr($this->processamento, 'https://carona.example/aviso?assinatura=c2', 'tok-carona-2');
});

function assinanteAvisarOcr(ProcessoOcrProcessamento $processamento, string $url, string $token, bool $dono = false, array $atributos = []): ProcessoOcrAssinante
{
    return ProcessoOcrAssinante::create(array_merge([
        'processamento_id' => $processamento->id,
        'dono' => $dono,
        'callback_url' => $url,
        'callback_token' => $token,
    ], $atributos));
}

function servicoAvisarOcr(): AvisarAssinantesOcrService
{
    return app(AvisarAssinantesOcrService::class);
}

/**
 * @return Collection<int, ProcessoOcrAviso>
 */
function avisosAvisarOcr(ProcessoOcrAssinante $assinante): Collection
{
    return ProcessoOcrAviso::where('assinante_id', $assinante->id)->orderBy('id')->get();
}

/**
 * Confere o payload contra o schema AvisoDeProgressoDoOcr do contrato:
 * nenhuma chave fora de `properties`, todas as `required`, tipos, `enum`,
 * `pattern`, `minimum` e o if/then de `codigo` só com `falhou`.
 */
function expectAvisoConformeContratoOcr(array $payload): void
{
    $schema = Yaml::parseFile(base_path('.spec/features/ocr-do-processo-para-o-sistema/openapi.yaml'))['components']['schemas']['AvisoDeProgressoDoOcr'];

    expect(array_values(array_diff(array_keys($payload), array_keys($schema['properties']))))->toBe([]);

    foreach ($schema['required'] as $chave) {
        expect($payload)->toHaveKey($chave);
    }

    foreach ($payload as $chave => $valor) {
        $propriedade = $schema['properties'][$chave];

        match ($propriedade['type']) {
            'string' => expect($valor)->toBeString(),
            'integer' => expect($valor)->toBeInt(),
        };

        if (isset($propriedade['enum'])) {
            expect($valor)->toBeIn($propriedade['enum']);
        }
        if (isset($propriedade['pattern'])) {
            expect($valor)->toMatch('/'.$propriedade['pattern'].'/');
        }
        if (isset($propriedade['minimum'])) {
            expect($valor)->toBeGreaterThanOrEqual($propriedade['minimum']);
        }
    }

    if (array_key_exists('codigo', $payload)) {
        expect($payload['situacao'])->toBe($schema['then']['properties']['situacao']['const']);
    }
}

// ---------- progresso (RF-14) ----------

it('avisarProgresso cria um aviso em_andamento por assinante ativo, com as 7 chaves do contrato', function () {
    $gerados = servicoAvisarOcr()->avisarProgresso($this->processamento, CONTAGENS_AVISAR_OCR);

    expect($gerados)->toBe(3);

    foreach ([$this->dono, $this->carona1, $this->carona2] as $assinante) {
        $avisos = avisosAvisarOcr($assinante);

        expect($avisos)->toHaveCount(1)
            ->and($avisos[0]->final)->toBeFalse()
            ->and($avisos[0]->enviado_em)->toBeNull()
            ->and($avisos[0]->tentativas)->toBe(0)
            ->and($avisos[0]->uuid)->toBeString()->not->toBeEmpty()
            ->and($avisos[0]->payload)->toBe([
                'numero_processo' => NUMERO_AVISAR_OCR,
                'tribunal_id' => $this->tribunal->id,
                'total' => 300,
                'processados' => 120,
                'com_erro' => 1,
                'ignorados' => 4,
                'situacao' => 'em_andamento',
            ]);

        expectAvisoConformeContratoOcr($avisos[0]->payload);
    }
});

it('aceita as contagens de ContagemOcr::paraCorpo()', function () {
    servicoAvisarOcr()->avisarProgresso($this->processamento, (new ContagemOcr(5, 2, 1, 2, 1, 1))->paraCorpo());

    expect(avisosAvisarOcr($this->dono)[0]->payload)->toMatchArray([
        'total' => 5,
        'processados' => 2,
        'com_erro' => 1,
        'ignorados' => 2,
    ])->not->toHaveKeys(['pendentes', 'aguardando']);
});

it('despacha um EnviarAvisoOcrJob por aviso, afterCommit, na fila ocr-aviso', function () {
    servicoAvisarOcr()->avisarProgresso($this->processamento, CONTAGENS_AVISAR_OCR);

    $ids = ProcessoOcrAviso::whereIn('assinante_id', [$this->dono->id, $this->carona1->id, $this->carona2->id])
        ->pluck('id')->sort()->values()->all();

    Queue::assertPushed(EnviarAvisoOcrJob::class, 3);

    $despachados = [];
    Queue::assertPushedOn('ocr-aviso', EnviarAvisoOcrJob::class, function (EnviarAvisoOcrJob $job) use (&$despachados) {
        $despachados[] = $job->avisoId;

        return $job->afterCommit === true;
    });

    expect(collect($despachados)->unique()->sort()->values()->all())->toBe($ids);
});

it('não gera aviso para assinante encerrado nem para quem já recebeu o final', function () {
    $this->carona1->update(['encerrado_em' => now()]);
    $this->carona2->update(['aviso_final_entregue_em' => now()]);

    expect(servicoAvisarOcr()->avisarProgresso($this->processamento, CONTAGENS_AVISAR_OCR))->toBe(1)
        ->and(avisosAvisarOcr($this->dono))->toHaveCount(1)
        ->and(avisosAvisarOcr($this->carona1))->toHaveCount(0)
        ->and(avisosAvisarOcr($this->carona2))->toHaveCount(0);

    Queue::assertPushed(EnviarAvisoOcrJob::class, 1);
});

it('não gera aviso para assinante de outro processamento', function () {
    $outro = ProcessoOcrProcessamento::create(['processo_id' => Processo::factory()->create()->id]);
    $assinanteDeOutro = assinanteAvisarOcr($outro, 'https://sistema.example/outro', 'tok-outro', true);

    servicoAvisarOcr()->avisarProgresso($this->processamento, CONTAGENS_AVISAR_OCR);

    expect(avisosAvisarOcr($assinanteDeOutro))->toHaveCount(0);
});

it('não gera progresso com o processamento encerrado (Q-03)', function (string $situacao) {
    $this->processamento->update(['situacao' => $situacao, 'finalizado_em' => now()]);

    expect(servicoAvisarOcr()->avisarProgresso($this->processamento, CONTAGENS_AVISAR_OCR))->toBe(0)
        ->and(ProcessoOcrAviso::whereIn('assinante_id', [$this->dono->id, $this->carona1->id, $this->carona2->id])->count())->toBe(0);

    Queue::assertNothingPushed();
})->with(['concluido', 'falhou']);

it('não gera progresso para quem já tem o final gerado, ainda não entregue (Q-03)', function () {
    servicoAvisarOcr()->avisarFinal($this->processamento, CONTAGENS_AVISAR_OCR, 'concluido');

    expect(servicoAvisarOcr()->avisarProgresso($this->processamento, CONTAGENS_AVISAR_OCR))->toBe(0);

    foreach ([$this->dono, $this->carona1, $this->carona2] as $assinante) {
        expect(avisosAvisarOcr($assinante))->toHaveCount(1)
            ->and(avisosAvisarOcr($assinante)[0]->final)->toBeTrue();
    }
});

// ---------- final (RF-15) ----------

it('avisarFinal concluido gera 1 final por assinante, sem codigo', function () {
    $contagens = ['total' => 4, 'processados' => 3, 'com_erro' => 1, 'ignorados' => 0];

    expect(servicoAvisarOcr()->avisarFinal($this->processamento, $contagens, 'concluido'))->toBe(3);

    foreach ([$this->dono, $this->carona1, $this->carona2] as $assinante) {
        $aviso = avisosAvisarOcr($assinante)->sole();

        expect($aviso->final)->toBeTrue()
            ->and($aviso->payload)->toBe([
                'numero_processo' => NUMERO_AVISAR_OCR,
                'tribunal_id' => $this->tribunal->id,
                'total' => 4,
                'processados' => 3,
                'com_erro' => 1,
                'ignorados' => 0,
                'situacao' => 'concluido',
            ]);

        expectAvisoConformeContratoOcr($aviso->payload);
    }
});

it('avisarFinal falhou por prazo vai sem codigo', function () {
    servicoAvisarOcr()->avisarFinal($this->processamento, CONTAGENS_AVISAR_OCR, 'falhou');

    foreach ([$this->dono, $this->carona1, $this->carona2] as $assinante) {
        $payload = avisosAvisarOcr($assinante)->sole()->payload;

        expect($payload['situacao'])->toBe('falhou')
            ->and($payload)->not->toHaveKey('codigo')
            ->and($payload)->toHaveCount(7);

        expectAvisoConformeContratoOcr($payload);
    }
});

it('avisarFinal falhou com recusa dá credencial_pje_recusada ao dono e credencial_de_outro_pedido_recusada a cada carona', function () {
    servicoAvisarOcr()->avisarFinal($this->processamento, CONTAGENS_AVISAR_OCR, 'falhou', 'credencial_pje_recusada');

    $dono = avisosAvisarOcr($this->dono)->sole()->payload;
    $carona1 = avisosAvisarOcr($this->carona1)->sole()->payload;
    $carona2 = avisosAvisarOcr($this->carona2)->sole()->payload;

    expect($dono)->toBe([
        'numero_processo' => NUMERO_AVISAR_OCR,
        'tribunal_id' => $this->tribunal->id,
        'total' => 300,
        'processados' => 120,
        'com_erro' => 1,
        'ignorados' => 4,
        'situacao' => 'falhou',
        'codigo' => 'credencial_pje_recusada',
    ])
        ->and($carona1['codigo'])->toBe('credencial_de_outro_pedido_recusada')
        ->and($carona2['codigo'])->toBe('credencial_de_outro_pedido_recusada');

    foreach ([$dono, $carona1, $carona2] as $payload) {
        expectAvisoConformeContratoOcr($payload);
    }
});

it('avisarFinal chamado 2 vezes gera 1 final por assinante', function () {
    servicoAvisarOcr()->avisarFinal($this->processamento, CONTAGENS_AVISAR_OCR, 'falhou', 'credencial_pje_recusada');

    expect(servicoAvisarOcr()->avisarFinal($this->processamento, CONTAGENS_AVISAR_OCR, 'falhou', 'credencial_pje_recusada'))->toBe(0);

    foreach ([$this->dono, $this->carona1, $this->carona2] as $assinante) {
        expect(avisosAvisarOcr($assinante)->where('final', true))->toHaveCount(1);
    }

    Queue::assertPushed(EnviarAvisoOcrJob::class, 3);
});

it('avisarFinal não alcança assinante encerrado nem quem já recebeu o final', function () {
    $this->carona1->update(['encerrado_em' => now()]);
    $this->carona2->update(['aviso_final_entregue_em' => now()]);

    expect(servicoAvisarOcr()->avisarFinal($this->processamento, CONTAGENS_AVISAR_OCR, 'concluido'))->toBe(1)
        ->and(avisosAvisarOcr($this->carona1))->toHaveCount(0)
        ->and(avisosAvisarOcr($this->carona2))->toHaveCount(0);
});

it('avisarFinal recusa situação, código e contagens fora do contrato', function (Closure $chamada) {
    expect(fn () => $chamada(servicoAvisarOcr(), $this->processamento))->toThrow(InvalidArgumentException::class);

    Queue::assertNothingPushed();
})->with([
    'situação em_andamento' => [fn ($s, $p) => $s->avisarFinal($p, CONTAGENS_AVISAR_OCR, 'em_andamento')],
    'situação desconhecida' => [fn ($s, $p) => $s->avisarFinal($p, CONTAGENS_AVISAR_OCR, 'cancelado')],
    'código com concluido' => [fn ($s, $p) => $s->avisarFinal($p, CONTAGENS_AVISAR_OCR, 'concluido', 'credencial_pje_recusada')],
    'código fora do enum' => [fn ($s, $p) => $s->avisarFinal($p, CONTAGENS_AVISAR_OCR, 'falhou', 'outro_codigo')],
    'código de carona passado por quem chama' => [fn ($s, $p) => $s->avisarFinal($p, CONTAGENS_AVISAR_OCR, 'falhou', 'credencial_de_outro_pedido_recusada')],
    'contagem ausente' => [fn ($s, $p) => $s->avisarProgresso($p, ['total' => 1, 'processados' => 0, 'com_erro' => 0])],
    'contagem negativa' => [fn ($s, $p) => $s->avisarProgresso($p, ['total' => 1, 'processados' => -1, 'com_erro' => 0, 'ignorados' => 0])],
]);

// ---------- afterCommit ----------

it('os avisos de uma transação desfeita não são gerados nem enviados', function () {
    // Fila real (sync, do phpunit.xml) no lugar do Queue::fake do beforeEach.
    Queue::swap(Queue::getFacadeRoot()->queue);
    config()->set('queue.default', 'sync');
    // Hosts liberados fora de produção (RF-17): o envio não depende de DNS.
    config()->set('pje.callback.hosts_liberados', ['sistema.example', 'carona.example']);
    Http::fake(['*' => Http::response('', 200)]);

    try {
        DB::transaction(function () {
            servicoAvisarOcr()->avisarProgresso($this->processamento, CONTAGENS_AVISAR_OCR);

            throw new RuntimeException('mudança desfeita');
        });
    } catch (RuntimeException) {
    }

    Http::assertNothingSent();
    expect(ProcessoOcrAviso::whereIn('assinante_id', [$this->dono->id, $this->carona1->id, $this->carona2->id])->count())->toBe(0);

    DB::transaction(fn () => servicoAvisarOcr()->avisarProgresso($this->processamento, CONTAGENS_AVISAR_OCR));

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://carona.example/aviso?assinatura=c2'
        && $request->hasHeader('X-API-Token', 'tok-carona-2'));
});
