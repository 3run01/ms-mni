<?php

use App\Exceptions\MNIException;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\MNI\Intercomunicacao\ConsultarDocumentoService;
use App\Services\MNI\Intercomunicacao\ConsultarProcessosService;
use App\Services\MNI\NormalizarDocumentoMniService;
use App\Services\Processo\BaixarDocumentosEmLoteService;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake('s3');
    Log::spy();

    // T = 20 e P pela fórmula (2), independentemente do .env local.
    config()->set('pje.download_documentos.tamanho_lote', 20);
    config()->set('pje.download_documentos.max_paginas', null);

    $this->consultarProcessos = $this->mock(ConsultarProcessosService::class);
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);

    // Caminho feliz: nenhuma consulta por documento.
    $this->mock(ConsultarDocumentoService::class)->shouldNotReceive('buscar');
});

/**
 * Responde consultarPagina com `$paginas[n]` (página ausente vem vazia) ou com
 * o retorno de `$paginas(n)`, no formato da operação real, e registra cada
 * chamada.
 */
function simularPaginasLote(MockInterface $consultarProcessos, array|Closure $paginas): ArrayObject
{
    $chamadas = new ArrayObject();

    $consultarProcessos->shouldReceive('consultarPagina')->andReturnUsing(
        function (Tribunal $tribunal, string $numeroProcesso, array $ids, int $pagina, int $tamanhoPagina, ?string $login = null, ?string $senha = null) use ($chamadas, $paginas) {
            $chamadas[] = compact('tribunal', 'numeroProcesso', 'ids', 'pagina', 'tamanhoPagina', 'login', 'senha');
            $documentos = $paginas instanceof Closure ? $paginas($pagina) : ($paginas[$pagina] ?? []);

            return NormalizarDocumentoMniService::indexarPorId($documentos);
        }
    );

    return $chamadas;
}

function executarLoteDownload(Processo $processo, Collection|array $documentos, ?string $login = null, ?string $senha = null): void
{
    app(BaixarDocumentosEmLoteService::class)->executar(
        $processo->id,
        collect($documentos)->pluck('id')->all(),
        $login,
        $senha,
    );
}

function idsDocumentoLote(Collection|array $documentos): array
{
    return collect($documentos)->map(fn (ProcessoDocumento $documento) => (string) $documento->id_documento)->values()->all();
}

function fakesMniLote(Collection|array $documentos, array $atributos = []): array
{
    return collect($documentos)->map(fn (ProcessoDocumento $documento) => documentoMniFake($documento, $atributos))->all();
}

function errosLogadosPara(ProcessoDocumento $documento, ?string $motivo = null): void
{
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($mensagem, $contexto = []) => ($contexto['id_documento'] ?? null) === $documento->id_documento
            && ($motivo === null || str_contains($contexto['motivo'] ?? '', $motivo)))
        ->once();
}

