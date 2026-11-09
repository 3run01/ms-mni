<?php

use App\Jobs\EnviarAvisoOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Services\Ocr\AvisarAssinantesOcrService;
use App\Services\Ocr\ContagemOcr;
use App\Services\Ocr\ContagemOcrService;
use App\Services\Ocr\ProcessamentoOcrService;
use App\Services\Ocr\ResultadoRetornoOcr;
use App\Services\Ocr\ResultadoRevelacaoOcr;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Máquina de estados do processamento do OCR (T14; RF-08, RF-10 itens 1 e 3,
 * RF-12, RF-14, RF-15, RF-18 item 2): transições do documento, progresso,
 * fim do processamento, aviso final único e a ordem de travas de D6.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    isolarAvisosOcrDoBancoDeDev();
    Queue::fake();
    $this->freezeSecond();
});

function servicoProcOcr(): ProcessamentoOcrService
{
    return app(ProcessamentoOcrService::class);
}

/**
 * Documentos do processo, um por item: a `ocr_situacao` (string ou null) ou
 * os atributos do documento. Sem `$processo`, cria processo e tribunal.
 *
 * @param  list<string|array<string, mixed>|null>  $documentos
 * @return array{0: Processo, 1: Collection<int, ProcessoDocumento>}
 */
function documentosProcOcr(array $documentos, ?Processo $processo = null): array
{
    return criarDocumentosLote(
        array_map(fn ($documento) => is_array($documento) ? $documento : ['ocr_situacao' => $documento], $documentos),
        $processo ?? [],
    );
}

/**
 * Processamento em andamento com os assinantes na ordem dada: o 1º é o dono
 * do par, os demais são caronas.
 *
 * @return array{0: ProcessoOcrProcessamento, 1: list<ProcessoOcrAssinante>}
 */
function abrirProcOcr(Processo $processo, array $tokens = ['tok-dono', 'tok-carona']): array
{
    $processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    $assinantes = [];
    foreach (array_values($tokens) as $i => $token) {
        $assinantes[] = ProcessoOcrAssinante::create([
            'processamento_id' => $processamento->id,
            'dono' => $i === 0,
            'callback_url' => "https://sistema.example/mni/p/{$processamento->id}/progresso?signature={$token}",
            'callback_token' => $token,
        ]);
    }

    return [$processamento, $assinantes];
}

/**
 * @return Collection<int, ProcessoOcrAviso>
 */
function avisosProcOcr(ProcessoOcrAssinante $assinante): Collection
{
    return ProcessoOcrAviso::where('assinante_id', $assinante->id)->orderBy('id')->get();
}

function totalAvisosProcOcr(): int
{
    return ProcessoOcrAviso::count();
}

function contagemProcOcr(Processo $processo): ContagemOcr
{
    return app(ContagemOcrService::class)->contar($processo->id);
}

/**
 * RF-08 em todo aviso: `processados + com_erro ≤ total` e `total + ignorados`
 * = documentos do processo no banco.
 */
function expectInvariantesAvisoProcOcr(array $payload, Processo $processo): void
{
    expect($payload['processados'] + $payload['com_erro'])->toBeLessThanOrEqual($payload['total'])
        ->and($payload['total'] + $payload['ignorados'])
        ->toBe(ProcessoDocumento::where('processo_id', $processo->id)->count());
}

// ---------- processamento aberto ----------

it('processamentoAberto devolve só o processamento em andamento do processo', function () {
    [$processo] = documentosProcOcr([null]);
    [$outro] = documentosProcOcr([null]);

    ProcessoOcrProcessamento::create(['processo_id' => $processo->id, 'situacao' => 'falhou', 'finalizado_em' => now()]);
    [$aberto] = abrirProcOcr($processo);
    abrirProcOcr($outro);

    expect(servicoProcOcr()->processamentoAberto($processo->id)?->id)->toBe($aberto->id)
        ->and(DB::transaction(fn () => servicoProcOcr()->processamentoAberto($processo->id, travar: true))?->id)->toBe($aberto->id);

    [$semProcessamento] = documentosProcOcr([null]);

    expect(servicoProcOcr()->processamentoAberto($semProcessamento->id))->toBeNull();
});

// ---------- envio (RF-11, R4-1) ----------

it('registrarEnvio passa a enviado com ocr_job_id e ocr_enviado_em, sem progresso e sem aviso', function (?string $situacao) {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => $situacao, 'ocr_erro' => 'erro do processamento anterior'],
    ]);
    [$processamento] = abrirProcOcr($processo);
    $documento = $documentos[0];

    $this->travel(3)->minutes();

    expect(servicoProcOcr()->registrarEnvio($documento, 'job-novo'))->toBeTrue();

    $documento->refresh();

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($documento->ocr_job_id)->toBe('job-novo')
        ->and($documento->ocr_enviado_em->equalTo(now()))->toBeTrue()
        ->and($documento->ocr_erro)->toBeNull()
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'nulo' => [null],
    'pendente' => [ProcessoDocumento::OCR_PENDENTE],
]);

it('registrarEnvio não muda documento processado', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO, 'ocr_job_id' => 'job-lido'],
    ]);
    abrirProcOcr($processo);

    expect(servicoProcOcr()->registrarEnvio($documentos[0], 'job-novo'))->toBeFalse();

    expect($documentos[0]->fresh()->only(['ocr_situacao', 'ocr_job_id']))->toBe([
        'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO,
        'ocr_job_id' => 'job-lido',
    ]);
});

it('registrarEnvio recusa job vazio ou maior que a coluna', function (string $jobId) {
    [, $documentos] = documentosProcOcr([null]);

    expect(fn () => servicoProcOcr()->registrarEnvio($documentos[0], $jobId))->toThrow(InvalidArgumentException::class);

    expect($documentos[0]->fresh()->ocr_situacao)->toBeNull();
})->with([
    'vazio' => [''],
    'só espaço' => ['   '],
    '65 caracteres' => [str_repeat('a', 65)],
]);

// ---------- retorno (RF-12) ----------

