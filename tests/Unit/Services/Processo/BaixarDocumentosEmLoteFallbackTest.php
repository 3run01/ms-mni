<?php

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\MNIException;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\MNI\Intercomunicacao\ConsultarDocumentoService;
use App\Services\MNI\Intercomunicacao\ConsultarProcessosService;
use App\Services\MNI\NormalizarDocumentoMniService;
use App\Services\Processo\BaixarDocumentosEmLoteService;
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
    $this->consultarDocumento = $this->mock(ConsultarDocumentoService::class);
});

/**
 * Responde consultarPagina com `$paginas[n]`: lista de documentos MNI crus,
 * um Throwable (lançado) ou ausente (página vazia). Registra cada chamada.
 */
function simularPaginasFallback(MockInterface $consultarProcessos, array $paginas): ArrayObject
{
    $chamadas = new ArrayObject();

    $consultarProcessos->shouldReceive('consultarPagina')->andReturnUsing(
        function (Tribunal $tribunal, string $numeroProcesso, array $ids, int $pagina, int $tamanhoPagina, ?string $login = null, ?string $senha = null) use ($chamadas, $paginas) {
            $chamadas[] = compact('pagina', 'ids', 'login', 'senha');
            $resposta = $paginas[$pagina] ?? [];

            if ($resposta instanceof Throwable) {
                throw $resposta;
            }

            return NormalizarDocumentoMniService::indexarPorId($resposta);
        }
    );

    return $chamadas;
}

/**
 * Responde buscar por `id_documento`: documento MNI cru, null (ausente) ou um
 * Throwable (lançado). Registra cada chamada, na ordem.
 */
function simularBuscasFallback(MockInterface $consultarDocumento, array $respostas): ArrayObject
{
    $chamadas = new ArrayObject();

    $consultarDocumento->shouldReceive('buscar')->andReturnUsing(
        function (Tribunal $tribunal, string $numeroProcesso, string $idDocumento, ?string $login = null, ?string $senha = null) use ($chamadas, $respostas) {
            $chamadas[] = compact('tribunal', 'numeroProcesso', 'idDocumento', 'login', 'senha');
            $resposta = $respostas[$idDocumento] ?? null;

            if ($resposta instanceof Throwable) {
                throw $resposta;
            }

            return $resposta;
        }
    );

    return $chamadas;
}

/**
 * Mapa id_documento → resposta unitária com conteúdo; `$sobrescritas` troca
 * a resposta de documentos específicos (por id_documento).
 */
function respostasUnitariasFallback(Collection|array $documentos, array $sobrescritas = []): array
{
    $respostas = [];

    foreach ($documentos as $documento) {
        $respostas[(string) $documento->id_documento] = documentoMniFake($documento);
    }

    foreach ($sobrescritas as $idDocumento => $resposta) {
        $respostas[(string) $idDocumento] = $resposta;
    }

    return $respostas;
}

function paginaComFallback(Collection|array $documentos): array
{
    return collect($documentos)->map(fn (ProcessoDocumento $documento) => documentoMniFake($documento))->all();
}

function executarFallbackLote(Processo $processo, Collection|array $documentos, ?string $login = null, ?string $senha = null): void
{
    app(BaixarDocumentosEmLoteService::class)->executar(
        $processo->id,
        collect($documentos)->pluck('id')->all(),
        $login,
        $senha,
    );
}

function idsDocumentoFallback(Collection|array $documentos): array
{
    return collect($documentos)->map(fn (ProcessoDocumento $documento) => (string) $documento->id_documento)->values()->all();
}

function idsBuscados(ArrayObject $buscas): array
{
    return array_column($buscas->getArrayCopy(), 'idDocumento');
}

function semSucessoNoMni(string $mensagem = 'Processo indisponível para consulta'): ConsultaMNISemSucessoException
{
    return new ConsultaMNISemSucessoException($mensagem, 500);
}

function falhaDeTransporteNoMni(string $mensagem = 'Could not connect to host'): MNIException
{
    return new MNIException($mensagem, 500);
}

