<?php

use App\Console\Commands\MediaFixInconsistent;
use App\Console\Commands\MediaRedownload;
use App\Console\Commands\MediaRedownloadTest;
use App\Jobs\BaixarDocumentoMNIJob;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Processo\EnfileirarDownloadDocumentosService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

const PERGUNTA_MEDIA_REDOWNLOAD = 'Deseja continuar com a remoção e redownload destes documentos?';
const PERGUNTA_MEDIA_FIX = 'Deseja corrigir o status destes documentos para PENDENTE?';
const PERGUNTA_MEDIA_REDOWNLOAD_TEST = 'Deseja continuar com o teste de reprocessamento?';

beforeEach(function () {
    Queue::fake();
    Storage::fake('s3');

    // Os comandos são globais: as mídias `baixado` do banco de desenvolvimento
    // saem da seleção só dentro da transação, que o DatabaseTransactions reverte.
    ProcessoDocumento::whereIn('mimetype', ['video/mp4', 'video/quicktime', 'audio/mpeg'])
        ->where('status', ProcessoDocumento::STATUS_BAIXADO)
        ->update(['status' => ProcessoDocumento::STATUS_PENDENTE]);
});

/**
 * Mídias `baixado` de um processo. Com `$comPath`, o objeto existe no S3
 * simulado, como na seleção de media:redownload; sem, é o caso inconsistente
 * de media:fix-inconsistent.
 *
 * @param  array<int, string>  $mimetypes
 * @return array{0: Processo, 1: Collection<int, ProcessoDocumento>}
 */
function criarMidiasBaixadas(array $mimetypes, bool $comPath = true, ?Processo $processo = null): array
{
    $extensoes = ['audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov'];

    $atributos = array_map(fn (string $mimetype) => [
        'mimetype' => $mimetype,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => $comPath ? 'documentos-processos/midia/' . uniqid('', true) . '.' . $extensoes[$mimetype] : null,
    ], $mimetypes);

    [$processo, $documentos] = criarDocumentosLote($atributos, $processo ?? []);

    $documentos->filter(fn ($documento) => $documento->path)
        ->each(fn ($documento) => Storage::disk('s3')->put($documento->path, 'binario'));

    return [$processo, $documentos];
}

/**
 * @return Collection<int, BaixarDocumentosMNIEmLoteJob>
 */
function lotesDeMidiaDoProcesso(Processo $processo): Collection
{
    return Queue::pushed(
        BaixarDocumentosMNIEmLoteJob::class,
        fn ($job) => $job->processoId === $processo->id
    )->values();
}

/**
 * Ids de `processo_documentos` que receberam um BaixarDocumentoMNIJob, um por despacho.
 *
 * @return array<int, int>
 */
function idsComJobPorDocumentoDeMidia(): array
{
    return Queue::pushed(BaixarDocumentoMNIJob::class)
        ->map(fn ($job) => (fn () => $this->documento->id)->call($job))
        ->values()
        ->all();
}

/**
 * Áudio vai num lote só, cada vídeo gera 1 BaixarDocumentoMNIJob e nenhum
 * áudio ganha job por documento.
 *
 * @param  Collection<int, ProcessoDocumento>  $documentos
 */
function esperarAudioEmLoteEVideoPorDocumento(Processo $processo, Collection $documentos): void
{
    $audios = $documentos->where('mimetype', 'audio/mpeg')->pluck('id')->all();
    $videos = $documentos->whereIn('mimetype', ['video/mp4', 'video/quicktime'])->pluck('id')->all();
    $lotes = lotesDeMidiaDoProcesso($processo);
    $comJobPorDocumento = idsComJobPorDocumentoDeMidia();

    expect($lotes)->toHaveCount(1)
        ->and($lotes->sole()->processoDocumentoIds)->toEqualCanonicalizing($audios)
        ->and($lotes->sole()->queue)->toBe('mni-download')
        ->and($comJobPorDocumento)->toEqualCanonicalizing($videos)
        ->and(array_intersect($comJobPorDocumento, $audios))->toBeEmpty();

    Queue::assertPushed(BaixarDocumentoMNIJob::class, fn ($job) => $job->queue === 'mni-download');
}