it('success sobre enviado vira processado, conta como progresso e avisa cada assinante', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    [$processamento, $assinantes] = abrirProcOcr($processo);

    $this->travel(4)->minutes();

    expect(servicoProcOcr()->aplicarRetorno($documentos[0], true))->toBe(ResultadoRetornoOcr::Aplicado);

    $documento = $documentos[0]->fresh();
    $processamento->refresh();

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($documento->ocr_concluido_em->equalTo(now()))->toBeTrue()
        ->and($documento->ocr_job_id)->toBe('job-1')
        ->and($processamento->ultimo_progresso_em->equalTo(now()))->toBeTrue()
        ->and($processamento->situacao)->toBe('em_andamento');

    foreach ($assinantes as $assinante) {
        $aviso = avisosProcOcr($assinante)->sole();

        expect($aviso->final)->toBeFalse()
            ->and($aviso->payload)->toMatchArray([
                'total' => 2,
                'processados' => 1,
                'com_erro' => 0,
                'ignorados' => 0,
                'situacao' => 'em_andamento',
            ])
            ->and($aviso->payload)->not->toHaveKey('codigo');

        expectInvariantesAvisoProcOcr($aviso->payload, $processo);
    }

    Queue::assertPushed(EnviarAvisoOcrJob::class, 2);
});

it('success sobre erro dá processados + 1 e com_erro − 1, com 1 aviso por assinante do processamento aberto', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ERRO, 'ocr_job_id' => 'job-a', 'ocr_erro' => 'job não encontrado'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-b'],
    ]);
    [, $assinantes] = abrirProcOcr($processo, ['tok-dono', 'tok-carona-1', 'tok-carona-2']);

    $antes = contagemProcOcr($processo);

    expect(servicoProcOcr()->aplicarRetorno($documentos[0], true))->toBe(ResultadoRetornoOcr::Aplicado);

    $depois = contagemProcOcr($processo);

    expect($depois->processados)->toBe($antes->processados + 1)
        ->and($depois->comErro)->toBe($antes->comErro - 1)
        ->and($documentos[0]->fresh()->ocr_erro)->toBeNull();

    foreach ($assinantes as $assinante) {
        expect(avisosProcOcr($assinante))->toHaveCount(1)
            ->and(avisosProcOcr($assinante)[0]->payload)->toMatchArray([
                'processados' => 1,
                'com_erro' => 0,
                'situacao' => 'em_andamento',
            ]);
    }
});

it('success sobre erro sem processamento aberto só atualiza as contagens, com 0 avisos', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ERRO, 'ocr_job_id' => 'job-a'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-b'],
    ]);
    [$processamento] = abrirProcOcr($processo);
    $processamento->update(['situacao' => 'falhou', 'finalizado_em' => now()]);

    $antes = contagemProcOcr($processo);

    expect(servicoProcOcr()->aplicarRetorno($documentos[0], true))->toBe(ResultadoRetornoOcr::Aplicado);

    $depois = contagemProcOcr($processo);

    expect($depois->processados)->toBe($antes->processados + 1)
        ->and($depois->comErro)->toBe($antes->comErro - 1)
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);

    Queue::assertNothingPushed();
});

it('error sobre enviado vira erro com o detalhe resumido, mantém o job e avisa', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    [, $assinantes] = abrirProcOcr($processo);

    expect(servicoProcOcr()->aplicarRetorno($documentos[0], false, "Falha no Gemini:\n  cota excedida"))
        ->toBe(ResultadoRetornoOcr::Aplicado);

    $documento = $documentos[0]->fresh();

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toBe('Falha no Gemini: cota excedida')
        ->and($documento->ocr_job_id)->toBe('job-1')
        ->and($documento->ocr_concluido_em)->toBeNull();

    foreach ($assinantes as $assinante) {
        expect(avisosProcOcr($assinante)->sole()->payload)->toMatchArray([
            'total' => 2,
            'processados' => 0,
            'com_erro' => 1,
            'situacao' => 'em_andamento',
        ]);
    }
});

it('error sem detalhe grava um resumo padrão', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
    ]);

    servicoProcOcr()->aplicarRetorno($documentos[0], false);

    expect($documentos[0]->fresh()->ocr_erro)->toBe('O workflow do OCR informou erro sem detalhe');
});

it('error sobre processado não muda nada e gera 0 avisos', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO, 'ocr_job_id' => 'job-1', 'ocr_concluido_em' => now()->subMinute()],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    [$processamento] = abrirProcOcr($processo);

    $antes = contagemProcOcr($processo);
    $documentoAntes = $documentos[0]->fresh()->getAttributes();

    expect(servicoProcOcr()->aplicarRetorno($documentos[0], false, 'erro tardio'))->toBe(ResultadoRetornoOcr::SemMudanca);

    expect(contagemProcOcr($processo))->toEqual($antes)
        ->and($documentos[0]->fresh()->getAttributes())->toBe($documentoAntes)
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);

    Queue::assertNothingPushed();
});

it('retorno repetido do mesmo job responde Repetido, sem mudança e com 0 avisos', function (string $situacao, bool $sucesso) {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => $situacao, 'ocr_job_id' => 'job-1', 'ocr_erro' => $situacao === 'erro' ? 'primeiro erro' : null],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    [$processamento] = abrirProcOcr($processo);

    $documentoAntes = $documentos[0]->fresh()->getAttributes();

    expect(servicoProcOcr()->aplicarRetorno($documentos[0], $sucesso, 'segundo erro'))->toBe(ResultadoRetornoOcr::Repetido);

    expect($documentos[0]->fresh()->getAttributes())->toBe($documentoAntes)
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);
})->with([
    'success sobre processado' => [ProcessoDocumento::OCR_PROCESSADO, true],
    'error sobre erro' => [ProcessoDocumento::OCR_ERRO, false],
]);

it('retorno sobre documento pendente não muda nada', function (?string $situacao, bool $sucesso) {
    [$processo, $documentos] = documentosProcOcr([$situacao, ProcessoDocumento::OCR_ENVIADO]);
    abrirProcOcr($processo);

    expect(servicoProcOcr()->aplicarRetorno($documentos[0], $sucesso))->toBe(ResultadoRetornoOcr::SemMudanca)
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe($situacao)
        ->and(totalAvisosProcOcr())->toBe(0);
})->with([
    'nulo, success' => [null, true],
    'pendente, success' => [ProcessoDocumento::OCR_PENDENTE, true],
    'pendente, error' => [ProcessoDocumento::OCR_PENDENTE, false],
]);

