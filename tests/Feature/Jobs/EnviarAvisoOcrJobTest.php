<?php

use App\Jobs\EnviarAvisoOcrJob;
use App\Models\Processo;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Models\Tribunal;
use App\Services\Callback\CallbackNotifier;
use App\Services\Callback\CallbackPermanentException;
use App\Services\Callback\InvalidCallbackUrlException;
use App\Services\Ocr\AvisarAssinantesOcrService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Entrega dos avisos ocr_progresso (T13, RF-14 a RF-16, CT-02, Q-03).
 * Os jobs rodam um a um pelo handle(), na ordem em que a fila os entregaria,
 * com as interações de fila simuladas para conferir o fail().
 */

uses(DatabaseTransactions::class);

const NUMERO_AVISO_OCR_JOB = '12345674220268260100';
// Com a query e um caractere codificado: a URL chamada tem de ser byte a byte esta.
const URL_A_AVISO_OCR_JOB = 'https://sistema.example/mni/processamentos/9f1c/progresso?signature=a1b2%2Bc3&expires=1762473600';
const URL_B_AVISO_OCR_JOB = 'https://carona.example/aviso?assinatura=x';
const TOKEN_A_AVISO_OCR_JOB = 'tok-assinante-a-41f';
const TOKEN_B_AVISO_OCR_JOB = 'tok-assinante-b-7c2';

beforeEach(function () {
    Queue::fake();
    // Hosts liberados fora de produção (RF-17): o CallbackUrlValidator não
    // precisa resolver DNS, e o teste não depende de rede.
    config()->set('pje.callback.hosts_liberados', ['sistema.example', 'carona.example']);

    $this->tribunal = Tribunal::factory()->create(['ativo' => true]);
    $processo = Processo::factory()->create([
        'tribunal_id' => $this->tribunal->id,
        'numero_processo' => NUMERO_AVISO_OCR_JOB,
    ]);

    $this->processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);
    $this->a = ProcessoOcrAssinante::create([
        'processamento_id' => $this->processamento->id,
        'dono' => true,
        'callback_url' => URL_A_AVISO_OCR_JOB,
        'callback_token' => TOKEN_A_AVISO_OCR_JOB,
    ]);
    $this->b = ProcessoOcrAssinante::create([
        'processamento_id' => $this->processamento->id,
        'dono' => false,
        'callback_url' => URL_B_AVISO_OCR_JOB,
        'callback_token' => TOKEN_B_AVISO_OCR_JOB,
    ]);
});

function contagensAvisoOcrJob(int $processados, int $comErro = 0): array
{
    return ['total' => 5, 'processados' => $processados, 'com_erro' => $comErro, 'ignorados' => 2];
}

/**
 * Roda o job de um aviso como o worker rodaria uma tentativa.
 */
function rodarAvisoOcrJob(ProcessoOcrAviso|int $aviso): EnviarAvisoOcrJob
{
    $job = (new EnviarAvisoOcrJob($aviso instanceof ProcessoOcrAviso ? $aviso->id : $aviso))->withFakeQueueInteractions();
    $job->handle(app(CallbackNotifier::class));

    return $job;
}

/**
 * Roda uma tentativa de cada aviso ainda não enviado do processamento, na
 * ordem de criação. Uma falha transitória fica para a próxima rodada, como a
 * fila faria.
 */
function rodarAvisosPendentesOcrJob(ProcessoOcrProcessamento $processamento): void
{
    $avisos = ProcessoOcrAviso::query()
        ->whereNull('enviado_em')
        ->whereHas('assinante', fn ($assinante) => $assinante->where('processamento_id', $processamento->id))
        ->orderBy('id')
        ->get();

    foreach ($avisos as $aviso) {
        try {
            rodarAvisoOcrJob($aviso);
        } catch (RequestException|ConnectionException) {
        }
    }
}

/**
 * @return list<Request>
 */
function requisicoesAvisoOcrJob(string $url): array
{
    return Http::recorded(fn (Request $request) => $request->url() === $url)
        ->map(fn (array $par) => $par[0])
        ->values()
        ->all();
}

function avisoOcrJob(ProcessoOcrAssinante $assinante, bool $final = false, array $atributos = []): ProcessoOcrAviso
{
    return ProcessoOcrAviso::create(array_merge([
        'assinante_id' => $assinante->id,
        'final' => $final,
        'payload' => [
            'numero_processo' => NUMERO_AVISO_OCR_JOB,
            'tribunal_id' => 1,
            'total' => 5,
            'processados' => 1,
            'com_erro' => 0,
            'ignorados' => 2,
            'situacao' => $final ? 'concluido' : 'em_andamento',
        ],
    ], $atributos));
}