function expectBaixadoNoLote(ProcessoDocumento $documento, int $tentativasAntes): void
{
    $documento->refresh();

    expect($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($documento->path)->not->toBeEmpty()
        ->and($documento->downloaded_at)->not->toBeNull()
        ->and($documento->tentativas_download)->toBe($tentativasAntes + 1);

    Storage::disk('s3')->assertExists($documento->path);
}

function expectErroNoLote(ProcessoDocumento $documento, int $tentativasAntes): void
{
    $documento->refresh();

    expect($documento->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($documento->tentativas_download)->toBe($tentativasAntes + 1);
}

it('consulta uma unica pagina quando todos os ids voltam na pagina 1 e nao consulta documento por documento', function () {
    [$processo, $documentos] = criarDocumentosLote(20);
    $chamadas = simularPaginasLote($this->consultarProcessos, [1 => fakesMniLote($documentos)]);
    $this->partialMock(SalvarDocumentoProcessoService::class)->shouldNotReceive('consultarDocumento');

    executarLoteDownload($processo, $documentos);

    expect($chamadas)->toHaveCount(1)
        ->and($chamadas[0]['pagina'])->toBe(1)
        ->and($chamadas[0]['tamanhoPagina'])->toBe(20)
        ->and($chamadas[0]['ids'])->toBe(idsDocumentoLote($documentos))
        ->and($chamadas[0]['numeroProcesso'])->toBe($processo->numero_processo)
        ->and($chamadas[0]['tribunal']->id)->toBe($processo->tribunal_id);

    $documentos->each(fn ($documento) => expectBaixadoNoLote($documento, 0));
    Log::shouldNotHaveReceived('error');
});

it('pagina ate a pagina 2 com a mesma lista quando a pagina 1 traz so parte dos ids', function () {
    [$processo, $documentos] = criarDocumentosLote(20);
    $chamadas = simularPaginasLote($this->consultarProcessos, [
        1 => fakesMniLote($documentos->take(12)),
        2 => fakesMniLote($documentos->slice(12)),
    ]);

    executarLoteDownload($processo, $documentos);

    expect($chamadas)->toHaveCount(2)
        ->and(array_column($chamadas->getArrayCopy(), 'pagina'))->toBe([1, 2])
        ->and($chamadas[0]['ids'])->toBe(idsDocumentoLote($documentos))
        ->and($chamadas[1]['ids'])->toBe(idsDocumentoLote($documentos));

    $documentos->each(fn ($documento) => expectBaixadoNoLote($documento, 0));
});

it('para na pagina vazia e marca erro nos ids que nao voltaram', function () {
    [$processo, $documentos] = criarDocumentosLote(20);
    $chamadas = simularPaginasLote($this->consultarProcessos, [
        1 => fakesMniLote($documentos->take(15)),
        2 => [],
    ]);

    executarLoteDownload($processo, $documentos);

    expect($chamadas)->toHaveCount(2);
    $documentos->take(15)->each(fn ($documento) => expectBaixadoNoLote($documento, 0));
    $documentos->slice(15)->each(fn ($documento) => expectErroNoLote($documento, 0));

    Log::shouldHaveReceived('error')->times(5);
    $documentos->slice(15)->each(
        fn ($documento) => errosLogadosPara($documento, BaixarDocumentosEmLoteService::MOTIVO_NAO_RETORNADO)
    );
});

it('respeita o teto P configurado e conta cada documento uma unica vez mesmo repetido em toda pagina', function () {
    config()->set('pje.download_documentos.max_paginas', 3);
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 20, ['tentativas_download' => 2]));
    $quinze = $documentos->take(15);
    $chamadas = simularPaginasLote($this->consultarProcessos, fn (int $pagina) => fakesMniLote($quinze));

    executarLoteDownload($processo, $documentos);

    expect(array_column($chamadas->getArrayCopy(), 'pagina'))->toBe([1, 2, 3]);
    $quinze->each(fn ($documento) => expectBaixadoNoLote($documento, 2));
    $documentos->slice(15)->each(fn ($documento) => expectErroNoLote($documento, 2));
    Log::shouldHaveReceived('error')->times(5);
});

it('faz no maximo P paginas quando os ids nunca voltam todos e nenhuma pagina vem vazia', function (?int $maxPaginasConfigurado) {
    config()->set('pje.download_documentos.max_paginas', $maxPaginasConfigurado);
    [$processo, $documentos] = criarDocumentosLote(20);
    $chamadas = simularPaginasLote(
        $this->consultarProcessos,
        fn (int $pagina) => [documentoMniFake($documentos[$pagina - 1])]
    );

    executarLoteDownload($processo, $documentos);

    expect(array_column($chamadas->getArrayCopy(), 'pagina'))->toBe([1, 2])
        ->and(array_column($chamadas->getArrayCopy(), 'tamanhoPagina'))->toBe([20, 20]);
    expectBaixadoNoLote($documentos[0], 0);
    expectBaixadoNoLote($documentos[1], 0);
    $documentos->slice(2)->each(fn ($documento) => expectErroNoLote($documento, 0));
})->with([
    'P configurado em 2' => [2],
    'P padrao pela formula' => [null],
]);