it('retorno de um job que já não é o atual do documento não muda nada (R2-2)', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-antigo'],
    ]);
    abrirProcOcr($processo);
    $lidoPeloWebhook = $documentos[0]->fresh();

    // Entre a leitura e a trava, outro worker devolveu e reenviou o documento.
    ProcessoDocumento::whereKey($lidoPeloWebhook->id)->update(['ocr_job_id' => 'job-novo']);

    expect(servicoProcOcr()->aplicarRetorno($lidoPeloWebhook, true))->toBe(ResultadoRetornoOcr::SemMudanca)
        ->and($lidoPeloWebhook->ocr_job_id)->toBe('job-novo')
        ->and($lidoPeloWebhook->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and(totalAvisosProcOcr())->toBe(0);
});

// ---------- fim por conclusão (RF-15) ----------

it('3 processados e 1 erro geram 1 final concluido {total 4, processados 3, com_erro 1} por assinante', function () {
    [$processo, $documentos] = documentosProcOcr(array_map(fn (int $i) => [
        'ocr_situacao' => ProcessoDocumento::OCR_ENVIADO,
        'ocr_job_id' => "job-{$i}",
    ], range(1, 4)));
    [$processamento, $assinantes] = abrirProcOcr($processo, ['tok-dono', 'tok-carona-1', 'tok-carona-2']);

    servicoProcOcr()->aplicarRetorno($documentos[0], true);
    servicoProcOcr()->aplicarRetorno($documentos[1], false, 'falha no OCR');
    servicoProcOcr()->aplicarRetorno($documentos[2], true);
    servicoProcOcr()->aplicarRetorno($documentos[3], true);

    // Depois do final, nada gera aviso novo.
    servicoProcOcr()->aplicarRetorno($documentos[3], true);
    servicoProcOcr()->registrarProgresso($processo->id);

    $processamento->refresh();

    expect($processamento->situacao)->toBe('concluido')
        ->and($processamento->codigo_falha)->toBeNull()
        ->and($processamento->finalizado_em->equalTo(now()))->toBeTrue();

    foreach ($assinantes as $assinante) {
        $avisos = avisosProcOcr($assinante);
        $finais = $avisos->where('final', true);

        expect($avisos)->toHaveCount(5)
            ->and($avisos->where('final', false))->toHaveCount(4)
            ->and($finais)->toHaveCount(1)
            ->and($avisos->last()->final)->toBeTrue()
            ->and($finais->sole()->payload)->toBe([
                'numero_processo' => $processo->numero_processo,
                'tribunal_id' => $processo->tribunal_id,
                'total' => 4,
                'processados' => 3,
                'com_erro' => 1,
                'ignorados' => 0,
                'situacao' => 'concluido',
            ]);

        $avisos->each(fn (ProcessoOcrAviso $aviso) => expectInvariantesAvisoProcOcr($aviso->payload, $processo));
    }

    Queue::assertPushed(EnviarAvisoOcrJob::class, 15);
});

it('ignorados entram nas contagens dos avisos sem impedir o final', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        ['mimetype' => 'video/mp4'],
        ['mimetype' => 'audio/mpeg'],
    ]);
    [$processamento, [$dono]] = abrirProcOcr($processo, ['tok-dono']);

    servicoProcOcr()->aplicarRetorno($documentos[0], true);

    expect($processamento->fresh()->situacao)->toBe('concluido')
        ->and(avisosProcOcr($dono)->last()->payload)->toMatchArray([
            'total' => 1,
            'processados' => 1,
            'com_erro' => 0,
            'ignorados' => 2,
            'situacao' => 'concluido',
        ]);
});

// ---------- erro definitivo (RF-09, RF-11) ----------

it('registrarErroDefinitivo passa pendente a erro com o motivo resumido, sem credencial e com até 1000 caracteres', function () {
    [$processo, $documentos] = documentosProcOcr([null, ProcessoDocumento::OCR_ENVIADO]);
    [, $assinantes] = abrirProcOcr($processo);

    $motivo = 'Falha no download: senha_pje=segredo-123 login_pje: 12345678900 '
        .'<ns1:idConsultante>98765432100</ns1:idConsultante><ns1:senhaConsultante>s3nh4-x</ns1:senhaConsultante> '
        .'Authorization: Bearer tok-xyz callback_token="cb-tok-1" X-API-Token: api-tok-2 '
        .'Erro ao realizar login via MNI. exception invoking: loginFailed '
        .str_repeat('detalhe ', 300);

    expect(servicoProcOcr()->registrarErroDefinitivo($documentos[0], $motivo))->toBe(ResultadoRetornoOcr::Aplicado);

    $erro = $documentos[0]->fresh()->ocr_erro;

    expect($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and(mb_strlen($erro))->toBeLessThanOrEqual(1000)
        ->and($erro)->toStartWith('Falha no download: senha_pje=***')
        ->and($erro)->toContain('loginFailed')
        ->and($erro)->toContain('<ns1:senhaConsultante>***</ns1:senhaConsultante>');

    foreach (['segredo-123', '12345678900', '98765432100', 's3nh4-x', 'tok-xyz', 'cb-tok-1', 'api-tok-2'] as $credencial) {
        expect($erro)->not->toContain($credencial);
    }

    foreach ($assinantes as $assinante) {
        expect(avisosProcOcr($assinante)->sole()->payload)->toMatchArray(['com_erro' => 1, 'situacao' => 'em_andamento']);
    }
});

it('registrarErroDefinitivo passa enviado a erro e mantém o job, que ainda pode trazer o success', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        null,
    ]);
    abrirProcOcr($processo);

    expect(servicoProcOcr()->registrarErroDefinitivo($documentos[0], 'job não encontrado'))->toBe(ResultadoRetornoOcr::Aplicado);

    expect($documentos[0]->fresh()->only(['ocr_situacao', 'ocr_job_id', 'ocr_erro']))->toBe([
        'ocr_situacao' => ProcessoDocumento::OCR_ERRO,
        'ocr_job_id' => 'job-1',
        'ocr_erro' => 'job não encontrado',
    ]);

    expect(servicoProcOcr()->aplicarRetorno($documentos[0]->fresh(), true))->toBe(ResultadoRetornoOcr::Aplicado)
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO);
});

