<?php

use App\Exceptions\MNIException;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Processo\BaixarDocumentosEmLoteService;
use App\Services\Processo\SalvarDocumentoProcessoService;
use App\Services\Processo\TravaDownloadDocumento;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake('s3');
    Log::spy();

    $this->trava = app(TravaDownloadDocumento::class);
});

/**
 * Job para os documentos dados, com as travas já adquiridas pelo despacho e
 * o snapshot de `tentativas_download` do momento da criação (ou `$snapshot`).
 */
function jobDoLote(Processo $processo, Collection $documentos, ?array $snapshot = null, ?string $login = null, ?string $senha = null): BaixarDocumentosMNIEmLoteJob
{
    $trava = app(TravaDownloadDocumento::class);

    foreach ($documentos as $documento) {
        expect($trava->adquirir($documento->id, 'dono-do-lote-job'))->toBeTrue();
    }

    return new BaixarDocumentosMNIEmLoteJob(
        $processo->id,
        $documentos->pluck('id')->all(),
        $snapshot ?? $documentos->mapWithKeys(fn ($documento) => [$documento->id => (int) $documento->tentativas_download])->all(),
        'dono-do-lote-job',
        $login,
        $senha,
    );
}

function travaDoLoteLiberada(ProcessoDocumento $documento): bool
{
    // Outro despacho consegue adquirir só se o job soltou a trava.
    $trava = app(TravaDownloadDocumento::class);
    $liberada = $trava->adquirir($documento->id, 'outro-despacho');

    if ($liberada) {
        $trava->liberar($documento->id, 'outro-despacho');
    }

    return $liberada;
}

function logsDeErroDoJob(ProcessoDocumento $documento): void
{
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($mensagem, $contexto = []) => ($contexto['id_documento'] ?? null) === $documento->id_documento)
        ->once();
}

function falhaDefinitivaDaFila(): MaxAttemptsExceededException
{
    return new MaxAttemptsExceededException(BaixarDocumentosMNIEmLoteJob::class . ' has been attempted too many times.');
}

it('declara 3 tentativas e timeout de 300 s alinhados ao supervisor mni-download, sem ShouldBeUnique', function () {
    [$processo, $documentos] = criarDocumentosLote(1);
    $job = new BaixarDocumentosMNIEmLoteJob($processo->id, $documentos->pluck('id')->all(), [], 'dono-do-lote-job');

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->not->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(300)
        ->and($job->tries)->toBe(config('horizon.defaults.supervisor-mni-download.tries'))
        ->and($job->timeout)->toBe(config('horizon.defaults.supervisor-mni-download.timeout'));
});

// handle()

it('delega ao servico com os ids e as credenciais do lote e libera as travas ao terminar sem excecao', function () {
    [$processo, $documentos] = criarDocumentosLote(3);
    $job = jobDoLote($processo, $documentos, login: '{LOGIN}', senha: '{SENHA}');
    $this->mock(BaixarDocumentosEmLoteService::class)
        ->shouldReceive('executar')
        ->once()
        ->with($processo->id, $documentos->pluck('id')->all(), '{LOGIN}', '{SENHA}');

    $job->handle();

    $documentos->each(fn ($documento) => expect(travaDoLoteLiberada($documento))->toBeTrue());
});

it('mantem as credenciais depois de serializado para a fila', function () {
    [$processo, $documentos] = criarDocumentosLote(1);
    $job = unserialize(serialize(jobDoLote($processo, $documentos, login: '{LOGIN}', senha: '{SENHA}')));
    $this->mock(BaixarDocumentosEmLoteService::class)
        ->shouldReceive('executar')
        ->once()
        ->with($processo->id, $documentos->pluck('id')->all(), '{LOGIN}', '{SENHA}');

    $job->handle();
});

it('relanca a excecao do servico e mantem as travas para a proxima tentativa', function () {
    [$processo, $documentos] = criarDocumentosLote(2);
    $job = jobDoLote($processo, $documentos);
    $this->mock(BaixarDocumentosEmLoteService::class)
        ->shouldReceive('executar')
        ->once()
        ->andThrow(new MNIException('Could not connect to host', 500));

    expect(fn () => $job->handle())->toThrow(MNIException::class);

    $documentos->each(fn ($documento) => expect(travaDoLoteLiberada($documento))->toBeFalse());
});

it('registra warning, libera as travas e termina sem excecao quando o processo nao existe', function () {
    [$processo, $documentos] = criarDocumentosLote(2);
    $job = jobDoLote($processo, $documentos);
    $processoInexistente = (int) Processo::max('id') + 1000;
    $job->processoId = $processoInexistente;

    $job->handle();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($mensagem) => str_starts_with($mensagem, "[Processo:{$processoInexistente}]"))
        ->once();
    Log::shouldNotHaveReceived('error');
    $documentos->each(fn ($documento) => expect(travaDoLoteLiberada($documento))->toBeTrue());
});

// failed(): RF-13

