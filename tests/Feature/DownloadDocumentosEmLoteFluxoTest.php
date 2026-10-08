<?php

use App\Exceptions\MNIException;
use App\Jobs\BaixarDocumentoMNIJob;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\MNI\Intercomunicacao\ConsultarDocumentoService;
use App\Services\MNI\Intercomunicacao\ConsultarProcessosService;
use App\Services\MNI\NormalizarDocumentoMniService;
use App\Services\Processo\ParametrosDownloadEmLote;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

/*
 * Fluxo ponta a ponta do download em lote: os lotes saem dos pontos de
 * despacho reais (execute() e mni:baixar-documento-pendente), ficam presos
 * no Queue::fake() e depois o handle()/failed() de cada job capturado roda
 * contra as operações SOAP mockadas e o S3 simulado.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('s3');
    Log::spy();

    // T = 20 e P pela fórmula (2), independentemente do .env local.
    config()->set('pje.download_documentos.tamanho_lote', 20);
    config()->set('pje.download_documentos.max_paginas', null);

    $this->consultarProcessos = $this->mock(ConsultarProcessosService::class);
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);

    // Nenhum cenário daqui sai do caminho feliz: o fallback unitário não roda.
    $this->mock(ConsultarDocumentoService::class)->shouldNotReceive('buscar');
});

/**
 * `documento` do payload da consulta do processo que o execute() persiste:
 * só metadados, sem conteúdo, com `id_documento` a partir de `$primeiroId`.
 */
function documentosDaConsultaFluxo(int $quantidade, int $primeiroId, array $atributos = []): array
{
    return array_map(function (int $id) use ($atributos) {
        $documento = documentoMniFake($id, $atributos);
        unset($documento->conteudo);

        return $documento;
    }, range($primeiroId, $primeiroId + $quantidade - 1));
}

function salvarDocumentosFluxo(Processo $processo, array $documentosMni, ?string $login = null, ?string $senha = null): void
{
    app(SalvarDocumentoProcessoService::class)->execute($processo, $documentosMni, $login, $senha);
}

/**
 * @return Collection<int, ProcessoDocumento>
 */
function documentosDoProcessoFluxo(Processo $processo): Collection
{
    return ProcessoDocumento::where('processo_id', $processo->id)->orderBy('id')->get();
}

/**
 * Lotes do processo, na ordem do despacho. Filtrar pelo processo isola o
 * teste dos documentos `erro` do banco de desenvolvimento, que o comando
 * também enfileira.
 *
 * @return Collection<int, BaixarDocumentosMNIEmLoteJob>
 */
function lotesFluxo(Processo $processo): Collection
{
    return Queue::pushed(
        BaixarDocumentosMNIEmLoteJob::class,
        fn ($job) => $job->processoId === $processo->id
    )->values();
}

/**
 * Responde consultarPagina com os documentos crus de `$responder($ids, $pagina)`,
 * no formato da operação real, e guarda cada chamada para as contagens.
 */
function simularConsultarProcessosFluxo(MockInterface $consultarProcessos, Closure $responder): ArrayObject
{
    $chamadas = new ArrayObject();

    $consultarProcessos->shouldReceive('consultarPagina')->andReturnUsing(
        function (Tribunal $tribunal, string $numeroProcesso, array $ids, int $pagina, int $tamanhoPagina, ?string $login = null, ?string $senha = null) use ($chamadas, $responder) {
            $chamadas[] = compact('numeroProcesso', 'ids', 'pagina', 'tamanhoPagina', 'login', 'senha');

            return NormalizarDocumentoMniService::indexarPorId($responder($ids, $pagina));
        }
    );

    return $chamadas;
}

/**
 * MNI que devolve, na página 1, todos os ids pedidos, com conteúdo.
 */
function todosNaPagina1Fluxo(): Closure
{
    return fn (array $ids, int $pagina) => $pagina === 1
        ? array_map(fn (string $id) => documentoMniFake($id), $ids)
        : [];
}