it('registrarErroDefinitivo não rebaixa processado e repete erro, com 0 avisos', function (string $situacao, ResultadoRetornoOcr $esperado) {
    [$processo, $documentos] = documentosProcOcr([$situacao, null]);
    abrirProcOcr($processo);

    expect(servicoProcOcr()->registrarErroDefinitivo($documentos[0], 'falha'))->toBe($esperado)
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe($situacao)
        ->and(totalAvisosProcOcr())->toBe(0);
})->with([
    'processado' => [ProcessoDocumento::OCR_PROCESSADO, ResultadoRetornoOcr::SemMudanca],
    'erro' => [ProcessoDocumento::OCR_ERRO, ResultadoRetornoOcr::Repetido],
]);

it('registrarErroDefinitivo do último documento termina concluido com com_erro > 0', function () {
    [$processo, $documentos] = documentosProcOcr([ProcessoDocumento::OCR_PROCESSADO, null]);
    [$processamento, [$dono]] = abrirProcOcr($processo, ['tok-dono']);

    servicoProcOcr()->registrarErroDefinitivo($documentos[1], 'documento bloqueado pelo MNI');

    expect($processamento->fresh()->situacao)->toBe('concluido')
        ->and(avisosProcOcr($dono)->where('final', true)->sole()->payload)->toMatchArray([
            'total' => 2,
            'processados' => 1,
            'com_erro' => 1,
            'situacao' => 'concluido',
        ]);
});

// ---------- progresso (RF-13, RF-14) ----------

it('registrarProgresso sem processamento aberto gera 0 avisos', function () {
    [$processo] = documentosProcOcr([ProcessoDocumento::OCR_PROCESSADO]);
    [$processamento] = abrirProcOcr($processo);
    $processamento->update(['situacao' => 'concluido', 'finalizado_em' => now()]);

    servicoProcOcr()->registrarProgresso($processo->id);

    expect(totalAvisosProcOcr())->toBe(0)
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull();

    Queue::assertNothingPushed();
});

it('registrarProgresso com processamento aberto grava ultimo_progresso_em e avisa, sem finalizar com pendente ou aguardando', function (?string $restante) {
    [$processo] = documentosProcOcr([ProcessoDocumento::OCR_PROCESSADO, $restante]);
    [$processamento, $assinantes] = abrirProcOcr($processo);

    $this->travel(7)->minutes();

    servicoProcOcr()->registrarProgresso($processo->id);

    $processamento->refresh();

    expect($processamento->ultimo_progresso_em->equalTo(now()))->toBeTrue()
        ->and($processamento->situacao)->toBe('em_andamento')
        ->and($processamento->finalizado_em)->toBeNull();

    foreach ($assinantes as $assinante) {
        expect(avisosProcOcr($assinante)->sole()->final)->toBeFalse();
    }
})->with([
    'pendente' => [null],
    'aguardando retorno' => [ProcessoDocumento::OCR_ENVIADO],
]);

it('registrarProgresso termina concluido sem pendente nem aguardando', function () {
    [$processo] = documentosProcOcr([ProcessoDocumento::OCR_PROCESSADO, ProcessoDocumento::OCR_ERRO]);
    [$processamento, $assinantes] = abrirProcOcr($processo);

    servicoProcOcr()->registrarProgresso($processo->id);

    expect($processamento->fresh()->situacao)->toBe('concluido');

    foreach ($assinantes as $assinante) {
        $avisos = avisosProcOcr($assinante);

        expect($avisos)->toHaveCount(2)
            ->and($avisos[0]->payload['situacao'])->toBe('em_andamento')
            ->and($avisos[1]->final)->toBeTrue()
            ->and($avisos[1]->payload['situacao'])->toBe('concluido');
    }
});

// ---------- recusa do par (RF-10, RF-15) ----------

it('encerrarPorRecusa deixa os pendentes pendentes, não aumenta com_erro e dá o final falhou por papel', function () {
    [$processo, $documentos] = documentosProcOcr([
        ProcessoDocumento::OCR_PROCESSADO,
        ProcessoDocumento::OCR_PROCESSADO,
        ProcessoDocumento::OCR_PROCESSADO,
        null, null, null, null, null, null, null,
    ]);
    [$processamento, [$dono, $carona1, $carona2]] = abrirProcOcr($processo, ['tok-dono', 'tok-carona-1', 'tok-carona-2']);

    $antes = contagemProcOcr($processo);

    expect(servicoProcOcr()->encerrarPorRecusa($processamento->id))->toBeTrue();

    $processamento->refresh();
    $depois = contagemProcOcr($processo);

    expect($processamento->situacao)->toBe('falhou')
        ->and($processamento->codigo_falha)->toBe('credencial_pje_recusada')
        ->and($processamento->finalizado_em->equalTo(now()))->toBeTrue()
        ->and($depois->pendentes)->toBe(7)
        ->and($depois->comErro)->toBe($antes->comErro)
        ->and($depois->comErro)->toBe(0)
        ->and($documentos->slice(3)->map(fn (ProcessoDocumento $d) => $d->fresh()->ocr_situacao)->unique()->values()->all())->toBe([null]);

    $esperado = [
        'numero_processo' => $processo->numero_processo,
        'tribunal_id' => $processo->tribunal_id,
        'total' => 10,
        'processados' => 3,
        'com_erro' => 0,
        'ignorados' => 0,
        'situacao' => 'falhou',
    ];

    expect(avisosProcOcr($dono)->sole()->payload)->toBe([...$esperado, 'codigo' => 'credencial_pje_recusada'])
        ->and(avisosProcOcr($carona1)->sole()->payload)->toBe([...$esperado, 'codigo' => 'credencial_de_outro_pedido_recusada'])
        ->and(avisosProcOcr($carona2)->sole()->payload)->toBe([...$esperado, 'codigo' => 'credencial_de_outro_pedido_recusada']);

    foreach ([$dono, $carona1, $carona2] as $assinante) {
        expect(avisosProcOcr($assinante)->sole()->final)->toBeTrue();
    }
});

it('encerrarPorRecusa chamado 2 vezes gera 1 final por assinante', function () {
    [$processo] = documentosProcOcr([null, null]);
    [$processamento, $assinantes] = abrirProcOcr($processo);

    expect(servicoProcOcr()->encerrarPorRecusa($processamento->id))->toBeTrue()
        ->and(servicoProcOcr()->encerrarPorRecusa($processamento->id))->toBeFalse();

    foreach ($assinantes as $assinante) {
        expect(avisosProcOcr($assinante)->where('final', true))->toHaveCount(1);
    }

    Queue::assertPushed(EnviarAvisoOcrJob::class, 2);
});

