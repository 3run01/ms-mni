<?php

use App\Exceptions\CredencialPjeRecusadaException;
use App\Models\ProcessoDocumento;
use App\Services\MNI\ClassificarFalhaMniService;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

/*
 * Download que revela o mimetype (T09; RF-09, DP-06 passo 1). O documento
 * com mimetype `''` é pedido ao tribunal com o par recebido, mesmo já
 * `baixado` no S3; o binário é gravado pelo mimetype da resposta, e o
 * atributo `mimetype` não muda (a gravação fica com T10, sob as travas).
 */

uses(DatabaseTransactions::class);

const LOGIN_REVELAR_MIMETYPE = 'login-revelar-mimetype-4d2';
const SENHA_REVELAR_MIMETYPE = 'senha-revelar-mimetype-9a7';
const RECUSA_REVELAR_MIMETYPE = 'Erro ao realizar login via MNI. exception invoking: loginFailed';

beforeEach(function () {
    Storage::fake('s3');
    definirCredenciaisPadrao(null, null);
});

/**
 * Documento `''` num processo com tribunal ativo.
 */
function documentoVazioRevelarMimetype(array $atributos = []): ProcessoDocumento
{
    [, $documentos] = criarDocumentosLote([array_merge(['mimetype' => ''], $atributos)]);

    return $documentos->first();
}

/**
 * O tribunal simulado devolve o documento pedido com `$mimetype` (sem a
 * chave, com `null`) e `$conteudo` (cru, como o MNI manda: a normalização
 * codifica em base64).
 */
function mniRevelarMimetype(ProcessoDocumento $documento, ?string $mimetype, ?string $conteudo = null): stdClass
{
    $documentoMni = documentoMniFake($documento->id_documento, [
        'mimetype' => $mimetype,
        'conteudo' => $conteudo ?? "conteudo-binario-{$documento->id_documento}",
    ]);

    if ($mimetype === null) {
        unset($documentoMni->mimetype);
    }

    return simularIntegracaoMni(fn () => (object) [
        'sucesso' => true,
        'processo' => (object) ['documento' => $documentoMni],
    ]);
}

function revelarMimetype(ProcessoDocumento $documento): string
{
    return app(SalvarDocumentoProcessoService::class)
        ->baixarRevelandoMimetype($documento, LOGIN_REVELAR_MIMETYPE, SENHA_REVELAR_MIMETYPE);
}

function caminhoRevelarMimetype(ProcessoDocumento $documento, string $extensao): string
{
    return "documentos-processos/{$documento->processo->numero_processo}/{$documento->id_documento}.{$extensao}";
}