function expectBaixadoNoFallback(ProcessoDocumento $documento, int $tentativasAntes): void
{
    $documento->refresh();

    expect($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($documento->path)->not->toBeEmpty()
        ->and($documento->downloaded_at)->not->toBeNull()
        ->and($documento->tentativas_download)->toBe($tentativasAntes + 1);

    Storage::disk('s3')->assertExists($documento->path);
}

function expectErroNoFallback(ProcessoDocumento $documento, int $tentativasAntes): void
{
    $documento->refresh();

    expect($documento->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($documento->tentativas_download)->toBe($tentativasAntes + 1);
}

function umLogDeErroPara(ProcessoDocumento $documento, ?string $motivo = null): void
{
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($mensagem, $contexto = []) => ($contexto['id_documento'] ?? null) === $documento->id_documento
            && ($motivo === null || str_contains($contexto['motivo'] ?? '', $motivo)))
        ->once();
}

// RF-08 (a): fallback unitário

it('usa consulta unitaria para todos os elegiveis quando o WSDL nao tem consultarProcessos', function () {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 3, ['tentativas_download' => 1]));
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->once()->andReturn(false);
    $this->consultarProcessos->shouldNotReceive('consultarPagina');
    $buscas = simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos));

    executarFallbackLote($processo, $documentos);

    expect(idsBuscados($buscas))->toBe(idsDocumentoFallback($documentos));

    foreach ($buscas as $busca) {
        expect($busca['tribunal']->id)->toBe($processo->tribunal_id)
            ->and($busca['numeroProcesso'])->toBe($processo->numero_processo);
    }

    $documentos->each(fn ($documento) => expectBaixadoNoFallback($documento, 1));
    Log::shouldNotHaveReceived('error');
});

it('cai na consulta unitaria de todos quando a pagina 1 volta sem sucesso', function () {
    [$processo, $documentos] = criarDocumentosLote(3);
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);
    $paginas = simularPaginasFallback($this->consultarProcessos, [1 => semSucessoNoMni()]);
    $buscas = simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos));

    executarFallbackLote($processo, $documentos);

    expect($paginas)->toHaveCount(1)
        ->and(idsBuscados($buscas))->toBe(idsDocumentoFallback($documentos));
    $documentos->each(fn ($documento) => expectBaixadoNoFallback($documento, 0));
    Log::shouldNotHaveReceived('error');
});

it('consulta um a um so os ids que nao voltaram quando a pagina 2 volta sem sucesso', function () {
    [$processo, $documentos] = criarDocumentosLote(20);
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);
    $paginas = simularPaginasFallback($this->consultarProcessos, [
        1 => paginaComFallback($documentos->take(12)),
        2 => semSucessoNoMni(),
    ]);
    $buscas = simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos->slice(12)));

    executarFallbackLote($processo, $documentos);

    expect(array_column($paginas->getArrayCopy(), 'pagina'))->toBe([1, 2])
        ->and(idsBuscados($buscas))->toBe(idsDocumentoFallback($documentos->slice(12)));
    $documentos->each(fn ($documento) => expectBaixadoNoFallback($documento, 0));
});

it('marca erro so no documento cuja consulta unitaria volta sem sucesso e segue com os demais', function () {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 3, ['tentativas_download' => 2]));
    [$a, $b, $c] = $documentos->all();
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(false);
    $buscas = simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos, [
        $b->id_documento => semSucessoNoMni('Documento indisponível no tribunal'),
    ]));

    executarFallbackLote($processo, $documentos);

    expect($buscas)->toHaveCount(3);
    expectBaixadoNoFallback($a, 2);
    expectErroNoFallback($b, 2);
    expectBaixadoNoFallback($c, 2);
    Log::shouldHaveReceived('error')->once();
    umLogDeErroPara($b, 'Documento indisponível no tribunal');
});

it('nao pede no fallback o documento ja marcado erro numa pagina anterior', function () {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 20, ['tentativas_download' => 1]));
    $vistos = $documentos->take(12);
    $d = $vistos[11];
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);
    $paginas = simularPaginasFallback($this->consultarProcessos, [
        1 => [...paginaComFallback($vistos->take(11)), documentoMniFake($d, ['conteudo' => ''])],
        2 => semSucessoNoMni(),
    ]);
    $buscas = simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos->slice(12)));

    executarFallbackLote($processo, $documentos);

    expect($paginas)->toHaveCount(2)
        ->and(idsBuscados($buscas))->toBe(idsDocumentoFallback($documentos->slice(12)))
        ->and(idsBuscados($buscas))->not->toContain((string) $d->id_documento);
    $vistos->take(11)->each(fn ($documento) => expectBaixadoNoFallback($documento, 1));
    $documentos->slice(12)->each(fn ($documento) => expectBaixadoNoFallback($documento, 1));
    expectErroNoFallback($d, 1);
    Log::shouldHaveReceived('error')->once();
    umLogDeErroPara($d, BaixarDocumentosEmLoteService::MOTIVO_VAZIO);
});