it('media:redownload --batch=2 com 3 audios do mesmo processo gera 2 lotes, de 2 e 1', function () {
    [$processo, $audios] = criarMidiasBaixadas(['audio/mpeg', 'audio/mpeg', 'audio/mpeg']);

    $this->artisan('media:redownload', ['--batch' => 2])
        ->expectsConfirmation(PERGUNTA_MEDIA_REDOWNLOAD, 'yes')
        ->assertSuccessful();

    $lotes = lotesDeMidiaDoProcesso($processo);

    expect($lotes->map(fn ($job) => count($job->processoDocumentoIds))->all())->toBe([2, 1])
        ->and($lotes->flatMap->processoDocumentoIds->all())->toEqualCanonicalizing($audios->pluck('id')->all())
        ->and(idsComJobPorDocumentoDeMidia())->toBeEmpty();

    $audios->each(function (ProcessoDocumento $audio) {
        Storage::disk('s3')->assertMissing($audio->path);

        expect($audio->fresh()->status)->toBe(ProcessoDocumento::STATUS_PENDENTE)
            ->and($audio->fresh()->path)->toBeNull();
    });
});

it('media:redownload manda audio para lote e cada video para 1 job por documento', function () {
    [$processo, $documentos] = criarMidiasBaixadas(['audio/mpeg', 'video/mp4', 'audio/mpeg', 'video/quicktime']);

    $this->artisan('media:redownload')
        ->expectsConfirmation(PERGUNTA_MEDIA_REDOWNLOAD, 'yes')
        ->expectsOutputToContain('Enfileirados: 4 (1 lotes, 2 vídeos)')
        ->assertSuccessful();

    esperarAudioEmLoteEVideoPorDocumento($processo, $documentos);
});

it('media:redownload conta falha no enfileirar como erro da pagina e registra em log', function () {
    criarMidiasBaixadas(['audio/mpeg', 'audio/mpeg']);
    Log::spy();

    $this->mock(EnfileirarDownloadDocumentosService::class)
        ->shouldReceive('enfileirar')
        ->once()
        ->andThrow(new RuntimeException('fila indisponível'));

    $this->artisan('media:redownload')
        ->expectsConfirmation(PERGUNTA_MEDIA_REDOWNLOAD, 'yes')
        ->expectsOutputToContain('Erros: 2')
        ->assertExitCode(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $mensagem) => str_contains($mensagem, 'Erro ao enfileirar o lote 1')
            && str_contains($mensagem, 'fila indisponível'))
        ->once();
});

it('media:fix-inconsistent manda audio para lote e cada video para 1 job por documento', function () {
    [$processo, $documentos] = criarMidiasBaixadas(['audio/mpeg', 'video/mp4', 'audio/mpeg', 'video/quicktime'], comPath: false);

    $this->artisan('media:fix-inconsistent')
        ->expectsConfirmation(PERGUNTA_MEDIA_FIX, 'yes')
        ->expectsOutputToContain('Documentos enfileirados: 4 (1 lotes, 2 vídeos)')
        ->assertSuccessful();

    esperarAudioEmLoteEVideoPorDocumento($processo, $documentos);

    $documentos->each(fn (ProcessoDocumento $documento) => expect($documento->fresh()->status)
        ->toBe(ProcessoDocumento::STATUS_PENDENTE));
});

it('media:redownload-test manda audio para lote e cada video para 1 job por documento', function () {
    [$processo, $documentos] = criarMidiasBaixadas(['audio/mpeg', 'video/mp4', 'audio/mpeg', 'video/quicktime']);

    $this->artisan('media:redownload-test')
        ->expectsConfirmation(PERGUNTA_MEDIA_REDOWNLOAD_TEST, 'yes')
        ->expectsOutputToContain('Documentos enfileirados para redownload: 4')
        ->assertSuccessful();

    esperarAudioEmLoteEVideoPorDocumento($processo, $documentos);
});

it('media:redownload-test respeita o limit padrao de 5 documentos', function () {
    [$processo] = criarMidiasBaixadas(array_fill(0, 7, 'audio/mpeg'));

    $this->artisan('media:redownload-test')
        ->expectsConfirmation(PERGUNTA_MEDIA_REDOWNLOAD_TEST, 'yes')
        ->assertSuccessful();

    expect(lotesDeMidiaDoProcesso($processo)->sole()->processoDocumentoIds)->toHaveCount(5);
});

it('mantem as assinaturas e os padroes de --batch e limit', function () {
    $assinatura = fn (string $classe) => (fn () => $this->signature)->call(new $classe());

    expect($assinatura(MediaRedownload::class))->toBe('media:redownload {--batch=50 : Número de documentos a processar por vez}')
        ->and($assinatura(MediaFixInconsistent::class))->toBe('media:fix-inconsistent')
        ->and($assinatura(MediaRedownloadTest::class))->toBe('media:redownload-test {limit=5 : Número de documentos para testar}')
        ->and(Artisan::all()['media:redownload']->getDefinition()->getOption('batch')->getDefault())->toBe('50')
        ->and(Artisan::all()['media:redownload-test']->getDefinition()->getArgument('limit')->getDefault())->toBe('5');
});
