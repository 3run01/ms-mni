<?php

use App\Jobs\BaixarDocumentoMNIJob;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Models\ProcessoDocumento;
use App\Services\Processo\EnfileirarDownloadDocumentosService;
use App\Services\Processo\TravaDownloadDocumento;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();

    $this->enfileirar = app(EnfileirarDownloadDocumentosService::class);
    $this->trava = app(TravaDownloadDocumento::class);
});

/**
 * @return Collection<int, BaixarDocumentosMNIEmLoteJob>
 */
function lotesDoDespacho(): Collection
{
    return Queue::pushed(BaixarDocumentosMNIEmLoteJob::class)->values();
}

/**
 * Credenciais privadas do job, sem expô-las na classe só para o teste.
 */
function credenciaisDoJobDespachado(object $job): array
{
    return (fn () => [$this->login_pje, $this->senha_pje])->call($job);
}

function documentoDoJobDeVideo(BaixarDocumentoMNIJob $job): ProcessoDocumento
{
    return (fn () => $this->documento)->call($job);
}

function travaLivreNoDespacho(int $processoDocumentoId): bool
{
    $trava = app(TravaDownloadDocumento::class);
    $livre = $trava->adquirir($processoDocumentoId, 'sonda-do-teste');

    if ($livre) {
        $trava->liberar($processoDocumentoId, 'sonda-do-teste');
    }

    return $livre;
}

it('enfileira so os documentos pendente e erro, deixando os baixado de fora', function () {
    [, $documentos] = criarDocumentosLote([
        ['status' => ProcessoDocumento::STATUS_PENDENTE],
        ['status' => ProcessoDocumento::STATUS_PENDENTE],
        ['status' => ProcessoDocumento::STATUS_PENDENTE],
        ['status' => ProcessoDocumento::STATUS_ERRO],
        ['status' => ProcessoDocumento::STATUS_ERRO],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/x/1.pdf'],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/x/2.pdf'],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/x/3.pdf'],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/x/4.pdf'],
    ]);

    $resumo = $this->enfileirar->enfileirar($documentos);

    $esperados = $documentos->take(5)->pluck('id')->sort()->values()->all();
    $enfileirados = lotesDoDespacho()->flatMap->processoDocumentoIds->sort()->values()->all();

    expect($enfileirados)->toBe($esperados)
        ->and($resumo)->toBe(['lotes' => 1, 'documentos_em_lote' => 5, 'videos' => 0, 'ignorados_trava' => 0]);

    // Baixado não entra e, por isso, nem trava.
    $documentos->slice(5)->each(fn ($documento) => expect(travaLivreNoDespacho($documento->id))->toBeTrue());
});

it('trata status null como pendente, como no model recem-criado por updateOrCreate', function () {
    [$processo] = criarDocumentosLote(0);

    $documento = ProcessoDocumento::updateOrCreate(
        ['processo_id' => $processo->id, 'id_documento' => 7199001],
        [
            'tipo_documento' => '57',
            'data_hora' => '2024-12-12 10:00:00',
            'mimetype' => 'application/pdf',
            'descricao' => 'Sem status hidratado',
        ],
    );

    expect($documento->status)->toBeNull()
        ->and($documento->tentativas_download)->toBeNull();

    $resumo = $this->enfileirar->enfileirar([$documento]);

    $lote = lotesDoDespacho()->sole();

    expect($resumo['documentos_em_lote'])->toBe(1)
        ->and($lote->processoDocumentoIds)->toBe([$documento->id])
        ->and($lote->tentativasNoDespacho)->toBe([$documento->id => 0]);
});

it('divide N documentos de um processo em lotes de ate T', function (int $quantidade, array $tamanhos) {
    [, $documentos] = criarDocumentosLote($quantidade);

    $resumo = $this->enfileirar->enfileirar($documentos);

    $lotes = lotesDoDespacho();

    expect($lotes->map(fn ($lote) => count($lote->processoDocumentoIds))->all())->toBe($tamanhos)
        ->and($resumo['lotes'])->toBe(count($tamanhos))
        ->and($resumo['documentos_em_lote'])->toBe($quantidade);
})->with([
    'N = 45' => [45, [20, 20, 5]],
    'N = 20' => [20, [20]],
    'N = 1' => [1, [1]],
]);