it('encerrarPorRecusa não mexe em processamento já encerrado nem em processamento inexistente', function () {
    [$processo] = documentosProcOcr([null]);
    [$processamento] = abrirProcOcr($processo);
    $processamento->update(['situacao' => 'concluido', 'finalizado_em' => now()]);

    expect(servicoProcOcr()->encerrarPorRecusa($processamento->id))->toBeFalse()
        ->and(servicoProcOcr()->encerrarPorRecusa($processamento->id + 1000))->toBeFalse()
        ->and($processamento->fresh()->situacao)->toBe('concluido')
        ->and($processamento->fresh()->codigo_falha)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);
});

// ---------- prazo (RF-13 item 2) ----------

it('encerrarPorPrazo termina falhou sem codigo e é idempotente', function (?string $restante) {
    [$processo] = documentosProcOcr([ProcessoDocumento::OCR_PROCESSADO, $restante]);
    [$processamento, $assinantes] = abrirProcOcr($processo);

    expect(servicoProcOcr()->encerrarPorPrazo($processamento))->toBeTrue()
        ->and($processamento->situacao)->toBe('falhou')
        ->and($processamento->codigo_falha)->toBeNull()
        ->and(servicoProcOcr()->encerrarPorPrazo($processamento))->toBeFalse();

    foreach ($assinantes as $assinante) {
        $payload = avisosProcOcr($assinante)->sole()->payload;

        expect($payload['situacao'])->toBe('falhou')
            ->and($payload)->not->toHaveKey('codigo')
            ->and($payload)->toHaveCount(7);
    }
})->with([
    'pendente' => [null],
    'aguardando retorno' => [ProcessoDocumento::OCR_ENVIADO],
]);

it('encerrarPorPrazo sem pendente nem aguardando termina concluido (RF-15)', function () {
    [$processo] = documentosProcOcr([ProcessoDocumento::OCR_PROCESSADO, ProcessoDocumento::OCR_ERRO]);
    [$processamento, [$dono]] = abrirProcOcr($processo, ['tok-dono']);

    expect(servicoProcOcr()->encerrarPorPrazo($processamento))->toBeTrue()
        ->and($processamento->situacao)->toBe('concluido')
        ->and(avisosProcOcr($dono)->sole()->payload)->toMatchArray([
            'processados' => 1,
            'com_erro' => 1,
            'situacao' => 'concluido',
        ]);
});

// ---------- retorno depois do final e entre processamentos (RF-15, RF-18) ----------

it('retorno depois do final, sem outro processamento aberto, gera 0 avisos', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        null,
    ]);
    [$processamento] = abrirProcOcr($processo);
    servicoProcOcr()->encerrarPorRecusa($processamento->id);
    $avisosDoFinal = totalAvisosProcOcr();

    expect(servicoProcOcr()->aplicarRetorno($documentos[0], true))->toBe(ResultadoRetornoOcr::Aplicado)
        ->and(contagemProcOcr($processo)->processados)->toBe(1)
        ->and(totalAvisosProcOcr())->toBe($avisosDoFinal)
        ->and($processamento->fresh()->situacao)->toBe('falhou');
});

it('retorno de um envio do processamento 1 com o 2 aberto avisa só os assinantes do 2', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-3'],
        ...array_fill(0, 7, null),
    ]);
    [$processamento1, $assinantes1] = abrirProcOcr($processo, ['tok-p1-dono', 'tok-p1-carona']);
    servicoProcOcr()->encerrarPorRecusa($processamento1->id);

    [$processamento2, $assinantes2] = abrirProcOcr($processo, ['tok-p2-dono', 'tok-p2-carona']);

    $this->travel(2)->minutes();

    expect(servicoProcOcr()->aplicarRetorno($documentos[1], true))->toBe(ResultadoRetornoOcr::Aplicado);

    foreach ($assinantes2 as $assinante) {
        expect(avisosProcOcr($assinante)->sole()->payload)->toMatchArray([
            'total' => 10,
            'processados' => 1,
            'situacao' => 'em_andamento',
        ]);
    }

    foreach ($assinantes1 as $assinante) {
        expect(avisosProcOcr($assinante))->toHaveCount(1)
            ->and(avisosProcOcr($assinante)->sole()->final)->toBeTrue();
    }

    expect($processamento2->fresh()->ultimo_progresso_em->equalTo(now()))->toBeTrue()
        ->and($processamento1->fresh()->ultimo_progresso_em)->toBeNull();
});

// ---------- devolução à fila (D1, R2-2) ----------

it('devolverParaFila move ocr_job_id para ocr_job_id_cancelar e o job antigo deixa de ser conhecido', function (string $situacao) {
    [$processo, $documentos] = documentosProcOcr([[
        'ocr_situacao' => $situacao,
        'ocr_job_id' => 'job-antigo',
        'ocr_enviado_em' => now()->subMinutes(21),
        'ocr_erro' => $situacao === 'erro' ? 'falha anterior' : null,
    ]]);
    [$processamento] = abrirProcOcr($processo);

    expect(servicoProcOcr()->devolverParaFila($documentos[0]))->toBeTrue();

    expect($documentos[0]->fresh()->only(['ocr_situacao', 'ocr_job_id', 'ocr_job_id_cancelar', 'ocr_enviado_em', 'ocr_erro']))->toBe([
        'ocr_situacao' => ProcessoDocumento::OCR_PENDENTE,
        'ocr_job_id' => null,
        'ocr_job_id_cancelar' => 'job-antigo',
        'ocr_enviado_em' => null,
        'ocr_erro' => null,
    ])
        ->and(ProcessoDocumento::where('ocr_job_id', 'job-antigo')->exists())->toBeFalse()
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);
})->with([
    'erro (reabertura)' => [ProcessoDocumento::OCR_ERRO],
    'enviado (consulta decidiu reenviar)' => [ProcessoDocumento::OCR_ENVIADO],
]);

it('devolverParaFila de erro sem job mantém o job a cancelar que o DELETE não cancelou', function () {
    [, $documentos] = documentosProcOcr([[
        'ocr_situacao' => ProcessoDocumento::OCR_ERRO,
        'ocr_job_id' => null,
        'ocr_job_id_cancelar' => 'job-nao-cancelado',
    ]]);

    expect(servicoProcOcr()->devolverParaFila($documentos[0]))->toBeTrue()
        ->and($documentos[0]->fresh()->ocr_job_id_cancelar)->toBe('job-nao-cancelado');
});