it('deixa fora da consulta, sem escrita, o documento baixado com objeto no S3', function () {
    [$processo, $documentos] = criarDocumentosLote([
        [],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/ja-baixado/1.pdf', 'tentativas_download' => 4],
        [],
    ]);
    Storage::disk('s3')->put('documentos-processos/ja-baixado/1.pdf', 'pdf-existente');
    $baixado = $documentos[1];
    $antes = $baixado->fresh()->getAttributes();
    $restantes = collect([$documentos[0], $documentos[2]]);
    $chamadas = simularPaginasLote($this->consultarProcessos, [1 => fakesMniLote($restantes)]);

    executarLoteDownload($processo, $documentos);

    expect($chamadas)->toHaveCount(1)
        ->and($chamadas[0]['ids'])->toBe(idsDocumentoLote($restantes))
        ->and($baixado->fresh()->getAttributes())->toBe($antes);
    $restantes->each(fn ($documento) => expectBaixadoNoLote($documento, 0));
});

it('termina sem chamar o MNI e sem excecao quando todo o lote ja esta baixado no S3', function () {
    [$processo, $documentos] = criarDocumentosLote([
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/todos/1.pdf'],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/todos/2.pdf'],
    ]);
    Storage::disk('s3')->put('documentos-processos/todos/1.pdf', 'pdf');
    Storage::disk('s3')->put('documentos-processos/todos/2.pdf', 'pdf');
    $this->consultarProcessos->shouldNotReceive('consultarPagina');

    executarLoteDownload($processo, $documentos);

    $documentos->each(fn ($documento) => expect($documento->fresh()->status)->toBe(ProcessoDocumento::STATUS_BAIXADO));
    Log::shouldNotHaveReceived('error');
});

it('baixa de novo o documento baixado cujo objeto sumiu do S3', function () {
    [$processo, $documentos] = criarDocumentosLote([
        [],
        ['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/sumiu/2.pdf', 'tentativas_download' => 1],
    ]);
    $chamadas = simularPaginasLote($this->consultarProcessos, [1 => fakesMniLote($documentos)]);

    executarLoteDownload($processo, $documentos);

    expect($chamadas[0]['ids'])->toBe(idsDocumentoLote($documentos));
    expectBaixadoNoLote($documentos[0], 0);
    expectBaixadoNoLote($documentos[1], 1);
    expect(Storage::disk('s3')->get($documentos[1]->fresh()->path))->toBe("conteudo-binario-{$documentos[1]->id_documento}");
});

it('volta o elegivel em erro para pendente antes de consultar o MNI', function () {
    [$processo, $documentos] = criarDocumentosLote([['status' => ProcessoDocumento::STATUS_ERRO, 'tentativas_download' => 3]]);
    $statusNaConsulta = null;
    simularPaginasLote($this->consultarProcessos, function () use ($documentos, &$statusNaConsulta) {
        $statusNaConsulta = $documentos[0]->fresh()->status;

        return fakesMniLote($documentos);
    });

    executarLoteDownload($processo, $documentos);

    expect($statusNaConsulta)->toBe(ProcessoDocumento::STATUS_PENDENTE);
    expectBaixadoNoLote($documentos[0], 3);
});