it('vazio pendente revelado PDF: devolve o mimetype, grava .pdf, marca baixado com 1 SOAP com o par e não troca a coluna mimetype', function () {
    $documento = documentoVazioRevelarMimetype();
    $mni = mniRevelarMimetype($documento, 'application/pdf', '%PDF-1.4 revelado');

    $revelado = revelarMimetype($documento);

    $depois = $documento->fresh();
    $pdf = caminhoRevelarMimetype($documento, 'pdf');

    expect($revelado)->toBe('application/pdf')
        ->and($depois->path)->toBe($pdf)
        ->and($depois->path)->toEndWith('.pdf')
        ->and($depois->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($depois->downloaded_at)->not->toBeNull()
        ->and($depois->mimetype)->toBe('')
        ->and($documento->mimetype)->toBe('')
        ->and($documento->isDirty('mimetype'))->toBeFalse()
        ->and(Storage::disk('s3')->get($pdf))->toBe('%PDF-1.4 revelado');

    expect($mni->requisicoes)->toHaveCount(1)
        ->and($mni->requisicoes[0]['parametros'])->toMatchArray([
            'idConsultante' => LOGIN_REVELAR_MIMETYPE,
            'senhaConsultante' => SENHA_REVELAR_MIMETYPE,
            'documento' => (string) $documento->id_documento,
        ]);
});

it('vazio já baixado com o .dat no S3: pede de novo ao tribunal, com 1 SOAP', function () {
    $documento = documentoVazioRevelarMimetype();
    $dat = caminhoRevelarMimetype($documento, 'dat');
    Storage::disk('s3')->put($dat, 'binario-antigo');
    $documento->update(['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => $dat]);

    $mni = mniRevelarMimetype($documento, 'application/pdf');

    expect(revelarMimetype($documento))->toBe('application/pdf')
        ->and($mni->requisicoes)->toHaveCount(1)
        ->and($documento->fresh()->path)->toBe(caminhoRevelarMimetype($documento, 'pdf'))
        ->and($documento->fresh()->mimetype)->toBe('');
});

it('revelado HTML: path .pdf e path_html .html, os 2 objetos no S3', function () {
    $documento = documentoVazioRevelarMimetype();
    $html = '<html><body><p>Despacho revelado</p></body></html>';
    mniRevelarMimetype($documento, 'text/html', $html);

    expect(revelarMimetype($documento))->toBe('text/html');

    $depois = $documento->fresh();

    expect($depois->path)->toBe(caminhoRevelarMimetype($documento, 'pdf'))
        ->and($depois->path_html)->toBe(caminhoRevelarMimetype($documento, 'html'))
        ->and(Storage::disk('s3')->exists($depois->path))->toBeTrue()
        ->and(Storage::disk('s3')->exists($depois->path_html))->toBeTrue()
        ->and(Storage::disk('s3')->get($depois->path_html))->toBe($html)
        ->and($depois->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($depois->mimetype)->toBe('');
});

it('revelado vídeo: grava .mp4 com 1 SOAP, sem recusar o vídeo', function () {
    $documento = documentoVazioRevelarMimetype();
    $mni = mniRevelarMimetype($documento, 'video/mp4', 'ftypmp42-video');

    expect(revelarMimetype($documento))->toBe('video/mp4');

    $depois = $documento->fresh();

    expect($depois->path)->toBe(caminhoRevelarMimetype($documento, 'mp4'))
        ->and(Storage::disk('s3')->get($depois->path))->toBe('ftypmp42-video')
        ->and($depois->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($depois->mimetype)->toBe('')
        ->and($mni->requisicoes)->toHaveCount(1);
});

it('revelado áudio: grava pela extensão do revelado', function () {
    $documento = documentoVazioRevelarMimetype();
    mniRevelarMimetype($documento, 'audio/mpeg', 'ID3-audio');

    expect(revelarMimetype($documento))->toBe('audio/mpeg')
        ->and($documento->fresh()->path)->toBe(caminhoRevelarMimetype($documento, 'mp3'))
        ->and($documento->fresh()->mimetype)->toBe('');
});

it('o tribunal sem mimetype na resposta: devolve "" e grava .dat', function (?string $informado) {
    $documento = documentoVazioRevelarMimetype();
    mniRevelarMimetype($documento, $informado);

    expect(revelarMimetype($documento))->toBe('');

    $depois = $documento->fresh();

    expect($depois->path)->toBe(caminhoRevelarMimetype($documento, 'dat'))
        ->and(Storage::disk('s3')->exists($depois->path))->toBeTrue()
        ->and($depois->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($depois->mimetype)->toBe('');
})->with([
    'vazio' => [''],
    'só espaços' => ['   '],
    'ausente' => [null],
]);

it('o mimetype revelado sai sem espaços nas pontas', function () {
    $documento = documentoVazioRevelarMimetype();
    mniRevelarMimetype($documento, ' application/pdf ');

    expect(revelarMimetype($documento))->toBe('application/pdf')
        ->and($documento->fresh()->path)->toEndWith('.pdf');
});

it('a recusa do par informado sobe sem embrulho, com a recusa na cadeia, e o documento não muda', function () {
    $documento = documentoVazioRevelarMimetype();
    $antes = $documento->fresh()->only(['status', 'path', 'path_html', 'mimetype', 'downloaded_at', 'tentativas_download']);
    $mni = simularIntegracaoMni(fn () => (object) ['sucesso' => false, 'mensagem' => RECUSA_REVELAR_MIMETYPE]);

    $capturada = null;

    try {
        revelarMimetype($documento);
    } catch (Throwable $e) {
        $capturada = $e;
    }

    expect($capturada)->toBeInstanceOf(CredencialPjeRecusadaException::class)
        ->and(ClassificarFalhaMniService::recusaNaCadeia($capturada))->toBeTrue()
        ->and($mni->requisicoes)->toHaveCount(1)
        ->and($documento->fresh()->only(array_keys($antes)))->toBe($antes)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('as demais falhas sobem embrulhadas, com a original como previous, e o documento não muda', function () {
    $documento = documentoVazioRevelarMimetype();
    $antes = $documento->fresh()->only(['status', 'path', 'path_html', 'mimetype', 'downloaded_at']);
    simularIntegracaoMni(fn () => (object) ['sucesso' => false, 'mensagem' => 'MNI indisponível']);

    $capturada = null;

    try {
        revelarMimetype($documento);
    } catch (Throwable $e) {
        $capturada = $e;
    }

    expect($capturada)->toBeInstanceOf(Exception::class)
        ->and($capturada->getMessage())->toStartWith('Erro ao processar documento sem mimetype: ')
        ->and($capturada->getPrevious())->not->toBeNull()
        ->and(ClassificarFalhaMniService::recusaNaCadeia($capturada))->toBeFalse()
        ->and($documento->fresh()->only(array_keys($antes)))->toBe($antes);
});

it('salvarConteudoOutros e salvarConteudoAudio sem o mimetype novo seguem o gravado', function () {
    $service = app(SalvarDocumentoProcessoService::class);
    [, $documentos] = criarDocumentosLote([
        ['mimetype' => 'application/zip'],
        ['mimetype' => 'audio/ogg'],
    ]);
    [$zip, $ogg] = [$documentos[0], $documentos[1]];
    $conteudo = (object) ['conteudo' => base64_encode('binario')];

    expect($service->salvarConteudoOutros($zip, $conteudo))->toEndWith("{$zip->id_documento}.zip")
        ->and($service->salvarConteudoOutros($zip, $conteudo, 'video/mp4'))->toEndWith("{$zip->id_documento}.mp4")
        ->and($service->salvarConteudoOutros($zip, $conteudo, ''))->toEndWith("{$zip->id_documento}.dat")
        ->and($service->salvarConteudoAudio($ogg, $conteudo))->toEndWith("{$ogg->id_documento}.ogg")
        ->and($service->salvarConteudoAudio($ogg, $conteudo, 'audio/mpeg'))->toEndWith("{$ogg->id_documento}.mp3");
});

it('o par é parâmetro sensível', function () {
    $parametros = collect((new ReflectionMethod(SalvarDocumentoProcessoService::class, 'baixarRevelandoMimetype'))->getParameters())
        ->keyBy(fn (ReflectionParameter $p) => $p->getName());

    expect($parametros['login']->getAttributes(SensitiveParameter::class))->toHaveCount(1)
        ->and($parametros['senha']->getAttributes(SensitiveParameter::class))->toHaveCount(1)
        ->and($parametros['documento']->getAttributes(SensitiveParameter::class))->toHaveCount(0);
});