it('nao mistura processos e cobre cada documento em exatamente um lote', function () {
    [$processoA, $documentosA] = criarDocumentosLote(25);
    [$processoB, $documentosB] = criarDocumentosLote(3);

    // Entrada intercalada: o agrupamento é por processo_id, não pela ordem.
    $selecao = $documentosA->take(10)
        ->concat($documentosB)
        ->concat($documentosA->slice(10));

    $this->enfileirar->enfileirar($selecao);

    $lotes = lotesDoDespacho();
    $idsDoProcesso = [
        $processoA->id => $documentosA->pluck('id')->all(),
        $processoB->id => $documentosB->pluck('id')->all(),
    ];

    foreach ($lotes as $lote) {
        expect(array_diff($lote->processoDocumentoIds, $idsDoProcesso[$lote->processoId]))->toBe([])
            ->and(count($lote->processoDocumentoIds))->toBeLessThanOrEqual(20);
    }

    $todos = $lotes->flatMap->processoDocumentoIds;

    expect($lotes)->toHaveCount(3)
        ->and($todos->count())->toBe($todos->unique()->count())
        ->and($todos->sort()->values()->all())->toBe($selecao->pluck('id')->sort()->values()->all());
});

it('deixa video mp4 fora do lote e despacha um job por documento para cada video', function () {
    [, $documentos] = criarDocumentosLote([
        ['mimetype' => 'application/pdf'],
        ['mimetype' => 'video/mp4'],
        ['mimetype' => 'application/pdf'],
        ['mimetype' => 'video/mp4'],
        ['mimetype' => 'application/pdf'],
    ]);
    $pdfs = $documentos->where('mimetype', 'application/pdf');
    $videos = $documentos->where('mimetype', 'video/mp4');

    $resumo = $this->enfileirar->enfileirar($documentos, '{LOGIN}', '{SENHA}');

    expect(lotesDoDespacho()->sole()->processoDocumentoIds)->toBe($pdfs->pluck('id')->values()->all())
        ->and($resumo)->toBe(['lotes' => 1, 'documentos_em_lote' => 3, 'videos' => 2, 'ignorados_trava' => 0]);

    Queue::assertPushed(BaixarDocumentoMNIJob::class, 2);

    $videosDespachados = Queue::pushed(BaixarDocumentoMNIJob::class)
        ->map(fn ($job) => documentoDoJobDeVideo($job)->id)
        ->sort()->values()->all();

    expect($videosDespachados)->toBe($videos->pluck('id')->sort()->values()->all());

    // Vídeo segue o ShouldBeUnique do próprio job, sem trava de lote.
    $videos->each(fn ($video) => expect(travaLivreNoDespacho($video->id))->toBeTrue());
});

it('deixa video quicktime fora do lote', function () {
    [, $documentos] = criarDocumentosLote([
        ['mimetype' => 'video/quicktime'],
        ['mimetype' => 'audio/mpeg'],
    ]);

    $this->enfileirar->enfileirar($documentos);

    expect(lotesDoDespacho()->sole()->processoDocumentoIds)->toBe([$documentos[1]->id])
        ->and(documentoDoJobDeVideo(Queue::pushed(BaixarDocumentoMNIJob::class)->sole())->id)->toBe($documentos[0]->id);
});

it('usa o T configurado: com T = 10, N = 45 gera 5 lotes', function () {
    config()->set('pje.download_documentos.tamanho_lote', 10);
    [, $documentos] = criarDocumentosLote(45);

    $this->enfileirar->enfileirar($documentos);

    expect(lotesDoDespacho()->map(fn ($lote) => count($lote->processoDocumentoIds))->all())
        ->toBe([10, 10, 10, 10, 5]);
});

it('nao inclui em outro lote o documento ja despachado e ainda nao concluido', function () {
    [, $documentos] = criarDocumentosLote(3);

    $primeiro = $this->enfileirar->enfileirar($documentos);
    $segundo = $this->enfileirar->enfileirar($documentos);

    $ocorrencias = lotesDoDespacho()->flatMap->processoDocumentoIds->countBy();

    expect(lotesDoDespacho())->toHaveCount(1)
        ->and($ocorrencias->all())->toBe($documentos->mapWithKeys(fn ($documento) => [$documento->id => 1])->all())
        ->and($primeiro['documentos_em_lote'])->toBe(3)
        ->and($segundo)->toBe(['lotes' => 0, 'documentos_em_lote' => 0, 'videos' => 0, 'ignorados_trava' => 3]);
});

it('com T = 20 e 25 elegiveis, 5 com trava ativa, enfileira 1 lote com os 20 sem trava', function () {
    [, $documentos] = criarDocumentosLote(25);
    $travados = $documentos->take(5);

    $travados->each(fn ($documento) => expect($this->trava->adquirir($documento->id, 'lote-anterior'))->toBeTrue());

    $resumo = $this->enfileirar->enfileirar($documentos);

    expect(lotesDoDespacho()->sole()->processoDocumentoIds)->toBe($documentos->slice(5)->pluck('id')->values()->all())
        ->and($resumo)->toBe(['lotes' => 1, 'documentos_em_lote' => 20, 'videos' => 0, 'ignorados_trava' => 5]);
});