it('devolverParaFila não devolve processado nem pendente', function (?string $situacao) {
    [, $documentos] = documentosProcOcr([['ocr_situacao' => $situacao, 'ocr_job_id' => $situacao ? 'job-1' : null]]);

    expect(servicoProcOcr()->devolverParaFila($documentos[0]))->toBeFalse()
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe($situacao)
        ->and($documentos[0]->fresh()->ocr_job_id_cancelar)->toBeNull();
})->with([
    'processado' => [ProcessoDocumento::OCR_PROCESSADO],
    'pendente' => [ProcessoDocumento::OCR_PENDENTE],
    'nulo' => [null],
]);

it('o job devolvido à fila não recebe mais retorno, e o novo envio volta a ser conhecido', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-antigo'],
        null,
    ]);
    abrirProcOcr($processo);
    $lidoPeloJobAntigo = $documentos[0]->fresh();

    servicoProcOcr()->devolverParaFila($documentos[0]);

    expect(servicoProcOcr()->aplicarRetorno($lidoPeloJobAntigo, true))->toBe(ResultadoRetornoOcr::SemMudanca)
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PENDENTE)
        ->and(totalAvisosProcOcr())->toBe(0);

    $atual = $documentos[0]->fresh();
    servicoProcOcr()->registrarEnvio($atual, 'job-novo');

    expect(servicoProcOcr()->aplicarRetorno($atual, true))->toBe(ResultadoRetornoOcr::Aplicado)
        ->and($atual->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($atual->fresh()->ocr_job_id_cancelar)->toBe('job-antigo');
});

// ---------- transação e ordem de travas (D6) ----------

it('trava primeiro o processamento aberto e depois o documento', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        null,
    ]);
    abrirProcOcr($processo);

    DB::enableQueryLog();
    servicoProcOcr()->aplicarRetorno($documentos[0], true);
    $travas = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains(strtolower($sql), 'for update'))
        ->values();
    DB::disableQueryLog();

    expect($travas)->toHaveCount(2)
        ->and($travas[0])->toContain('processo_ocr_processamentos')
        ->and($travas[1])->toContain('processo_documentos');
});

it('a mudança do documento é desfeita junto com os avisos quando a transação falha', function () {
    [$processo, $documentos] = documentosProcOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        null,
    ]);
    [$processamento] = abrirProcOcr($processo);

    $this->mock(AvisarAssinantesOcrService::class, function ($mock) {
        $mock->shouldReceive('avisarProgresso')->once()->andThrow(new RuntimeException('falha ao gerar o aviso'));
    });

    expect(fn () => servicoProcOcr()->aplicarRetorno($documentos[0], true))->toThrow(RuntimeException::class);

    expect($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);
});

// ---------- revelação do mimetype (T10; RF-10, RF-11, RF-12, RNF-05) ----------

it('revelado legível → Enviar, mimetype gravado, sem progresso e com 0 avisos', function (string $revelado) {
    [$processo, $documentos] = documentosProcOcr([
        ProcessoDocumento::OCR_PROCESSADO,
        ['mimetype' => ''],
    ]);
    [$processamento] = abrirProcOcr($processo);
    $vazio = $documentos[1];

    $this->travel(4)->minutes();

    expect(servicoProcOcr()->registrarMimetypeRevelado($vazio, $revelado))->toBe(ResultadoRevelacaoOcr::Enviar);

    $depois = $vazio->fresh();

    expect($depois->mimetype)->toBe($revelado)
        ->and($vazio->mimetype)->toBe($revelado)
        ->and($depois->ocr_situacao)->toBeNull()
        ->and($depois->ocr_erro)->toBeNull()
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and($processamento->fresh()->situacao)->toBe('em_andamento')
        ->and(totalAvisosProcOcr())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'PDF' => ['application/pdf'],
    'HTML' => ['text/html'],
    'imagem' => ['image/png'],
]);