/**
 * @param  Collection<int, ProcessoDocumento>  $documentos
 * @return array<int, string>
 */
function idsDocumentoFluxo(Collection $documentos): array
{
    return $documentos->map(fn (ProcessoDocumento $documento) => (string) $documento->id_documento)->values()->all();
}

/**
 * Casa o `error` emitido pelo failed() do job, de qualquer documento ou só do indicado.
 */
function logDaFalhaDefinitivaFluxo(?ProcessoDocumento $documento = null): Closure
{
    return fn ($mensagem, $contexto = []) => str_starts_with($contexto['motivo'] ?? '', 'Falha definitiva')
        && ($documento === null || ($contexto['id_documento'] ?? null) === $documento->id_documento);
}

function expectBaixadoNoFluxo(ProcessoDocumento $documento, int $tentativas): void
{
    $documento->refresh();

    expect($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($documento->downloaded_at)->not->toBeNull()
        ->and($documento->tentativas_download)->toBe($tentativas);

    Storage::disk('s3')->assertExists($documento->path);
}

function expectErroNoFluxo(ProcessoDocumento $documento, int $tentativas): void
{
    $documento->refresh();

    expect($documento->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($documento->tentativas_download)->toBe($tentativas);
}

// RNF-01: carga no MNI

it('45 documentos com os ids na pagina 1 geram 3 lotes e exatamente 3 chamadas SOAP, todas consultarProcessos', function () {
    [$processo] = criarDocumentosLote([]);
    salvarDocumentosFluxo($processo, documentosDaConsultaFluxo(45, 8800001), '{LOGIN}', '{SENHA}');
    $documentos = documentosDoProcessoFluxo($processo);
    $lotes = lotesFluxo($processo);
    $chamadas = simularConsultarProcessosFluxo($this->consultarProcessos, todosNaPagina1Fluxo());

    expect($documentos)->toHaveCount(45)
        ->and($lotes->map(fn ($lote) => count($lote->processoDocumentoIds))->all())->toBe([20, 20, 5])
        ->and($lotes->flatMap->processoDocumentoIds->sort()->values()->all())->toBe($documentos->pluck('id')->sort()->values()->all())
        ->and($lotes->every(fn ($lote) => $lote->queue === 'mni-download'))->toBeTrue();
    Queue::assertNotPushed(BaixarDocumentoMNIJob::class);

    $chamadasPorLote = $lotes->map(function (BaixarDocumentosMNIEmLoteJob $lote) use ($chamadas) {
        $antes = count($chamadas);
        $lote->handle();

        return count($chamadas) - $antes;
    });

    // Hoje seriam 45 chamadas, uma por documento.
    expect($chamadas)->toHaveCount(3)
        ->and($chamadasPorLote->all())->toBe([1, 1, 1])
        ->and($chamadasPorLote->max())->toBeLessThanOrEqual(app(ParametrosDownloadEmLote::class)->maxPaginas())
        ->and(array_column($chamadas->getArrayCopy(), 'pagina'))->toBe([1, 1, 1])
        ->and(array_column($chamadas->getArrayCopy(), 'tamanhoPagina'))->toBe([20, 20, 20]);

    foreach ($lotes as $indice => $lote) {
        $doLote = $documentos->whereIn('id', $lote->processoDocumentoIds);

        expect($chamadas[$indice]['ids'])->toBe(idsDocumentoFluxo($doLote))
            ->and($chamadas[$indice]['numeroProcesso'])->toBe((string) $processo->numero_processo);
    }

    $documentos->each(fn ($documento) => expectBaixadoNoFluxo($documento, 1));
    Log::shouldNotHaveReceived('error');
});

// RF-10: vídeo fora do lote

it('no despacho do execute os videos seguem por documento e o lote consulta so os nao-video', function () {
    [$processo] = criarDocumentosLote([]);
    salvarDocumentosFluxo($processo, [
        ...documentosDaConsultaFluxo(3, 8830001),
        ...documentosDaConsultaFluxo(1, 8830004, ['mimetype' => 'video/mp4']),
        ...documentosDaConsultaFluxo(1, 8830005, ['mimetype' => 'video/quicktime']),
    ], '{LOGIN}', '{SENHA}');
    $documentos = documentosDoProcessoFluxo($processo);
    $pdfs = $documentos->where('mimetype', 'application/pdf')->values();
    $videos = $documentos->whereIn('mimetype', ['video/mp4', 'video/quicktime'])->values();
    $chamadas = simularConsultarProcessosFluxo($this->consultarProcessos, todosNaPagina1Fluxo());

    $lote = lotesFluxo($processo)->sole();
    $jobsDeVideo = Queue::pushed(
        BaixarDocumentoMNIJob::class,
        fn ($job) => in_array((fn () => $this->documento->id)->call($job), $videos->pluck('id')->all(), true)
    );

    $lote->handle();

    expect($lote->processoDocumentoIds)->toEqualCanonicalizing($pdfs->pluck('id')->all())
        ->and($jobsDeVideo)->toHaveCount(2)
        ->and($jobsDeVideo->every(fn ($job) => $job->queue === 'mni-download'))->toBeTrue()
        ->and($chamadas)->toHaveCount(1)
        ->and($chamadas[0]['ids'])->toBe(idsDocumentoFluxo($pdfs));

    $pdfs->each(fn ($documento) => expectBaixadoNoFluxo($documento, 1));
    $videos->each(fn ($video) => expect($video->fresh()->status)->toBe(ProcessoDocumento::STATUS_PENDENTE));
});

// RF-12 (b): trava liberada no fim do job

it('depois de um handle sem excecao o documento que ficou em erro entra num novo lote', function () {
    [$processo] = criarDocumentosLote([]);
    $consulta = documentosDaConsultaFluxo(3, 8840001);
    salvarDocumentosFluxo($processo, $consulta, '{LOGIN}', '{SENHA}');
    [$a, $b, $c] = documentosDoProcessoFluxo($processo)->all();
    $primeiroLote = lotesFluxo($processo)->sole();
    // C não volta em nenhuma página: falha só dele, sem exceção (RF-07).
    simularConsultarProcessosFluxo($this->consultarProcessos, fn (array $ids, int $pagina) => $pagina === 1
        ? [documentoMniFake($a), documentoMniFake($b)]
        : []);

    // Com o lote ainda na fila, a próxima consulta do processo não o duplica (RF-12 a).
    salvarDocumentosFluxo($processo, $consulta, '{LOGIN}', '{SENHA}');
    expect(lotesFluxo($processo))->toHaveCount(1);

    $primeiroLote->handle();

    expectBaixadoNoFluxo($a, 1);
    expectBaixadoNoFluxo($b, 1);
    expectErroNoFluxo($c, 1);

    salvarDocumentosFluxo($processo, $consulta, '{LOGIN}', '{SENHA}');

    $lotes = lotesFluxo($processo);

    expect($lotes)->toHaveCount(2)
        ->and($lotes->last()->processoDocumentoIds)->toBe([$c->id]);
});

// RF-13 (a): falha definitiva

it('depois da falha definitiva os documentos em erro entram num novo lote e o baixado fica como esta', function () {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 3, [
        'status' => ProcessoDocumento::STATUS_ERRO,
        'tentativas_download' => 1,
    ]));
    [$a, $b, $c] = $documentos->all();

    $this->artisan('mni:baixar-documento-pendente')->assertSuccessful();

    $lote = lotesFluxo($processo)->sole();
    // A volta na página 1 e a página 2 cai no transporte: falha total (RF-08 b).
    simularConsultarProcessosFluxo($this->consultarProcessos, fn (array $ids, int $pagina) => $pagina === 1
        ? [documentoMniFake($a)]
        : throw new MNIException('Could not connect to host', 500));

    expect(fn () => $lote->handle())->toThrow(MNIException::class);

    expectBaixadoNoFluxo($a, 2);
    expectErroNoFluxo($b, 2);
    expectErroNoFluxo($c, 2);

    // Entre as tentativas da fila a trava continua: o comando não duplica o lote.
    $this->artisan('mni:baixar-documento-pendente')->assertSuccessful();
    expect(lotesFluxo($processo))->toHaveCount(1);

    $baixadoAntes = $a->fresh()->getAttributes();

    $lote->failed(new MaxAttemptsExceededException(BaixarDocumentosMNIEmLoteJob::class . ' has been attempted too many times.'));

    // B e C já receberam +1 na tentativa que falhou: sem contagem dupla.
    expect($a->fresh()->getAttributes())->toBe($baixadoAntes);
    expectErroNoFluxo($b, 2);
    expectErroNoFluxo($c, 2);

    // B e C já tinham 1 log da falha total; a falha definitiva soma 1 a cada.
    Log::shouldHaveReceived('error')->withArgs(logDaFalhaDefinitivaFluxo())->twice();
    Log::shouldHaveReceived('error')->withArgs(logDaFalhaDefinitivaFluxo($b))->once();
    Log::shouldHaveReceived('error')->withArgs(logDaFalhaDefinitivaFluxo($c))->once();

    $this->artisan('mni:baixar-documento-pendente')->assertSuccessful();

    $lotes = lotesFluxo($processo);

    expect($lotes)->toHaveCount(2)
        ->and($lotes->last()->processoDocumentoIds)->toEqualCanonicalizing([$b->id, $c->id]);
});

