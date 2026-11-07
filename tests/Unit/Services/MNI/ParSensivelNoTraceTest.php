<?php

use App\Models\Tribunal;
use App\Services\IntegracaoBase;
use App\Services\MNI\Intercomunicacao\ConsultarDocumentoService;
use App\Services\MNI\Intercomunicacao\ConsultarProcessoService;
use App\Services\Processo\EnfileirarDownloadDocumentosService;
use App\Services\Processo\ProcessoService;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

/*
 * RNF-01 (texto de exceção e trace): com `zend.exception_ignore_args = 0`,
 * getTraceAsString() mostra os argumentos de cada chamada. O par do PJe
 * só não aparece porque os parâmetros da cadeia MNI têm
 * #[\SensitiveParameter]. O login e a senha têm 15 caracteres, o limite em
 * que o PHP ainda mostra a string inteira no trace.
 */

uses(DatabaseTransactions::class);

const LOGIN_TRACE_PAR_SENSIVEL = 'login-trace-4c2';
const SENHA_TRACE_PAR_SENSIVEL = 'senha-trace-8d6';

beforeEach(function () {
    $this->ignoreArgsAntes = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    definirCredenciaisPadrao(null, null);
    Storage::fake('s3');

    [$processo, $documentos] = criarDocumentosLote(1);
    $this->processo = $processo;
    $this->documento = $documentos->first();
    $this->tribunal = Tribunal::find($processo->tribunal_id);

    $this->mni = simularIntegracaoMni(fn () => throw new SoapFault('Server', 'Falha SOAP simulada'));
});

afterEach(function () {
    ini_set('zend.exception_ignore_args', (string) $this->ignoreArgsAntes);
});