it('grava cada mimetype nas chaves de hoje e marca baixado com path, downloaded_at e +1', function (string $mimetype, string $extensao, bool $comHtml) {
    [$processo, $documentos] = criarDocumentosLote([['mimetype' => $mimetype, 'tentativas_download' => 1]]);
    $documento = $documentos[0];
    $prefixo = "documentos-processos/{$processo->numero_processo}/{$documento->id_documento}";
    simularPaginasLote($this->consultarProcessos, [1 => fakesMniLote($documentos)]);

    executarLoteDownload($processo, $documentos);

    expectBaixadoNoLote($documento, 1);
    expect($documento->path)->toBe("{$prefixo}.{$extensao}");

    if ($comHtml) {
        Storage::disk('s3')->assertExists("{$prefixo}.html");
        expect($documento->path_html)->toBe("{$prefixo}.html");
    } else {
        expect(Storage::disk('s3')->get($documento->path))->toBe("conteudo-binario-{$documento->id_documento}");
    }
})->with([
    'text/html' => ['text/html', 'pdf', true],
    'application/pdf' => ['application/pdf', 'pdf', false],
    'audio/mpeg' => ['audio/mpeg', 'mp3', false],
    'application/msword' => ['application/msword', 'doc', false],
    'mimetype desconhecido' => ['application/x-desconhecido', 'dat', false],
]);

it('marca erro so nos documentos ausentes, bloqueados ou vazios, em qualquer ordem da resposta', function (array $ordem) {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 4, ['tentativas_download' => 1]));
    [$a, $b, $c, $d] = $documentos->all();
    $resposta = [
        'A' => documentoMniFake($a),
        'C' => documentoMniFake($c, ['conteudo' => NormalizarDocumentoMniService::MENSAGEM_BLOQUEIO]),
        'D' => documentoMniFake($d, ['conteudo' => '']),
    ];
    simularPaginasLote($this->consultarProcessos, [1 => array_map(fn ($letra) => $resposta[$letra], $ordem)]);

    executarLoteDownload($processo, $documentos);

    expectBaixadoNoLote($a, 1);
    expectErroNoLote($b, 1);
    expectErroNoLote($c, 1);
    expectErroNoLote($d, 1);

    Log::shouldHaveReceived('error')->times(3);
    errosLogadosPara($b, BaixarDocumentosEmLoteService::MOTIVO_NAO_RETORNADO);
    errosLogadosPara($c, NormalizarDocumentoMniService::MENSAGEM_BLOQUEIO);
    errosLogadosPara($d, BaixarDocumentosEmLoteService::MOTIVO_VAZIO);
})->with([
    'A, C, D' => [['A', 'C', 'D']],
    'D, C, A' => [['D', 'C', 'A']],
]);

it('marca erro so no documento cuja gravacao falha e segue com os demais', function () {
    [$processo, $documentos] = criarDocumentosLote(3);
    [$a, $b, $c] = $documentos->all();
    $salvar = $this->partialMock(SalvarDocumentoProcessoService::class);
    $salvar->shouldReceive('salvarConteudo')
        ->withArgs(fn (ProcessoDocumento $documento) => $documento->id === $b->id)
        ->andThrow(new RuntimeException('S3 indisponível'));
    $salvar->shouldReceive('salvarConteudo')->passthru();
    simularPaginasLote($this->consultarProcessos, [1 => fakesMniLote($documentos)]);

    executarLoteDownload($processo, $documentos);

    expectBaixadoNoLote($a, 0);
    expectErroNoLote($b, 0);
    expectBaixadoNoLote($c, 0);
    Log::shouldHaveReceived('error')->once();
    errosLogadosPara($b, 'S3 indisponível');
});

it('marca erro quando o objeto nao aparece no S3 depois da gravacao', function () {
    [$processo, $documentos] = criarDocumentosLote(1);
    $this->partialMock(SalvarDocumentoProcessoService::class)
        ->shouldReceive('salvarConteudo')
        ->andReturn('documentos-processos/nao-gravado/1.pdf');
    simularPaginasLote($this->consultarProcessos, [1 => fakesMniLote($documentos)]);

    executarLoteDownload($processo, $documentos);

    expectErroNoLote($documentos[0], 0);
    errosLogadosPara($documentos[0], 'ausente no S3');
});

