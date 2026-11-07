<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\TipoDocumento;
use App\Models\Tribunal;
use App\Services\Ocr\MontarEnvioOcrService;
use App\Services\Ocr\WebhookOcrUrl;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Corpo do envio ao workflow do OCR (T16; RF-11, CT-03, NC-1, Q-05): as 5
 * chaves de EnvioDeDocumentoAoOcr, o `filename` pela extensão do mimetype,
 * a `url` temporária do objeto gravado e o `metadata` de documento dos
 * autos, sem `escritorio_id`.
 */

uses(DatabaseTransactions::class);

const BASE_WEBHOOK_MONTAR_ENVIO_OCR = 'http://host.docker.internal:8002';
const BASE_CONHECIMENTO_MONTAR_ENVIO_OCR = 'base-processos-7d1';
const URL_S3_MONTAR_ENVIO_OCR = 'https://bucket.s3.sa-east-1.amazonaws.com/';

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    Storage::fake('s3');

    // Expiração de cada link temporário pedido, por `path`. O callback roda
    // ligado ao adaptador do disco, e não ao teste: captura por objeto.
    $expiracoes = $this->expiracoes = new ArrayObject;
    Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiracao) use ($expiracoes) {
        $expiracoes[$path] = $expiracao;

        return URL_S3_MONTAR_ENVIO_OCR.$path.'?X-Amz-Expires='.$expiracao->getTimestamp();
    });

    // RF-11 AC (f): a data gravada é lida no fuso da aplicação.
    config()->set('app.timezone', 'UTC');
    config()->set('services.n8n_ocr.webhook_base_url', BASE_WEBHOOK_MONTAR_ENVIO_OCR);
    config()->set('services.n8n_ocr.base_conhecimento', BASE_CONHECIMENTO_MONTAR_ENVIO_OCR);
    config()->set('services.n8n_ocr.prazo_sem_progresso_minutos', 25);
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 60);
});

function montarEnvioOcr(ProcessoDocumento $documento): array
{
    return app(MontarEnvioOcrService::class)->corpo($documento);
}

/**
 * Documento `baixado` com o objeto gravado no S3 como o download grava: o
 * PDF para PDF e HTML (com o `.html` ao lado), e a extensão de
 * SalvarDocumentoProcessoService::getFileExtension para as imagens (`.dat`
 * para webp, tiff, bmp e gif).
 */
function documentoNoS3MontarEnvioOcr(string $mimetype = 'application/pdf', string $extensaoNoS3 = 'pdf', array $atributos = [], array|Processo $processo = []): ProcessoDocumento
{
    [$processoCriado, $documentos] = criarDocumentosLote([array_merge(['mimetype' => $mimetype], $atributos)], $processo);
    $documento = $documentos->first();

    $pasta = "documentos-processos/{$processoCriado->numero_processo}";
    $path = "{$pasta}/{$documento->id_documento}.{$extensaoNoS3}";
    Storage::disk('s3')->put($path, "conteudo-{$documento->id_documento}");

    $gravado = ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => $path];

    if ($mimetype === 'text/html') {
        $gravado['path_html'] = "{$pasta}/{$documento->id_documento}.html";
        Storage::disk('s3')->put($gravado['path_html'], '<html><body>documento</body></html>');
    }

    $documento->update($gravado);

    return $documento->fresh();
}

