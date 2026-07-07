<?php

use App\Jobs\EnviarParaS3ExportacaoJob;
use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

function criarArquivoLocalParaExportacao(ProcessoExportacao $exportacao, string $conteudo = 'pdf-bytes'): string
{
    $caminho = storage_path("app/private/exportacoes/{$exportacao->uuid_arquivo}.pdf");
    @mkdir(dirname($caminho), 0755, true);
    file_put_contents($caminho, $conteudo);
    return $caminho;
}

it('faz upload, atualiza registro e despacha webhook em sucesso', function () {
    Storage::fake('s3');
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->processando()->create(['user_id' => 7]);
    criarArquivoLocalParaExportacao($exportacao);

    (new EnviarParaS3ExportacaoJob($exportacao->id))->handle(app(ExportacaoProcessoService::class));

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_CONCLUIDO);
    Storage::disk('s3')->assertExists($exportacao->s3_path);
    Queue::assertPushed(EnviarWebhookDownloadJob::class, fn ($j) => $j->exportacaoId === $exportacao->id);
});

it('relança exception em falha transitória do upload (queue retenta)', function () {
    Storage::fake('s3');

    $exportacao = ProcessoExportacao::factory()->processando()->create();
    // Não criamos o arquivo local — força falha de IO no service
    $service = app(ExportacaoProcessoService::class);

    expect(fn () => (new EnviarParaS3ExportacaoJob($exportacao->id))->handle($service))
        ->toThrow(\Exception::class);
});

it('em failed() (esgotou tries) marca exportação como falhou e despacha webhook', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->processando()->create();

    $job = new EnviarParaS3ExportacaoJob($exportacao->id);
    $job->failed(new RuntimeException('upload definitivamente falhou'));

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_FALHOU);
    expect($exportacao->erro_resumo)->toBe('Falha ao enviar arquivo para o storage.');
    Queue::assertPushed(EnviarWebhookDownloadJob::class);
});

it('retorna sem efeito quando exportacao não existe', function () {
    Queue::fake();
    Storage::fake('s3');

    (new EnviarParaS3ExportacaoJob(999999))->handle(app(ExportacaoProcessoService::class));

    Queue::assertNothingPushed();
});