it('declara 5 tentativas, o backoff do webhook de monitoramento e timeout 30', function () {
    $job = new EnviarAvisoOcrJob(1);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([10, 60, 300, 900, 3600])
        ->and($job->timeout)->toBe(30)
        ->and($job)->not->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('com 2 assinantes, cada aviso vai à URL exata do callback_url, com a query e o X-API-Token do próprio assinante', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $servico = app(AvisarAssinantesOcrService::class);

    for ($processados = 1; $processados <= 5; $processados++) {
        $servico->avisarProgresso($this->processamento, contagensAvisoOcrJob($processados));
    }

    rodarAvisosPendentesOcrJob($this->processamento);

    $paraA = requisicoesAvisoOcrJob(URL_A_AVISO_OCR_JOB);
    $paraB = requisicoesAvisoOcrJob(URL_B_AVISO_OCR_JOB);

    expect($paraA)->toHaveCount(5)
        ->and($paraB)->toHaveCount(5);

    Http::assertSentCount(10);

    foreach ($paraA as $request) {
        expect($request->method())->toBe('POST')
            ->and($request->header('X-API-Token'))->toBe([TOKEN_A_AVISO_OCR_JOB]);
    }
    foreach ($paraB as $request) {
        expect($request->header('X-API-Token'))->toBe([TOKEN_B_AVISO_OCR_JOB]);
    }

    expect(array_map(fn (Request $request) => $request->data()['processados'], $paraA))->toBe([1, 2, 3, 4, 5]);
});

it('o corpo tem exatamente as 7 chaves, com X-Evento e X-Idempotency-Key do aviso', function () {
    Http::fake(['*' => Http::response('', 202)]);

    app(AvisarAssinantesOcrService::class)->avisarProgresso($this->processamento, contagensAvisoOcrJob(2, 1));
    $aviso = ProcessoOcrAviso::where('assinante_id', $this->a->id)->sole();

    rodarAvisoOcrJob($aviso);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === URL_A_AVISO_OCR_JOB
        && $request->hasHeader('X-Evento', 'processo.ocr.progresso')
        && $request->hasHeader('X-Idempotency-Key', $aviso->uuid)
        && $request->data() === [
            'numero_processo' => NUMERO_AVISO_OCR_JOB,
            'tribunal_id' => $this->tribunal->id,
            'total' => 5,
            'processados' => 2,
            'com_erro' => 1,
            'ignorados' => 2,
            'situacao' => 'em_andamento',
        ]);

    $aviso->refresh();

    expect($aviso->enviado_em)->not->toBeNull()
        ->and($aviso->status_http)->toBe(202)
        ->and($aviso->tentativas)->toBe(1)
        ->and($this->a->fresh()->aviso_final_entregue_em)->toBeNull();
});

it('o final falhou com recusa leva codigo por papel, e só ele leva codigo', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $servico = app(AvisarAssinantesOcrService::class);

    $servico->avisarProgresso($this->processamento, contagensAvisoOcrJob(1));
    $servico->avisarFinal($this->processamento, contagensAvisoOcrJob(1), 'falhou', 'credencial_pje_recusada');

    rodarAvisosPendentesOcrJob($this->processamento);

    $paraA = requisicoesAvisoOcrJob(URL_A_AVISO_OCR_JOB);
    $paraB = requisicoesAvisoOcrJob(URL_B_AVISO_OCR_JOB);

    expect($paraA)->toHaveCount(2)
        ->and($paraB)->toHaveCount(2)
        ->and(array_keys($paraA[0]->data()))->toBe(['numero_processo', 'tribunal_id', 'total', 'processados', 'com_erro', 'ignorados', 'situacao'])
        ->and($paraB[0]->data())->not->toHaveKey('codigo')
        ->and($paraA[1]->data()['situacao'])->toBe('falhou')
        ->and($paraA[1]->data()['codigo'])->toBe('credencial_pje_recusada')
        ->and($paraB[1]->data()['situacao'])->toBe('falhou')
        ->and($paraB[1]->data()['codigo'])->toBe('credencial_de_outro_pedido_recusada')
        ->and($paraA[1]->data())->toHaveCount(8);
});

it('2xx do final marca o aviso final entregue do assinante', function () {
    Http::fake(['*' => Http::response('', 200)]);

    app(AvisarAssinantesOcrService::class)->avisarFinal($this->processamento, contagensAvisoOcrJob(5), 'concluido');

    rodarAvisosPendentesOcrJob($this->processamento);

    expect($this->a->fresh()->aviso_final_entregue_em)->not->toBeNull()
        ->and($this->b->fresh()->aviso_final_entregue_em)->not->toBeNull()
        ->and(ProcessoOcrAviso::where('final', true)->whereIn('assinante_id', [$this->a->id, $this->b->id])->whereNull('enviado_em')->count())->toBe(0);
});