it('só 1 vazio pendente revelado vídeo → Ignorado, 1 em_andamento com total T−1 e ignorados I+1 e depois 1 final concluido', function () {
    [$processo, $documentos] = documentosProcOcr([
        ProcessoDocumento::OCR_PROCESSADO,
        ['mimetype' => 'text/html', 'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO],
        ['mimetype' => 'audio/mpeg'],
        ['mimetype' => ''],
    ]);
    [$processamento, $assinantes] = abrirProcOcr($processo);
    $vazio = $documentos[3];
    $antes = contagemProcOcr($processo);

    expect([$antes->total, $antes->ignorados])->toBe([3, 1]);

    $this->travel(6)->minutes();

    expect(servicoProcOcr()->registrarMimetypeRevelado($vazio, 'video/mp4'))->toBe(ResultadoRevelacaoOcr::Ignorado);

    $processamento->refresh();

    expect($vazio->fresh()->mimetype)->toBe('video/mp4')
        ->and($processamento->situacao)->toBe('concluido')
        ->and($processamento->ultimo_progresso_em->equalTo(now()))->toBeTrue()
        ->and($processamento->finalizado_em->equalTo(now()))->toBeTrue();

    foreach ($assinantes as $assinante) {
        $avisos = avisosProcOcr($assinante);

        expect($avisos)->toHaveCount(2)
            ->and($avisos[0]->final)->toBeFalse()
            ->and($avisos[0]->payload)->toMatchArray([
                'total' => $antes->total - 1,
                'processados' => 2,
                'com_erro' => 0,
                'ignorados' => $antes->ignorados + 1,
                'situacao' => 'em_andamento',
            ])
            ->and($avisos[1]->final)->toBeTrue()
            ->and($avisos[1]->payload)->toMatchArray([
                'total' => $antes->total - 1,
                'ignorados' => $antes->ignorados + 1,
                'situacao' => 'concluido',
            ]);

        $avisos->each(fn (ProcessoOcrAviso $aviso) => expectInvariantesAvisoProcOcr($aviso->payload, $processo));
    }
});

it('revelado fora da lista com outro documento pendente → 1 aviso em_andamento, e o processamento continua', function () {
    [$processo, $documentos] = documentosProcOcr([
        null,
        ['mimetype' => ''],
    ]);
    [$processamento, $assinantes] = abrirProcOcr($processo);

    expect(servicoProcOcr()->registrarMimetypeRevelado($documentos[1], 'video/mp4'))->toBe(ResultadoRevelacaoOcr::Ignorado);

    expect($processamento->fresh()->situacao)->toBe('em_andamento')
        ->and($processamento->fresh()->finalizado_em)->toBeNull();

    foreach ($assinantes as $assinante) {
        $aviso = avisosProcOcr($assinante)->sole();

        expect($aviso->final)->toBeFalse()
            ->and($aviso->payload)->toMatchArray(['total' => 1, 'ignorados' => 1, 'situacao' => 'em_andamento']);

        expectInvariantesAvisoProcOcr($aviso->payload, $processo);
    }
});

it('revelado vazio → SemMimetype, erro com o texto fixo e com_erro + 1 no aviso', function () {
    [$processo, $documentos] = documentosProcOcr([
        null,
        ['mimetype' => '', 'ocr_situacao' => ProcessoDocumento::OCR_PENDENTE],
    ]);
    [$processamento, $assinantes] = abrirProcOcr($processo);
    $antes = contagemProcOcr($processo);

    expect(servicoProcOcr()->registrarMimetypeRevelado($documentos[1], '  '))->toBe(ResultadoRevelacaoOcr::SemMimetype);

    $depois = $documentos[1]->fresh();

    expect($depois->mimetype)->toBe('')
        ->and($depois->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($depois->ocr_erro)->toBe('mimetype não informado pelo tribunal')
        ->and(ProcessamentoOcrService::ERRO_MIMETYPE_NAO_INFORMADO)->toBe('mimetype não informado pelo tribunal')
        ->and($processamento->fresh()->situacao)->toBe('em_andamento');

    foreach ($assinantes as $assinante) {
        $aviso = avisosProcOcr($assinante)->sole();

        expect($aviso->payload)->toMatchArray([
            'total' => $antes->total,
            'com_erro' => $antes->comErro + 1,
            'ignorados' => $antes->ignorados,
            'situacao' => 'em_andamento',
        ]);

        expectInvariantesAvisoProcOcr($aviso->payload, $processo);
    }
});

it('o último documento revelado vazio termina concluido com com_erro > 0', function () {
    [$processo, $documentos] = documentosProcOcr([
        ProcessoDocumento::OCR_PROCESSADO,
        ['mimetype' => ''],
    ]);
    [$processamento, [$dono]] = abrirProcOcr($processo, ['tok-dono']);

    servicoProcOcr()->registrarMimetypeRevelado($documentos[1], '');

    expect($processamento->fresh()->situacao)->toBe('concluido')
        ->and(avisosProcOcr($dono))->toHaveCount(2)
        ->and(avisosProcOcr($dono)->last()->payload)->toMatchArray([
            'total' => 2,
            'processados' => 1,
            'com_erro' => 1,
            'situacao' => 'concluido',
        ]);
});

it('a 2ª chamada para o mesmo documento já revelado → JaRevelado, sem mudança e com 0 avisos novos', function (string $revelado, ResultadoRevelacaoOcr $primeiro) {
    [$processo, $documentos] = documentosProcOcr([
        null,
        ['mimetype' => ''],
    ]);
    [$processamento] = abrirProcOcr($processo);

    expect(servicoProcOcr()->registrarMimetypeRevelado($documentos[1], $revelado))->toBe($primeiro);

    $avisos = totalAvisosProcOcr();
    $documentoAntes = $documentos[1]->fresh()->getAttributes();
    $progressoAntes = $processamento->fresh()->ultimo_progresso_em;

    $this->travel(2)->minutes();

    expect(servicoProcOcr()->registrarMimetypeRevelado($documentos[1]->fresh(), $revelado))->toBe(ResultadoRevelacaoOcr::JaRevelado)
        ->and(servicoProcOcr()->registrarMimetypeRevelado($documentos[1]->fresh(), ''))->toBe(ResultadoRevelacaoOcr::JaRevelado);

    expect(totalAvisosProcOcr())->toBe($avisos)
        ->and($documentos[1]->fresh()->getAttributes())->toBe($documentoAntes)
        ->and($processamento->fresh()->ultimo_progresso_em?->toIso8601String())->toBe($progressoAntes?->toIso8601String());
})->with([
    'vídeo' => ['video/mp4', ResultadoRevelacaoOcr::Ignorado],
    'PDF' => ['application/pdf', ResultadoRevelacaoOcr::Enviar],
]);

it('mimetype regravado pela lista antes da trava → JaRevelado, sem trocar o valor e com 0 avisos', function () {
    [$processo, $documentos] = documentosProcOcr([
        null,
        ['mimetype' => ''],
    ]);
    abrirProcOcr($processo);
    $lido = $documentos[1];

    // a lista regravou o documento depois da leitura do job
    ProcessoDocumento::whereKey($lido->id)->update(['mimetype' => 'audio/mpeg']);

    expect(servicoProcOcr()->registrarMimetypeRevelado($lido, 'application/pdf'))->toBe(ResultadoRevelacaoOcr::JaRevelado)
        ->and($lido->fresh()->mimetype)->toBe('audio/mpeg')
        ->and($lido->mimetype)->toBe('audio/mpeg')
        ->and(totalAvisosProcOcr())->toBe(0);
});

it('vazio já em erro revelado vazio de novo → SemMimetype, sem 2º aviso', function () {
    [$processo, $documentos] = documentosProcOcr([
        null,
        ['mimetype' => ''],
    ]);
    abrirProcOcr($processo);

    servicoProcOcr()->registrarMimetypeRevelado($documentos[1], '');
    $avisos = totalAvisosProcOcr();

    expect(servicoProcOcr()->registrarMimetypeRevelado($documentos[1]->fresh(), ''))->toBe(ResultadoRevelacaoOcr::SemMimetype)
        ->and(totalAvisosProcOcr())->toBe($avisos)
        ->and($avisos)->toBeGreaterThan(0);
});

it('vazio processado revelado vazio nunca rebaixa e não avisa', function () {
    [$processo, $documentos] = documentosProcOcr([
        null,
        ['mimetype' => '', 'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO],
    ]);
    [$processamento] = abrirProcOcr($processo);

    expect(servicoProcOcr()->registrarMimetypeRevelado($documentos[1], ''))->toBe(ResultadoRevelacaoOcr::SemMimetype)
        ->and($documentos[1]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($documentos[1]->fresh()->ocr_erro)->toBeNull()
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);
});

it('sem processamento aberto, o mimetype é gravado com 0 avisos', function (string $revelado, ResultadoRevelacaoOcr $esperado) {
    [$processo, $documentos] = documentosProcOcr([
        null,
        ['mimetype' => ''],
    ]);
    [$processamento] = abrirProcOcr($processo);
    $processamento->update(['situacao' => 'falhou', 'finalizado_em' => now()]);

    expect(servicoProcOcr()->registrarMimetypeRevelado($documentos[1], $revelado))->toBe($esperado)
        ->and($documentos[1]->fresh()->mimetype)->toBe($revelado)
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and($processamento->fresh()->situacao)->toBe('falhou')
        ->and(totalAvisosProcOcr())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'vídeo' => ['video/mp4', ResultadoRevelacaoOcr::Ignorado],
    'PDF' => ['application/pdf', ResultadoRevelacaoOcr::Enviar],
    'vazio' => ['', ResultadoRevelacaoOcr::SemMimetype],
]);

it('a revelação trava primeiro o processamento aberto e depois o documento', function () {
    [$processo, $documentos] = documentosProcOcr([null, ['mimetype' => '']]);
    abrirProcOcr($processo);

    DB::enableQueryLog();
    servicoProcOcr()->registrarMimetypeRevelado($documentos[1], 'video/mp4');
    $travas = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains(strtolower($sql), 'for update'))
        ->values();
    DB::disableQueryLog();

    expect($travas)->toHaveCount(2)
        ->and($travas[0])->toContain('processo_ocr_processamentos')
        ->and($travas[1])->toContain('processo_documentos');
});

it('a revelação é desfeita junto com os avisos quando a transação falha', function () {
    [$processo, $documentos] = documentosProcOcr([null, ['mimetype' => '']]);
    [$processamento] = abrirProcOcr($processo);

    $this->mock(AvisarAssinantesOcrService::class, function ($mock) {
        $mock->shouldReceive('avisarProgresso')->once()->andThrow(new RuntimeException('falha ao gerar o aviso'));
    });

    expect(fn () => servicoProcOcr()->registrarMimetypeRevelado($documentos[1], 'video/mp4'))->toThrow(RuntimeException::class);

    expect($documentos[1]->fresh()->mimetype)->toBe('')
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);
});

it('o log da revelação leva o mimetype, o resultado e o processamento_id', function () {
    [$processo, $documentos] = documentosProcOcr([null, ['mimetype' => '']]);
    [$processamento] = abrirProcOcr($processo);

    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$logs) {
        if (str_contains($evento->message, 'mimetype revelado')) {
            $logs[] = [$evento->message, $evento->context];
        }
    });

    servicoProcOcr()->registrarMimetypeRevelado($documentos[1], 'video/mp4');

    expect($logs)->toHaveCount(1)
        ->and($logs[0][0])->toBe("[ProcessoDocumento:{$documentos[1]->id}] mimetype revelado")
        ->and($logs[0][1])->toBe([
            'mimetype' => 'video/mp4',
            'resultado' => 'ignorado',
            'processamento_id' => $processamento->id,
        ]);
});