it('marca erro nos nao baixados, preserva o baixado com objeto no S3 e libera as travas na falha definitiva', function () {
    [$processo, $documentos] = criarDocumentosLote([
        ['tentativas_download' => 1],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/rf13a/2.pdf', 'tentativas_download' => 1],
        ['tentativas_download' => 1],
    ]);
    Storage::disk('s3')->put('documentos-processos/rf13a/2.pdf', 'pdf');
    [$a, $baixado, $c] = $documentos->all();
    $antes = $baixado->fresh()->getAttributes();
    $job = jobDoLote($processo, $documentos);

    $job->failed(falhaDefinitivaDaFila());

    foreach ([$a, $c] as $documento) {
        $documento->refresh();
        expect($documento->status)->toBe(ProcessoDocumento::STATUS_ERRO)
            ->and($documento->tentativas_download)->toBe(2);
    }

    expect($baixado->fresh()->getAttributes())->toBe($antes);
    Log::shouldHaveReceived('error')->twice();
    logsDeErroDoJob($a);
    logsDeErroDoJob($c);
    $documentos->each(fn ($documento) => expect(travaDoLoteLiberada($documento))->toBeTrue());
});

it('soma +1 so nos documentos que nao receberam +1 em nenhuma tentativa do job', function () {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 3, ['tentativas_download' => 5]));
    [$a, $b, $c] = $documentos->all();
    $job = jobDoLote($processo, $documentos);
    // A recebeu +1 numa tentativa anterior (falha total do RF-08 b); B e C não.
    $a->update(['status' => ProcessoDocumento::STATUS_ERRO, 'tentativas_download' => 6]);

    $job->failed(falhaDefinitivaDaFila());

    expect($a->fresh()->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($a->fresh()->tentativas_download)->toBe(6)
        ->and($b->fresh()->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($b->fresh()->tentativas_download)->toBe(6)
        ->and($c->fresh()->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($c->fresh()->tentativas_download)->toBe(6);
    Log::shouldHaveReceived('error')->times(3);
});

it('marca erro com +1 no pendente e no baixado sem objeto no S3 quando nenhuma tentativa chegou a eles', function () {
    [$processo, $documentos] = criarDocumentosLote([
        ['tentativas_download' => 2],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/rf13c/2.pdf', 'tentativas_download' => 4],
    ]);
    [$a, $b] = $documentos->all();
    $job = jobDoLote($processo, $documentos);

    $job->failed(falhaDefinitivaDaFila());

    expect($a->fresh()->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($a->fresh()->tentativas_download)->toBe(3)
        ->and($b->fresh()->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($b->fresh()->tentativas_download)->toBe(5);
    Log::shouldHaveReceived('error')->twice();
    logsDeErroDoJob($a);
    logsDeErroDoJob($b);
    $documentos->each(fn ($documento) => expect(travaDoLoteLiberada($documento))->toBeTrue());
});

it('trata erro na verificacao do S3 como objeto ausente', function () {
    [$processo, $documentos] = criarDocumentosLote([
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/s3-fora/1.pdf', 'tentativas_download' => 1],
    ]);
    $job = jobDoLote($processo, $documentos);
    $s3 = Mockery::mock(Filesystem::class);
    $s3->shouldReceive('exists')->andThrow(new RuntimeException('S3 indisponível'));
    Storage::set('s3', $s3);

    $job->failed(falhaDefinitivaDaFila());

    expect($documentos[0]->fresh()->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($documentos[0]->fresh()->tentativas_download)->toBe(2);
    logsDeErroDoJob($documentos[0]);
    expect(travaDoLoteLiberada($documentos[0]))->toBeTrue();
});

it('registra a mensagem MNI padronizada como motivo e aceita falha sem excecao', function (?Throwable $falha, string $motivo) {
    [$processo, $documentos] = criarDocumentosLote(1);
    $job = jobDoLote($processo, $documentos);

    $job->failed($falha);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($mensagem, $contexto = []) => str_contains($contexto['motivo'] ?? '', $motivo))
        ->once();
})->with([
    'MNIException' => [new MNIException('Erro ao realizar login via MNI. exception invoking: loginFailed', 500), 'Erro ao realizar login via MNI. Verifique suas credenciais.'],
    'sem excecao' => [null, 'motivo não informado pela fila'],
]);

it('nao altera documento de outro processo listado no lote', function () {
    [$processo, $documentos] = criarDocumentosLote(1);
    [, $deOutroProcesso] = criarDocumentosLote(1);
    $antes = $deOutroProcesso[0]->fresh()->getAttributes();
    $job = new BaixarDocumentosMNIEmLoteJob(
        $processo->id,
        [$documentos[0]->id, $deOutroProcesso[0]->id],
        [],
        'dono-do-lote-job',
    );

    $job->failed(falhaDefinitivaDaFila());

    expect($documentos[0]->fresh()->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($deOutroProcesso[0]->fresh()->getAttributes())->toBe($antes);
});

it('libera as travas mesmo quando a marcacao de erro falha', function () {
    [$processo, $documentos] = criarDocumentosLote(2);
    $job = jobDoLote($processo, $documentos);
    $this->partialMock(SalvarDocumentoProcessoService::class)
        ->shouldReceive('marcarComoErro')
        ->andThrow(new RuntimeException('banco indisponível'));

    expect(fn () => $job->failed(falhaDefinitivaDaFila()))->toThrow(RuntimeException::class, 'banco indisponível');

    $documentos->each(fn ($documento) => expect(travaDoLoteLiberada($documento))->toBeTrue());
});