it('410 no 2º aviso encerra o assinante: 0 requisições depois, nem o final, e o outro recebe todos', function () {
    Http::fake([
        'sistema.example/*' => Http::sequence()
            ->push('', 200)
            ->push('gone', 410)
            ->whenEmpty(Http::response('', 200)),
        'carona.example/*' => Http::response('', 200),
    ]);
    $servico = app(AvisarAssinantesOcrService::class);

    // 1º e 2º avisos gerados e entregues antes do 3º, como na vida real.
    $servico->avisarProgresso($this->processamento, contagensAvisoOcrJob(1));
    rodarAvisosPendentesOcrJob($this->processamento);
    $servico->avisarProgresso($this->processamento, contagensAvisoOcrJob(2));
    rodarAvisosPendentesOcrJob($this->processamento);

    $segundoDeA = ProcessoOcrAviso::where('assinante_id', $this->a->id)->orderByDesc('id')->first();

    expect($this->a->fresh()->encerrado_em)->not->toBeNull()
        ->and($segundoDeA->status_http)->toBe(410)
        ->and($segundoDeA->enviado_em)->toBeNull();

    // Depois do encerramento, o serviço já não gera nada para A...
    $servico->avisarProgresso($this->processamento, contagensAvisoOcrJob(3));
    $servico->avisarProgresso($this->processamento, contagensAvisoOcrJob(4, 1));
    $servico->avisarFinal($this->processamento, contagensAvisoOcrJob(4, 1), 'concluido');
    rodarAvisosPendentesOcrJob($this->processamento);

    // ...e um aviso de A já gerado antes do 410 também não sai.
    $atrasadoDeA = avisoOcrJob($this->a);
    rodarAvisoOcrJob($atrasadoDeA);

    expect(requisicoesAvisoOcrJob(URL_A_AVISO_OCR_JOB))->toHaveCount(2)
        ->and(requisicoesAvisoOcrJob(URL_B_AVISO_OCR_JOB))->toHaveCount(5)
        ->and($this->b->fresh()->aviso_final_entregue_em)->not->toBeNull()
        ->and($this->b->fresh()->encerrado_em)->toBeNull()
        ->and($atrasadoDeA->fresh()->tentativas)->toBe(0);
});

it('4xx encerra o assinante e falha o job sem nova tentativa', function (int $status) {
    Http::fake(['*' => Http::response('rejeitado', $status)]);
    $aviso = avisoOcrJob($this->a);

    $job = rodarAvisoOcrJob($aviso);

    $job->assertFailedWith(CallbackPermanentException::class);
    $aviso->refresh();

    expect($aviso->enviado_em)->toBeNull()
        ->and($aviso->status_http)->toBe($status)
        ->and($aviso->tentativas)->toBe(1)
        ->and($this->a->fresh()->encerrado_em)->not->toBeNull()
        ->and($this->b->fresh()->encerrado_em)->toBeNull();
})->with([400, 401, 404, 410, 422]);

it('callback_url recusada pelo validador encerra o assinante e falha o job sem requisição', function (string $url) {
    Http::fake(['*' => Http::response('', 200)]);
    $this->a->update(['callback_url' => $url]);
    $aviso = avisoOcrJob($this->a);

    $job = rodarAvisoOcrJob($aviso);

    $job->assertFailedWith(InvalidCallbackUrlException::class);
    Http::assertNothingSent();

    expect($this->a->fresh()->encerrado_em)->not->toBeNull()
        ->and($aviso->fresh()->enviado_em)->toBeNull();
})->with([
    'localhost' => ['https://localhost/aviso'],
    'http fora da lista' => ['http://fora-da-lista.example/aviso'],
]);

it('5xx e falha de rede relançam para a fila tentar de novo, sem encerrar o assinante', function (Closure $resposta, string $excecao) {
    Http::fake(['*' => $resposta]);
    $aviso = avisoOcrJob($this->a);

    $job = (new EnviarAvisoOcrJob($aviso->id))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(CallbackNotifier::class)))->toThrow($excecao);

    $job->assertNotFailed();
    $aviso->refresh();

    expect($aviso->enviado_em)->toBeNull()
        ->and($aviso->tentativas)->toBe(1)
        ->and($this->a->fresh()->encerrado_em)->toBeNull();
})->with([
    '503' => [fn () => Http::response('indisponível', 503), RequestException::class],
    '500' => [fn () => Http::response('erro', 500), RequestException::class],
    'rede' => [fn () => Http::failedConnection(), ConnectionException::class],
]);