// ---------- saída Q-05 (T10; RF-13, DP-07) ----------

it('concluirSeNadaResta sem pendente nem aguardando → concluido, 1 final por assinante e 0 em_andamento', function () {
    [$processo] = documentosProcOcr([
        ProcessoDocumento::OCR_PROCESSADO,
        ProcessoDocumento::OCR_ERRO,
        ['mimetype' => 'audio/mpeg'],
    ]);
    [$processamento, $assinantes] = abrirProcOcr($processo);

    $this->travel(3)->minutes();

    expect(servicoProcOcr()->concluirSeNadaResta($processamento->id))->toBeTrue();

    $processamento->refresh();

    expect($processamento->situacao)->toBe('concluido')
        ->and($processamento->codigo_falha)->toBeNull()
        ->and($processamento->finalizado_em->equalTo(now()))->toBeTrue()
        ->and($processamento->ultimo_progresso_em)->toBeNull();

    foreach ($assinantes as $assinante) {
        $avisos = avisosProcOcr($assinante);

        expect($avisos)->toHaveCount(1)
            ->and($avisos->where('final', false))->toHaveCount(0)
            ->and($avisos->sole()->final)->toBeTrue()
            ->and($avisos->sole()->payload)->toMatchArray([
                'total' => 2,
                'processados' => 1,
                'com_erro' => 1,
                'ignorados' => 1,
                'situacao' => 'concluido',
            ]);

        expectInvariantesAvisoProcOcr($avisos->sole()->payload, $processo);
    }

    // idempotente: a 2ª chamada não encerra nem avisa de novo
    expect(servicoProcOcr()->concluirSeNadaResta($processamento->id))->toBeFalse()
        ->and(totalAvisosProcOcr())->toBe(count($assinantes));
});

it('concluirSeNadaResta com pendente ou aguardando → false, em_andamento e 0 avisos', function (string|array|null $restante) {
    [$processo] = documentosProcOcr([ProcessoDocumento::OCR_PROCESSADO, $restante]);
    [$processamento] = abrirProcOcr($processo);

    expect(servicoProcOcr()->concluirSeNadaResta($processamento->id))->toBeFalse()
        ->and($processamento->fresh()->situacao)->toBe('em_andamento')
        ->and($processamento->fresh()->finalizado_em)->toBeNull()
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(totalAvisosProcOcr())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'pendente' => [null],
    'vazio pendente' => [['mimetype' => '']],
    'aguardando retorno' => [ProcessoDocumento::OCR_ENVIADO],
]);

it('concluirSeNadaResta com processamento encerrado ou inexistente → false e 0 avisos', function () {
    [$processo] = documentosProcOcr([ProcessoDocumento::OCR_PROCESSADO]);
    [$processamento] = abrirProcOcr($processo);
    $processamento->update(['situacao' => 'falhou', 'finalizado_em' => now()]);

    expect(servicoProcOcr()->concluirSeNadaResta($processamento->id))->toBeFalse()
        ->and($processamento->fresh()->situacao)->toBe('falhou')
        ->and(servicoProcOcr()->concluirSeNadaResta(PHP_INT_MAX))->toBeFalse()
        ->and(totalAvisosProcOcr())->toBe(0);
});