it('o corpo de 1 PDF tem exatamente as 5 chaves e o metadata de RF-11, sem escritorio_id', function () {
    $documento = documentoNoS3MontarEnvioOcr(atributos: [
        'tipo_documento' => '57',
        'descricao' => 'Petição inicial',
        'movimento' => 'MOV-1',
        'data_juntada' => '2026-01-05 10:00:00',
    ]);
    $processo = $documento->processo;
    TipoDocumento::create(['tribunal_id' => $processo->tribunal_id, 'codigo' => '57', 'descricao' => 'Petição Inicial']);

    $corpo = montarEnvioOcr($documento);

    expect(array_keys($corpo))->toBe(['url', 'filename', 'webhook_url', 'base_conhecimento', 'metadata'])
        ->and($corpo['url'])->toBe(URL_S3_MONTAR_ENVIO_OCR.$documento->path.'?X-Amz-Expires='.now()->addMinutes(25)->getTimestamp())
        ->and($corpo['filename'])->toBe("{$documento->id_documento}.pdf")
        ->and($corpo['webhook_url'])->toBe(WebhookOcrUrl::gerar($documento))
        ->and($corpo['webhook_url'])->toStartWith(BASE_WEBHOOK_MONTAR_ENVIO_OCR.'/api/ocr/webhook?documento='.$documento->id)
        ->and($corpo['base_conhecimento'])->toBe(BASE_CONHECIMENTO_MONTAR_ENVIO_OCR)
        ->and($corpo['metadata'])->toBe([
            'numero_processo' => $processo->numero_processo,
            'tribunal_id' => (int) $processo->tribunal_id,
            'numero_documento' => (string) $documento->id_documento,
            'tipo_documento' => 'Petição Inicial',
            'descricao' => 'Petição inicial',
            'movimento' => 'MOV-1',
            'mimetype' => 'application/pdf',
            'data_juntada' => '2026-01-05T10:00:00+00:00',
            'nivel_sigilo' => 0,
        ])
        ->and($corpo['metadata'])->not->toHaveKey('escritorio_id');

    // O contrato do workflow (EnvioDeDocumentoAoOcr): uma fonte só.
    foreach (['file_base64', 'bucket_origem', 'path_origem', 'bucket_destino', 'path_destino'] as $chave) {
        expect($corpo)->not->toHaveKey($chave);
    }
});

it('os tipos do metadata seguem o contrato: tribunal_id inteiro e numero_documento texto de dígitos', function () {
    $documento = documentoNoS3MontarEnvioOcr();

    $metadata = montarEnvioOcr($documento)['metadata'];

    expect($metadata['tribunal_id'])->toBeInt()->toBeGreaterThan(0)
        ->and($metadata['numero_documento'])->toBeString()->toMatch('/^[0-9]+$/')
        ->and($metadata['numero_processo'])->toBeString()->toMatch('/^[0-9]{20}$/')
        ->and($metadata['nivel_sigilo'])->toBeInt();
});

it('HTML vai pelo PDF já gravado: url do objeto .pdf e filename .pdf', function () {
    $documento = documentoNoS3MontarEnvioOcr('text/html', 'pdf');

    $corpo = montarEnvioOcr($documento);

    expect($documento->path)->toEndWith('.pdf')
        ->and($corpo['url'])->toStartWith(URL_S3_MONTAR_ENVIO_OCR.$documento->path.'?')
        ->and($corpo['url'])->not->toContain('.html')
        ->and($corpo['filename'])->toBe("{$documento->id_documento}.pdf")
        ->and($corpo['metadata']['mimetype'])->toBe('text/html');
});

it('cada imagem vai com a extensão do mimetype no filename e a url do objeto gravado, sem objeto novo no S3', function (string $mimetype, string $extensaoNoS3, string $extensaoDoFilename) {
    $documento = documentoNoS3MontarEnvioOcr($mimetype, $extensaoNoS3);
    $objetosAntes = Storage::disk('s3')->allFiles();

    $corpo = montarEnvioOcr($documento);

    expect($corpo['filename'])->toBe("{$documento->id_documento}.{$extensaoDoFilename}")
        ->and($corpo['url'])->toStartWith(URL_S3_MONTAR_ENVIO_OCR.$documento->path.'?')
        ->and($documento->path)->toEndWith(".{$extensaoNoS3}")
        ->and($corpo['metadata']['mimetype'])->toBe($mimetype)
        ->and(Storage::disk('s3')->allFiles())->toBe($objetosAntes);
})->with([
    'image/png' => ['image/png', 'png', 'png'],
    'image/jpeg' => ['image/jpeg', 'jpg', 'jpg'],
    'image/webp' => ['image/webp', 'dat', 'webp'],
    'image/tiff' => ['image/tiff', 'dat', 'tif'],
    'image/bmp' => ['image/bmp', 'dat', 'bmp'],
    'image/gif' => ['image/gif', 'dat', 'gif'],
]);