it('trata conteudo vazio, bloqueado ou ausente na consulta unitaria como erro so do documento', function () {
    [$processo, $documentos] = criarDocumentosLote(4);
    [$a, $b, $c, $d] = $documentos->all();
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(false);
    simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos, [
        $b->id_documento => documentoMniFake($b, ['conteudo' => '']),
        $c->id_documento => documentoMniFake($c, ['conteudo' => NormalizarDocumentoMniService::MENSAGEM_BLOQUEIO]),
        $d->id_documento => null,
    ]));

    executarFallbackLote($processo, $documentos);

    expectBaixadoNoFallback($a, 0);
    expectErroNoFallback($b, 0);
    expectErroNoFallback($c, 0);
    expectErroNoFallback($d, 0);
    Log::shouldHaveReceived('error')->times(3);
    umLogDeErroPara($b, BaixarDocumentosEmLoteService::MOTIVO_VAZIO);
    umLogDeErroPara($c, NormalizarDocumentoMniService::MENSAGEM_BLOQUEIO);
    umLogDeErroPara($d, BaixarDocumentosEmLoteService::MOTIVO_NAO_RETORNADO_UNITARIO);
});

// RF-08 (b): falha total

it('marca erro em todo o lote e lanca excecao quando a pagina 1 lanca excecao', function () {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 20, ['tentativas_download' => 1]));
    $falha = falhaDeTransporteNoMni();
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);
    $paginas = simularPaginasFallback($this->consultarProcessos, [1 => $falha]);
    $this->consultarDocumento->shouldNotReceive('buscar');

    expect(fn () => executarFallbackLote($processo, $documentos))->toThrow(MNIException::class);

    expect($paginas)->toHaveCount(1);
    $documentos->each(fn ($documento) => expectErroNoFallback($documento, 1));
    Log::shouldHaveReceived('error')->times(20);
    $documentos->each(fn ($documento) => umLogDeErroPara($documento, $falha->getError()));
});

it('mantem baixados os ids da pagina 1 e marca erro so nos restantes quando a pagina 2 lanca excecao', function () {
    [$processo, $documentos] = criarDocumentosLote(20);
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);
    $paginas = simularPaginasFallback($this->consultarProcessos, [
        1 => paginaComFallback($documentos->take(12)),
        2 => falhaDeTransporteNoMni(),
    ]);
    $this->consultarDocumento->shouldNotReceive('buscar');

    expect(fn () => executarFallbackLote($processo, $documentos))->toThrow(MNIException::class);

    expect($paginas)->toHaveCount(2);
    $documentos->take(12)->each(fn ($documento) => expectBaixadoNoFallback($documento, 0));
    $documentos->slice(12)->each(fn ($documento) => expectErroNoFallback($documento, 0));
    Log::shouldHaveReceived('error')->times(8);
});

it('encerra o fallback na excecao da consulta unitaria e marca erro nela e nos ainda nao pedidos', function () {
    [$processo, $documentos] = criarDocumentosLote(3);
    [$a, $b, $c] = $documentos->all();
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(false);
    $buscas = simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos, [
        $b->id_documento => falhaDeTransporteNoMni(),
    ]));

    expect(fn () => executarFallbackLote($processo, $documentos))->toThrow(MNIException::class);

    expect(idsBuscados($buscas))->toBe(idsDocumentoFallback([$a, $b]));
    expectBaixadoNoFallback($a, 0);
    expectErroNoFallback($b, 0);
    expectErroNoFallback($c, 0);
    Log::shouldHaveReceived('error')->times(2);
    umLogDeErroPara($b);
    umLogDeErroPara($c);
});

it('nao soma +1 nem log de novo no documento ja marcado erro pela consulta unitaria antes da falha total', function () {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 3, ['tentativas_download' => 4]));
    [$a, $b, $c] = $documentos->all();
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(false);
    $buscas = simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos, [
        $a->id_documento => semSucessoNoMni('Documento indisponível no tribunal'),
        $b->id_documento => falhaDeTransporteNoMni(),
    ]));

    expect(fn () => executarFallbackLote($processo, $documentos))->toThrow(MNIException::class);

    expect(idsBuscados($buscas))->toBe(idsDocumentoFallback([$a, $b]));
    expectErroNoFallback($a, 4);
    expectErroNoFallback($b, 4);
    expectErroNoFallback($c, 4);
    Log::shouldHaveReceived('error')->times(3);
    umLogDeErroPara($a, 'Documento indisponível no tribunal');
    umLogDeErroPara($b, 'Could not connect to host');
    umLogDeErroPara($c, 'Could not connect to host');
});