it('ignora documentos que voltam na resposta sem terem sido pedidos', function () {
    [$processo, $documentos] = criarDocumentosLote(2);
    [, $foraDoLote] = criarDocumentosLote(1, $processo);
    $foraDoLote = $foraDoLote[0];
    $antes = $foraDoLote->fresh()->getAttributes();
    simularPaginasLote($this->consultarProcessos, [1 => [
        documentoMniFake($foraDoLote),
        documentoMniFake(99999999),
        ...fakesMniLote($documentos),
    ]]);

    executarLoteDownload($processo, $documentos);

    $documentos->each(fn ($documento) => expectBaixadoNoLote($documento, 0));
    expect($foraDoLote->fresh()->getAttributes())->toBe($antes);
    Storage::disk('s3')->assertMissing("documentos-processos/{$processo->numero_processo}/{$foraDoLote->id_documento}.pdf");
});

it('nao inclui no lote documento de outro processo', function () {
    [$processo, $documentos] = criarDocumentosLote(2);
    [, $deOutroProcesso] = criarDocumentosLote(1);
    $antes = $deOutroProcesso[0]->fresh()->getAttributes();
    $chamadas = simularPaginasLote($this->consultarProcessos, [1 => fakesMniLote($documentos)]);

    app(BaixarDocumentosEmLoteService::class)->executar(
        $processo->id,
        [...$documentos->pluck('id')->all(), $deOutroProcesso[0]->id],
    );

    expect($chamadas[0]['ids'])->toBe(idsDocumentoLote($documentos))
        ->and($deOutroProcesso[0]->fresh()->getAttributes())->toBe($antes);
});

it('envia as credenciais do lote em todas as paginas', function () {
    [$processo, $documentos] = criarDocumentosLote(4);
    $chamadas = simularPaginasLote($this->consultarProcessos, [
        1 => fakesMniLote($documentos->take(2)),
        2 => fakesMniLote($documentos->slice(2)),
    ]);

    executarLoteDownload($processo, $documentos, '{LOGIN}', '{SENHA}');

    expect($chamadas)->toHaveCount(2)
        ->and(array_column($chamadas->getArrayCopy(), 'login'))->toBe(['{LOGIN}', '{LOGIN}'])
        ->and(array_column($chamadas->getArrayCopy(), 'senha'))->toBe(['{SENHA}', '{SENHA}']);
});

it('deixa a operacao usar as credenciais do tribunal quando o lote nao tem credenciais', function () {
    [$processo, $documentos] = criarDocumentosLote(1);
    $chamadas = simularPaginasLote($this->consultarProcessos, [1 => fakesMniLote($documentos)]);

    executarLoteDownload($processo, $documentos);

    expect($chamadas[0]['login'])->toBeNull()
        ->and($chamadas[0]['senha'])->toBeNull();
});

it('registra warning e termina sem consultar o MNI quando o processo nao existe', function () {
    $this->consultarProcessos->shouldNotReceive('consultarPagina');
    $processoInexistente = (int) Processo::max('id') + 1000;

    app(BaixarDocumentosEmLoteService::class)->executar($processoInexistente, [1, 2]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($mensagem) => str_starts_with($mensagem, "[Processo:{$processoInexistente}]"))
        ->once();
    Log::shouldNotHaveReceived('error');
});

it('lanca MNIException quando o processo nao tem tribunal ativo', function (array $processo, array $tribunal) {
    [$processoCriado, $documentos] = criarDocumentosLote(1, $processo, $tribunal);
    $this->consultarProcessos->shouldNotReceive('consultarPagina');

    expect(fn () => executarLoteDownload($processoCriado, $documentos))
        ->toThrow(MNIException::class);

    expect($documentos[0]->fresh()->status)->toBe(ProcessoDocumento::STATUS_PENDENTE);
})->with([
    'tribunal inexistente' => [['tribunal_id' => 999999], []],
    'tribunal inativo' => [[], ['ativo' => false]],
]);