function excecaoDaChamadaParSensivel(Closure $chamada): ?Throwable
{
    try {
        $chamada();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * @return array<int, Throwable>
 */
function cadeiaDeExcecoesParSensivel(Throwable $excecao): array
{
    $cadeia = [];

    for ($atual = $excecao; $atual !== null; $atual = $atual->getPrevious()) {
        $cadeia[] = $atual;
    }

    return $cadeia;
}

function expectCadeiaSemParSensivel(?Throwable $excecao): void
{
    expect($excecao)->not->toBeNull();

    foreach (cadeiaDeExcecoesParSensivel($excecao) as $atual) {
        $rotulo = get_class($atual);

        expect($atual->getTraceAsString())->not->toContain(LOGIN_TRACE_PAR_SENSIVEL, "login no trace de {$rotulo}")
            ->and($atual->getTraceAsString())->not->toContain(SENHA_TRACE_PAR_SENSIVEL, "senha no trace de {$rotulo}")
            ->and($atual->getMessage())->not->toContain(LOGIN_TRACE_PAR_SENSIVEL)
            ->and($atual->getMessage())->not->toContain(SENHA_TRACE_PAR_SENSIVEL);
    }

    // __toString() junta mensagem e trace de toda a cadeia, como no log.
    expect((string) $excecao)->not->toContain(LOGIN_TRACE_PAR_SENSIVEL)
        ->and((string) $excecao)->not->toContain(SENHA_TRACE_PAR_SENSIVEL);
}

/**
 * O trace mostra o valor marcado como SensitiveParameterValue: prova que a
 * chamada com o par está nele, só que sem o valor.
 */
function expectTraceComParOcultado(Throwable $excecao, string $metodo): void
{
    $traces = implode("\n", array_map(
        fn (Throwable $atual) => $atual->getTraceAsString(),
        cadeiaDeExcecoesParSensivel($excecao),
    ));

    expect($traces)->toMatch('/' . preg_quote($metodo, '/') . '\([^\n]*Object\(SensitiveParameterValue\)/');
}

it('controle: sem o atributo, o trace mostra o par inteiro', function () {
    $semAtributo = function ($login, $senha) {
        throw new RuntimeException('falha');
    };

    $excecao = excecaoDaChamadaParSensivel(fn () => $semAtributo(LOGIN_TRACE_PAR_SENSIVEL, SENHA_TRACE_PAR_SENSIVEL));

    expect($excecao->getTraceAsString())->toContain(LOGIN_TRACE_PAR_SENSIVEL)
        ->and($excecao->getTraceAsString())->toContain(SENHA_TRACE_PAR_SENSIVEL);
});

it('ProcessoService::consultarNumero com SoapFault não leva o par ao trace de nenhuma exceção da cadeia', function () {
    $servico = app(ProcessoService::class);

    $excecao = excecaoDaChamadaParSensivel(fn () => $servico->consultarNumero(
        $this->tribunal,
        $this->processo->numero_processo,
        LOGIN_TRACE_PAR_SENSIVEL,
        SENHA_TRACE_PAR_SENSIVEL,
    ));

    expectCadeiaSemParSensivel($excecao);
    expectTraceComParOcultado($excecao, 'ProcessoService->consultarNumero');
    expectTraceComParOcultado($excecao, 'ConsultarProcessoService->execute');

    // O SOAP saiu com o par: o atributo não muda o comportamento.
    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and($this->mni->requisicoes[0]['parametros']['idConsultante'])->toBe(LOGIN_TRACE_PAR_SENSIVEL)
        ->and($this->mni->requisicoes[0]['parametros']['senhaConsultante'])->toBe(SENHA_TRACE_PAR_SENSIVEL);
});

it('baixarDocumento de um PDF pendente com SoapFault não leva o par ao trace de nenhuma exceção da cadeia', function () {
    $servico = app(SalvarDocumentoProcessoService::class);

    $excecao = excecaoDaChamadaParSensivel(fn () => $servico->baixarDocumento(
        $this->documento,
        LOGIN_TRACE_PAR_SENSIVEL,
        SENHA_TRACE_PAR_SENSIVEL,
    ));

    expect(cadeiaDeExcecoesParSensivel($excecao))->toHaveCount(2);
    expectCadeiaSemParSensivel($excecao);
    expectTraceComParOcultado($excecao, 'SalvarDocumentoProcessoService->baixarDocumento');
    expectTraceComParOcultado($excecao, 'SalvarDocumentoProcessoService->downloadPDF');
    expectTraceComParOcultado($excecao, 'SalvarDocumentoProcessoService->consultarDocumento');

    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and($this->mni->requisicoes[0]['parametros']['documento'])->toBe($this->documento->id_documento)
        ->and($this->mni->requisicoes[0]['parametros']['idConsultante'])->toBe(LOGIN_TRACE_PAR_SENSIVEL)
        ->and($this->mni->requisicoes[0]['parametros']['senhaConsultante'])->toBe(SENHA_TRACE_PAR_SENSIVEL);
});

it('o parâmetro com o par tem #[\SensitiveParameter]', function (string $classe, string $metodo, array $parametros) {
    $porNome = collect((new ReflectionMethod($classe, $metodo))->getParameters())
        ->keyBy(fn (ReflectionParameter $parametro) => $parametro->getName());

    foreach ($parametros as $nome) {
        expect($porNome->has($nome))->toBeTrue("{$classe}::{$metodo} sem o parâmetro \${$nome}")
            ->and($porNome->get($nome)?->getAttributes(SensitiveParameter::class))->toHaveCount(1, "{$classe}::{$metodo}(\${$nome})");
    }
})->with([
    'IntegracaoBase::makeSoapRequest' => [IntegracaoBase::class, 'makeSoapRequest', ['params']],
    'ConsultarProcessoService::execute' => [ConsultarProcessoService::class, 'execute', ['login_pje', 'senha_pje']],
    'ConsultarDocumentoService::execute' => [ConsultarDocumentoService::class, 'execute', ['login_pje', 'senha_pje']],
    'ConsultarDocumentoService::buscar' => [ConsultarDocumentoService::class, 'buscar', ['login', 'senha']],
    'ConsultarDocumentoService::parametros' => [ConsultarDocumentoService::class, 'parametros', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::execute' => [SalvarDocumentoProcessoService::class, 'execute', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::salvarDocumento' => [SalvarDocumentoProcessoService::class, 'salvarDocumento', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::baixarDocumento' => [SalvarDocumentoProcessoService::class, 'baixarDocumento', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::downloadHTML' => [SalvarDocumentoProcessoService::class, 'downloadHTML', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::downloadPDF' => [SalvarDocumentoProcessoService::class, 'downloadPDF', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::downloadOutros' => [SalvarDocumentoProcessoService::class, 'downloadOutros', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::downloadAudio' => [SalvarDocumentoProcessoService::class, 'downloadAudio', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::downloadMP4' => [SalvarDocumentoProcessoService::class, 'downloadMP4', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::downloadQuicktime' => [SalvarDocumentoProcessoService::class, 'downloadQuicktime', ['login_pje', 'senha_pje']],
    'SalvarDocumentoProcessoService::consultarDocumento' => [SalvarDocumentoProcessoService::class, 'consultarDocumento', ['login_pje', 'senha_pje']],
    'ProcessoService::consultarNumero' => [ProcessoService::class, 'consultarNumero', ['login', 'senha']],
    'ProcessoService::consultarDadosBasicos' => [ProcessoService::class, 'consultarDadosBasicos', ['login', 'senha']],
    'ProcessoService::consultarMovimentos' => [ProcessoService::class, 'consultarMovimentos', ['login', 'senha']],
    'ProcessoService::consultarDocumentos' => [ProcessoService::class, 'consultarDocumentos', ['login', 'senha']],
    'ProcessoService::getProcesso' => [ProcessoService::class, 'getProcesso', ['login', 'senha']],
    'EnfileirarDownloadDocumentosService::enfileirar' => [EnfileirarDownloadDocumentosService::class, 'enfileirar', ['login', 'senha']],
    'EnfileirarDownloadDocumentosService::despacharLote' => [EnfileirarDownloadDocumentosService::class, 'despacharLote', ['login', 'senha']],
]);
