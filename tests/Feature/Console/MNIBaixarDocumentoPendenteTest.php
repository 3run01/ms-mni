<?php

use App\Console\Commands\MNIBaixarDocumentoPendente;
use App\Jobs\BaixarDocumentoMNIJob;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(fn () => Queue::fake());

/**
 * Lotes do processo indicado. As asserções são sempre por id: o comando é
 * global e um banco de desenvolvimento pode ter outros documentos em `erro`,
 * que não são problema deste teste.
 *
 * @return Collection<int, BaixarDocumentosMNIEmLoteJob>
 */
function lotesDoProcessoPendente(Processo $processo): Collection
{
    return Queue::pushed(
        BaixarDocumentosMNIEmLoteJob::class,
        fn ($job) => $job->processoId === $processo->id
    )->values();
}

/**
 * Jobs por documento (`BaixarDocumentoMNIJob`) despachados para os ids indicados.
 *
 * @param  array<int, int>  $processoDocumentoIds
 */
function jobsPorDocumentoPendente(array $processoDocumentoIds): Collection
{
    return Queue::pushed(
        BaixarDocumentoMNIJob::class,
        fn ($job) => in_array((fn () => $this->documento->id)->call($job), $processoDocumentoIds, true)
    )->values();
}

it('enfileira os documentos em erro em um lote por processo, sem job por documento', function () {
    [$processoA, $documentosA] = criarDocumentosLote(array_fill(0, 20, ['status' => ProcessoDocumento::STATUS_ERRO]));
    [$processoB, $documentosB] = criarDocumentosLote(array_fill(0, 5, ['status' => ProcessoDocumento::STATUS_ERRO]));

    $this->artisan('mni:baixar-documento-pendente')->assertSuccessful();

    $lotesA = lotesDoProcessoPendente($processoA);
    $lotesB = lotesDoProcessoPendente($processoB);

    expect($lotesA)->toHaveCount(1)
        ->and($lotesA->sole()->processoDocumentoIds)->toEqualCanonicalizing($documentosA->pluck('id')->all())
        ->and($lotesB)->toHaveCount(1)
        ->and($lotesB->sole()->processoDocumentoIds)->toEqualCanonicalizing($documentosB->pluck('id')->all())
        ->and($lotesA->concat($lotesB)->every(fn ($job) => $job->queue === 'mni-download'))->toBeTrue()
        ->and(jobsPorDocumentoPendente($documentosA->concat($documentosB)->pluck('id')->all()))->toBeEmpty();
});

it('video mp4 em erro gera exatamente 1 job por documento e fica fora dos lotes', function () {
    [$processoA, $documentosA] = criarDocumentosLote(array_fill(0, 20, ['status' => ProcessoDocumento::STATUS_ERRO]));
    [$processoB, $documentosB] = criarDocumentosLote(array_fill(0, 5, ['status' => ProcessoDocumento::STATUS_ERRO]));
    [, $videos] = criarDocumentosLote([
        ['status' => ProcessoDocumento::STATUS_ERRO, 'mimetype' => 'video/mp4'],
    ], $processoA);
    $video = $videos->sole();

    $this->artisan('mni:baixar-documento-pendente')->assertSuccessful();

    $lotes = lotesDoProcessoPendente($processoA)->concat(lotesDoProcessoPendente($processoB));
    $jobsDoVideo = jobsPorDocumentoPendente([$video->id]);

    expect($lotes->map(fn ($job) => count($job->processoDocumentoIds))->sort()->values()->all())->toBe([5, 20])
        ->and($lotes->flatMap->processoDocumentoIds->all())->not->toContain($video->id)
        ->and($jobsDoVideo)->toHaveCount(1)
        ->and($jobsDoVideo->sole()->queue)->toBe('mni-download')
        ->and(jobsPorDocumentoPendente($documentosA->concat($documentosB)->pluck('id')->all()))->toBeEmpty();
});

it('enfileira os lotes sem credenciais, para valer o login do tribunal', function () {
    [$processo] = criarDocumentosLote(array_fill(0, 2, ['status' => ProcessoDocumento::STATUS_ERRO]));

    $this->artisan('mni:baixar-documento-pendente')->assertSuccessful();

    $lote = lotesDoProcessoPendente($processo)->sole();

    expect((fn () => [$this->login_pje, $this->senha_pje])->call($lote))->toBe([null, null]);
});

it('ignora documentos pendente e baixado, mantendo a selecao so de erro', function () {
    [$processo, $documentos] = criarDocumentosLote([
        ['status' => ProcessoDocumento::STATUS_ERRO],
        ['status' => ProcessoDocumento::STATUS_PENDENTE],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/x/1.pdf'],
    ]);

    $this->artisan('mni:baixar-documento-pendente')->assertSuccessful();

    expect(lotesDoProcessoPendente($processo)->sole()->processoDocumentoIds)->toBe([$documentos->first()->id]);
});

it('imprime o resumo do despacho', function () {
    criarDocumentosLote(array_fill(0, 3, ['status' => ProcessoDocumento::STATUS_ERRO]));

    $this->artisan('mni:baixar-documento-pendente')
        ->expectsOutputToContain('Lotes enfileirados:')
        ->expectsOutputToContain('Vídeos enfileirados por documento:')
        ->assertSuccessful();
});

it('mantem a assinatura do comando', function () {
    $assinatura = (fn () => $this->signature)->call(new MNIBaixarDocumentoPendente());

    expect($assinatura)->toBe('mni:baixar-documento-pendente');
});