it('503 em todas: cada tentativa sai, e o aviso seguinte ainda é tentado', function () {
    Http::fake(['sistema.example/*' => Http::response('', 503)]);
    $aviso = avisoOcrJob($this->a);
    $seguinte = avisoOcrJob($this->a);

    for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
        expect(fn () => rodarAvisoOcrJob($aviso))->toThrow(RequestException::class);
    }

    expect(fn () => rodarAvisoOcrJob($seguinte))->toThrow(RequestException::class);

    expect($aviso->fresh()->tentativas)->toBe(5)
        ->and($seguinte->fresh()->tentativas)->toBe(1)
        ->and(requisicoesAvisoOcrJob(URL_A_AVISO_OCR_JOB))->toHaveCount(6)
        ->and($this->a->fresh()->encerrado_em)->toBeNull();
});

it('final entregue antes da nova tentativa: a tentativa pendente do aviso anterior sai com 0 requisições (Q-03)', function () {
    Http::fake([
        'sistema.example/*' => Http::sequence()
            ->push('', 200)
            ->push('', 200)
            ->push('indisponível', 503)
            ->whenEmpty(Http::response('', 200)),
    ]);
    $servico = app(AvisarAssinantesOcrService::class);

    $servico->avisarProgresso($this->processamento, contagensAvisoOcrJob(1));
    $servico->avisarProgresso($this->processamento, contagensAvisoOcrJob(2));
    $servico->avisarProgresso($this->processamento, contagensAvisoOcrJob(3));
    $servico->avisarFinal($this->processamento, contagensAvisoOcrJob(5), 'concluido');

    [$primeiro, $segundo, $terceiro, $final] = ProcessoOcrAviso::where('assinante_id', $this->a->id)->orderBy('id')->get()->all();

    rodarAvisoOcrJob($primeiro);
    rodarAvisoOcrJob($segundo);
    expect(fn () => rodarAvisoOcrJob($terceiro))->toThrow(RequestException::class);
    rodarAvisoOcrJob($final);

    expect($this->a->fresh()->aviso_final_entregue_em)->not->toBeNull()
        ->and(requisicoesAvisoOcrJob(URL_A_AVISO_OCR_JOB))->toHaveCount(4);

    // A 2ª tentativa do 3º aviso, depois do final entregue.
    $job = rodarAvisoOcrJob($terceiro);

    $job->assertNotFailed();

    expect(requisicoesAvisoOcrJob(URL_A_AVISO_OCR_JOB))->toHaveCount(4)
        ->and($terceiro->fresh()->enviado_em)->toBeNull()
        ->and($terceiro->fresh()->tentativas)->toBe(1);
});

it('aviso já enviado sai com 0 requisições e sem nova tentativa contada', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $aviso = avisoOcrJob($this->a, false, ['enviado_em' => now(), 'tentativas' => 1, 'status_http' => 200]);

    rodarAvisoOcrJob($aviso);

    Http::assertNothingSent();
    expect($aviso->fresh()->tentativas)->toBe(1);
});

it('assinante encerrado: nenhum aviso sai, nem o final', function (bool $final) {
    Http::fake(['*' => Http::response('', 200)]);
    $this->a->update(['encerrado_em' => now()]);
    $aviso = avisoOcrJob($this->a, $final);

    rodarAvisoOcrJob($aviso);

    Http::assertNothingSent();
    expect($aviso->fresh()->tentativas)->toBe(0);
})->with(['progresso' => false, 'final' => true]);

it('aviso inexistente termina sem requisição e sem exceção', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $job = rodarAvisoOcrJob(999999999);

    $job->assertNotFailed();
    Http::assertNothingSent();
});

it('o log da falha leva o processamento e o status, sem o token nem o corpo da resposta', function () {
    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$logs) {
        $logs[] = $evento->level.' '.$evento->message.' '.json_encode($evento->context);
    });

    Http::fake(['*' => Http::response('corpo-do-callback-'.TOKEN_A_AVISO_OCR_JOB, 503)]);
    $aviso = avisoOcrJob($this->a);
    $job = new EnviarAvisoOcrJob($aviso->id);

    try {
        $job->handle(app(CallbackNotifier::class));
    } catch (RequestException $e) {
        $job->failed($e);
    }

    $texto = implode("\n", $logs);

    expect($texto)->toContain("critical [ProcessamentoOcr:{$this->processamento->id}] aviso {$aviso->id} não entregue")
        ->and($texto)->toContain('503')
        ->and($texto)->not->toContain(TOKEN_A_AVISO_OCR_JOB)
        ->and($texto)->not->toContain('corpo-do-callback');
});