it('data_juntada sai em ISO-8601 com fuso e nivel_sigilo null sai 0', function () {
    $documento = documentoNoS3MontarEnvioOcr(atributos: ['data_juntada' => '2026-01-05 10:00:00']);
    // A coluna é NOT NULL com padrão '0'; o null chega pelo model.
    $documento->nivel_sigilo = null;

    $metadata = montarEnvioOcr($documento)['metadata'];

    expect(config('app.timezone'))->toBe('UTC')
        ->and($metadata['data_juntada'])->toBe('2026-01-05T10:00:00+00:00')
        ->and($metadata['nivel_sigilo'])->toBe(0);
});

it('data_juntada leva o deslocamento do fuso da aplicação', function () {
    config()->set('app.timezone', 'America/Sao_Paulo');
    $documento = documentoNoS3MontarEnvioOcr(atributos: ['data_juntada' => '2026-01-05 10:00:00']);

    expect(montarEnvioOcr($documento)['metadata']['data_juntada'])->toBe('2026-01-05T10:00:00-03:00');
});

it('sem data_juntada gravada, data_juntada vai null', function () {
    $documento = documentoNoS3MontarEnvioOcr(atributos: ['data_juntada' => null]);

    expect(montarEnvioOcr($documento)['metadata'])->toHaveKey('data_juntada', null);
});

it('nivel_sigilo gravado como texto sai inteiro', function (string $gravado, int $enviado) {
    $documento = documentoNoS3MontarEnvioOcr();
    ProcessoDocumento::whereKey($documento->id)->update(['nivel_sigilo' => $gravado]);

    expect(montarEnvioOcr($documento->fresh())['metadata']['nivel_sigilo'])->toBe($enviado);
})->with([
    'padrão 0' => ['0', 0],
    'sigilo 5' => ['5', 5],
]);

it('tipo_documento é null sem cadastro do código no tribunal do processo', function () {
    $documento = documentoNoS3MontarEnvioOcr(atributos: ['tipo_documento' => '57']);
    $outroTribunal = Tribunal::factory()->create(['ativo' => true]);
    // Mesmo código, outro tribunal: não vale para este documento.
    TipoDocumento::create(['tribunal_id' => $outroTribunal->id, 'codigo' => '57', 'descricao' => 'Petição de outro tribunal']);

    expect(montarEnvioOcr($documento)['metadata'])->toHaveKey('tipo_documento', null);
});

it('o link temporário vale pelo menos P = 25 min depois do envio', function () {
    $documento = documentoNoS3MontarEnvioOcr();

    montarEnvioOcr($documento);

    expect($this->expiracoes)->toHaveKey($documento->path)
        ->and($this->expiracoes[$documento->path]->getTimestamp())->toBeGreaterThanOrEqual(now()->addMinutes(25)->getTimestamp());
});

it('o link é gerado no momento do envio, com a validade de P configurada', function () {
    config()->set('services.n8n_ocr.prazo_sem_progresso_minutos', 40);
    $documento = documentoNoS3MontarEnvioOcr();
    $this->travel(7)->minutes();

    montarEnvioOcr($documento);

    expect($this->expiracoes[$documento->path]->getTimestamp())->toBe(now()->addMinutes(40)->getTimestamp());
});

it('documento sem objeto gravado não monta corpo', function () {
    [, $documentos] = criarDocumentosLote(1);

    montarEnvioOcr($documentos->first());
})->throws(InvalidArgumentException::class);

it('mimetype que o OCR não lê não monta corpo', function () {
    $documento = documentoNoS3MontarEnvioOcr('video/mp4', 'mp4');

    montarEnvioOcr($documento);
})->throws(InvalidArgumentException::class);