it('nao soma +1 nem log de novo no documento vazio da pagina 1 quando a pagina 2 lanca excecao', function () {
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, 20, ['tentativas_download' => 3]));
    $vistos = $documentos->take(12);
    $d = $vistos[11];
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);
    simularPaginasFallback($this->consultarProcessos, [
        1 => [...paginaComFallback($vistos->take(11)), documentoMniFake($d, ['conteudo' => ''])],
        2 => falhaDeTransporteNoMni(),
    ]);
    $this->consultarDocumento->shouldNotReceive('buscar');

    expect(fn () => executarFallbackLote($processo, $documentos))->toThrow(MNIException::class);

    $vistos->take(11)->each(fn ($documento) => expectBaixadoNoFallback($documento, 3));
    expectErroNoFallback($d, 3);
    $documentos->slice(12)->each(fn ($documento) => expectErroNoFallback($documento, 3));
    Log::shouldHaveReceived('error')->times(9);
    umLogDeErroPara($d, BaixarDocumentosEmLoteService::MOTIVO_VAZIO);
    $documentos->slice(12)->each(fn ($documento) => umLogDeErroPara($documento, 'Could not connect to host'));
});

it('trata excecao na verificacao da operacao como falha total sem nenhuma consulta', function () {
    [$processo, $documentos] = criarDocumentosLote(2);
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')
        ->once()
        ->andThrow(falhaDeTransporteNoMni('SOAP-ERROR: Parsing WSDL'));
    $this->consultarProcessos->shouldNotReceive('consultarPagina');
    $this->consultarDocumento->shouldNotReceive('buscar');

    expect(fn () => executarFallbackLote($processo, $documentos))
        ->toThrow(MNIException::class);

    $documentos->each(fn ($documento) => expectErroNoFallback($documento, 0));
    $documentos->each(fn ($documento) => umLogDeErroPara($documento, 'SOAP-ERROR: Parsing WSDL'));
});

it('relanca a propria MNIException da falha total sem log de erro agregado do lote', function () {
    [$processo, $documentos] = criarDocumentosLote(2);
    $falha = falhaDeTransporteNoMni('Erro ao realizar login via MNI. exception invoking: loginFailed');
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);
    simularPaginasFallback($this->consultarProcessos, [1 => $falha]);
    $lancada = null;

    try {
        executarFallbackLote($processo, $documentos);
    } catch (MNIException $e) {
        $lancada = $e;
    }

    expect($lancada)->toBe($falha);
    // Só os 2 logs por documento, com a mensagem já padronizada.
    Log::shouldHaveReceived('error')->times(2);
    $documentos->each(fn ($documento) => umLogDeErroPara($documento, 'Erro ao realizar login via MNI. Verifique suas credenciais.'));
});

// RF-11 no fallback

it('envia as credenciais do lote em todas as consultas unitarias', function () {
    [$processo, $documentos] = criarDocumentosLote(3);
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(true);
    $paginas = simularPaginasFallback($this->consultarProcessos, [1 => semSucessoNoMni()]);
    $buscas = simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos));

    executarFallbackLote($processo, $documentos, '{LOGIN}', '{SENHA}');

    expect($paginas[0]['login'])->toBe('{LOGIN}')
        ->and(array_column($buscas->getArrayCopy(), 'login'))->toBe(['{LOGIN}', '{LOGIN}', '{LOGIN}'])
        ->and(array_column($buscas->getArrayCopy(), 'senha'))->toBe(['{SENHA}', '{SENHA}', '{SENHA}']);
});

it('deixa a consulta unitaria usar as credenciais do tribunal quando o lote nao tem credenciais', function () {
    [$processo, $documentos] = criarDocumentosLote(1);
    $this->consultarProcessos->shouldReceive('operacaoDisponivel')->andReturn(false);
    $buscas = simularBuscasFallback($this->consultarDocumento, respostasUnitariasFallback($documentos));

    executarFallbackLote($processo, $documentos);

    expect($buscas[0]['login'])->toBeNull()
        ->and($buscas[0]['senha'])->toBeNull();
});