// RF-11: credenciais do lote

it('leva o login e a senha do execute a todas as paginas de consultarProcessos', function () {
    [$processo] = criarDocumentosLote([]);
    salvarDocumentosFluxo($processo, documentosDaConsultaFluxo(20, 8850001), '{LOGIN}', '{SENHA}');
    $chamadas = simularConsultarProcessosFluxo($this->consultarProcessos, fn (array $ids, int $pagina) => array_map(
        fn (string $id) => documentoMniFake($id),
        $pagina === 1 ? array_slice($ids, 0, 12) : array_slice($ids, 12)
    ));

    lotesFluxo($processo)->sole()->handle();

    expect($chamadas)->toHaveCount(2)
        ->and(array_column($chamadas->getArrayCopy(), 'pagina'))->toBe([1, 2])
        ->and(array_column($chamadas->getArrayCopy(), 'login'))->toBe(['{LOGIN}', '{LOGIN}'])
        ->and(array_column($chamadas->getArrayCopy(), 'senha'))->toBe(['{SENHA}', '{SENHA}']);

    documentosDoProcessoFluxo($processo)->each(fn ($documento) => expectBaixadoNoFluxo($documento, 1));
});

it('pelo comando consulta todas as paginas sem login, para valer a credencial do tribunal', function () {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 3, ['status' => ProcessoDocumento::STATUS_ERRO]));
    $chamadas = simularConsultarProcessosFluxo($this->consultarProcessos, fn (array $ids, int $pagina) => array_map(
        fn (string $id) => documentoMniFake($id),
        $pagina === 1 ? array_slice($ids, 0, 2) : array_slice($ids, 2)
    ));

    $this->artisan('mni:baixar-documento-pendente')->assertSuccessful();
    lotesFluxo($processo)->sole()->handle();

    // Com login null, ConsultarProcessosService usa o login e a senha do tribunal.
    expect($chamadas)->toHaveCount(2)
        ->and(array_column($chamadas->getArrayCopy(), 'pagina'))->toBe([1, 2])
        ->and(array_column($chamadas->getArrayCopy(), 'login'))->toBe([null, null])
        ->and(array_column($chamadas->getArrayCopy(), 'senha'))->toBe([null, null]);

    $documentos->each(fn ($documento) => expectBaixadoNoFluxo($documento, 1));
});