it('trava cada documento do lote com o dono gravado no job, um dono por chamada', function () {
    [, $primeiros] = criarDocumentosLote(2);
    [, $segundos] = criarDocumentosLote(2);

    $this->enfileirar->enfileirar($primeiros);
    $this->enfileirar->enfileirar($segundos);

    [$loteA, $loteB] = lotesDoDespacho()->all();

    expect($loteA->donoTrava)->not->toBe($loteB->donoTrava);

    foreach ($primeiros as $documento) {
        expect(travaLivreNoDespacho($documento->id))->toBeFalse();
    }

    // Só o dono gravado no job solta a trava: é o que o job faz ao terminar.
    $this->trava->liberarTodas($loteA->processoDocumentoIds, $loteB->donoTrava);
    expect(travaLivreNoDespacho($primeiros[0]->id))->toBeFalse();

    $this->trava->liberarTodas($loteA->processoDocumentoIds, $loteA->donoTrava);
    expect(travaLivreNoDespacho($primeiros[0]->id))->toBeTrue()
        ->and(travaLivreNoDespacho($primeiros[1]->id))->toBeTrue();
});

it('envia todo job para a fila mni-download repassando as credenciais e o snapshot de tentativas', function () {
    [$processo, $documentos] = criarDocumentosLote([
        ['tentativas_download' => 0],
        ['tentativas_download' => 2, 'status' => ProcessoDocumento::STATUS_ERRO],
        ['mimetype' => 'video/mp4'],
    ]);

    $this->enfileirar->enfileirar($documentos, '{LOGIN}', '{SENHA}');

    $lote = lotesDoDespacho()->sole();
    $video = Queue::pushed(BaixarDocumentoMNIJob::class)->sole();

    expect($lote->queue)->toBe('mni-download')
        ->and($video->queue)->toBe('mni-download')
        ->and($lote->processoId)->toBe($processo->id)
        ->and($lote->tentativasNoDespacho)->toBe([$documentos[0]->id => 0, $documentos[1]->id => 2])
        ->and(credenciaisDoJobDespachado($lote))->toBe(['{LOGIN}', '{SENHA}'])
        ->and(credenciaisDoJobDespachado($video))->toBe(['{LOGIN}', '{SENHA}']);
});

it('sem credenciais despacha os jobs com login e senha null, para usar as do tribunal', function () {
    [, $documentos] = criarDocumentosLote([[], ['mimetype' => 'video/quicktime']]);

    $this->enfileirar->enfileirar($documentos);

    expect(credenciaisDoJobDespachado(lotesDoDespacho()->sole()))->toBe([null, null])
        ->and(credenciaisDoJobDespachado(Queue::pushed(BaixarDocumentoMNIJob::class)->sole()))->toBe([null, null]);
});

it('deduplica por id e aceita qualquer iterable', function () {
    [, $documentos] = criarDocumentosLote(3);

    $selecao = (function () use ($documentos) {
        yield from $documentos;
        yield ProcessoDocumento::find($documentos[0]->id);
        yield $documentos[1];
    })();

    $resumo = $this->enfileirar->enfileirar($selecao);

    expect(lotesDoDespacho()->sole()->processoDocumentoIds)->toBe($documentos->pluck('id')->all())
        ->and($resumo['ignorados_trava'])->toBe(0);
});

it('nao enfileira nada para uma selecao vazia', function () {
    $resumo = $this->enfileirar->enfileirar([]);

    Queue::assertNothingPushed();
    expect($resumo)->toBe(['lotes' => 0, 'documentos_em_lote' => 0, 'videos' => 0, 'ignorados_trava' => 0]);
});

it('libera as travas do lote cujo despacho falhou e dos seguintes, mantem as do ja enfileirado e relanca', function () {
    [, $documentos] = criarDocumentosLote(45);

    $despachados = collect();
    $this->mock(Dispatcher::class, function ($dispatcher) use ($despachados) {
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($despachados) {
            if ($despachados->count() === 1) {
                throw new RuntimeException('fila mni-download indisponível');
            }

            $despachados->push($job);
        });
    });

    expect(fn () => $this->enfileirar->enfileirar($documentos))
        ->toThrow(RuntimeException::class, 'fila mni-download indisponível');

    $enfileirado = $despachados->sole();

    expect($enfileirado->processoDocumentoIds)->toBe($documentos->take(20)->pluck('id')->all());

    // Lote enfileirado: a trava fica com o job.
    $documentos->take(20)->each(fn ($documento) => expect(travaLivreNoDespacho($documento->id))->toBeFalse());

    // Lote que falhou e o que nem chegou a ser despachado: travas soltas.
    $documentos->slice(20)->each(fn ($documento) => expect(travaLivreNoDespacho($documento->id))->toBeTrue());
});
