<?php

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
use App\Models\Tribunal;
use App\Services\IntegracaoBase;
use App\Services\MNI\Intercomunicacao\ConsultarDocumentoService;
use Illuminate\Support\Facades\Crypt;

const URL_MNI_CONSULTAR_DOCUMENTO = 'https://mni.tribunal.exemplo/intercomunicacao?wsdl';
const URL_CONSULTAR_PROCESSO_CONSULTAR_DOCUMENTO = 'https://consulta.tribunal.exemplo/intercomunicacao?wsdl';
const MENSAGEM_BLOQUEIO_CONSULTAR_DOCUMENTO = 'Visualização do documento bloqueada enquanto não houver ciência de seus destinatários';
const LOGIN_RECUSADO_CONSULTAR_DOCUMENTO = 'Erro ao realizar login via MNI. exception invoking: loginFailed';
const FAULT_LOGIN_RECUSADO_CONSULTAR_DOCUMENTO = 'org.apache.cxf.interceptor.Fault: exception invoking: loginFailed';
const PROCESSO_NAO_ENCONTRADO_CONSULTAR_DOCUMENTO = 'Processo de número 99999901120268030001 não encontrado!';

function tribunalConsultarDocumento(array $atributos = []): Tribunal
{
    return Tribunal::factory()->make(array_merge([
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni' => URL_MNI_CONSULTAR_DOCUMENTO,
        'url_webservice_mni_consultar_processo' => URL_CONSULTAR_PROCESSO_CONSULTAR_DOCUMENTO,
    ], $atributos));
}

function documentoMniConsultarDocumento(int|string $id, array $extras = []): object
{
    return (object) array_merge([
        'idDocumento' => $id,
        'mimetype' => 'application/pdf',
        'conteudo' => '%PDF-cru-' . $id,
    ], $extras);
}

function respostaConsultarDocumento(mixed $documento): object
{
    return (object) [
        'sucesso' => true,
        'mensagem' => 'Processo consultado com sucesso',
        'processo' => (object) ['documento' => $documento],
    ];
}

/**
 * Troca o IntegracaoBase do container por um mock, guardando a URL usada na
 * criação e a operação/parâmetros de cada makeSoapRequest.
 */
function simularIntegracaoConsultarDocumento(object $chamadas, Closure $responder): void
{
    $chamadas->urls = [];
    $chamadas->requisicoes = [];

    app()->bind(IntegracaoBase::class, function ($app, $params) use ($chamadas, $responder) {
        $chamadas->urls[] = $params['url'] ?? null;

        $integracao = Mockery::mock(IntegracaoBase::class);
        $integracao->shouldReceive('makeSoapRequest')
            ->andReturnUsing(function (string $operacao, array $parametros) use ($chamadas, $responder) {
                $chamadas->requisicoes[] = ['operacao' => $operacao, 'parametros' => $parametros];

                return $responder($operacao, $parametros);
            });

        return $integracao;
    });
}

/**
 * O tribunal falha do jeito pedido: `sucesso = false` com a mensagem, ou
 * SoapFault com o texto (o fault CXF do PJe chega assim).
 */
function responderFalhaConsultarDocumento(string $forma, string $texto): Closure
{
    return match ($forma) {
        'sucesso false' => fn () => (object) ['sucesso' => false, 'mensagem' => $texto],
        'SoapFault' => fn () => throw new SoapFault('Server', $texto),
    };
}

function falhaConsultarDocumento(Closure $consulta): ?Throwable
{
    try {
        $consulta();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

beforeEach(function () {
    $this->chamadas = new stdClass();
});

// buscar ----------------------------------------------------------------------

it('buscar invoca consultarProcesso com um documento em url_webservice_mni e as credenciais informadas', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(documentoMniConsultarDocumento(4001)));

    (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001', '{LOGIN}', '{SENHA}');

    expect($this->chamadas->urls)->toBe([URL_MNI_CONSULTAR_DOCUMENTO])
        ->and($this->chamadas->requisicoes)->toHaveCount(1)
        ->and($this->chamadas->requisicoes[0]['operacao'])->toBe('consultarProcesso')
        ->and($this->chamadas->requisicoes[0]['parametros'])->toBe([
            'idConsultante' => '{LOGIN}',
            'senhaConsultante' => '{SENHA}',
            'numeroProcesso' => '0000001-02.2024.8.26.0001',
            'incluirCabecalho' => false,
            'incluirMovimentos' => false,
            'incluirDocumentos' => false,
            'documento' => '4001',
        ]);
});

it('buscar usa as credenciais do tribunal quando login e senha nao sao informados', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(documentoMniConsultarDocumento(4001)));

    (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001');

    expect($this->chamadas->requisicoes[0]['parametros']['idConsultante'])->toBe('login-do-tribunal')
        ->and($this->chamadas->requisicoes[0]['parametros']['senhaConsultante'])->toBe('{SENHA}');
});

it('buscar nao usa url_webservice_mni_consultar_processo', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(documentoMniConsultarDocumento(4001)));

    (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001');

    expect($this->chamadas->urls)->not->toContain(URL_CONSULTAR_PROCESSO_CONSULTAR_DOCUMENTO);
});

it('buscar devolve o documento casado cru, sem codificar o conteudo', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento([
        documentoMniConsultarDocumento(4000),
        documentoMniConsultarDocumento(4001),
    ]));

    $documento = (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001');

    expect($documento->idDocumento)->toBe(4001)
        ->and($documento->conteudo)->toBe('%PDF-cru-4001');
});

it('buscar devolve conteudo bloqueado sem lancar excecao, para o lote classificar', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(
        documentoMniConsultarDocumento(4001, ['conteudo' => MENSAGEM_BLOQUEIO_CONSULTAR_DOCUMENTO])
    ));

    $documento = (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001');

    expect($documento->conteudo)->toBe(MENSAGEM_BLOQUEIO_CONSULTAR_DOCUMENTO);
});

it('buscar encontra documento presente so como documentoVinculado', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(
        documentoMniConsultarDocumento(4000, [
            'documentoVinculado' => [
                documentoMniConsultarDocumento(4002),
                documentoMniConsultarDocumento(4003, ['mimetype' => 'text/html', 'conteudo' => '<p>vinculado</p>']),
            ],
        ])
    ));

    $documento = (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4003');

    expect($documento->idDocumento)->toBe(4003)
        ->and($documento->mimetype)->toBe('text/html')
        ->and($documento->conteudo)->toBe('<p>vinculado</p>');
});

it('buscar devolve null quando o documento nao vem na resposta', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(documentoMniConsultarDocumento(4000)));

    expect((new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001'))
        ->toBeNull();
});

it('buscar devolve null quando o processo vem sem documento', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => (object) [
        'sucesso' => true,
        'mensagem' => 'Processo consultado com sucesso',
        'processo' => (object) [],
    ]);

    expect((new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001'))
        ->toBeNull();
});

it('buscar casa o id como string e nao confunde zeros a esquerda', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento([
        documentoMniConsultarDocumento('04001'),
        documentoMniConsultarDocumento('4001', ['conteudo' => 'certo']),
    ]));

    $documento = (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001');

    expect($documento->conteudo)->toBe('certo');
});

it('buscar lanca ConsultaMNISemSucessoException quando o MNI responde sucesso false', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => (object) [
        'sucesso' => false,
        'mensagem' => 'Processo não encontrado',
    ]);

    $capturada = null;

    try {
        (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001');
    } catch (Throwable $e) {
        $capturada = $e;
    }

    expect($capturada)->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and($capturada->getError())->toBe('Processo não encontrado');
});

it('buscar padroniza a mensagem do sucesso false', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => (object) [
        'sucesso' => false,
        'mensagem' => 'loginFailed',
    ]);

    expect(fn () => (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001'))
        ->toThrow(fn (ConsultaMNISemSucessoException $e) => expect($e->getError())
            ->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.'));
});

it('buscar transforma SoapFault em MNIException nao especifica', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => throw new SoapFault('Server', 'Could not connect to host'));

    $capturada = null;

    try {
        (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001');
    } catch (Throwable $e) {
        $capturada = $e;
    }

    expect($capturada)->toBeInstanceOf(MNIException::class)
        ->and($capturada)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and($capturada->getError())->toBe('Could not connect to host');
});

it('buscar transforma erro de recibo do IntegracaoBase em MNIException nao especifica', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => throw new Exception('Recibo com erro', 99));

    expect(fn () => (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001'))
        ->toThrow(fn (MNIException $e) => expect($e)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class));
});

it('buscar transforma senha do tribunal indecifravel em MNIException 422 sem chamar o MNI', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(documentoMniConsultarDocumento(4001)));

    $capturada = null;

    try {
        (new ConsultarDocumentoService())->buscar(tribunalConsultarDocumento(['password' => 'texto-puro']), '0000001-02.2024.8.26.0001', '4001');
    } catch (Throwable $e) {
        $capturada = $e;
    }

    expect($capturada)->toBeInstanceOf(MNIException::class)
        ->and($capturada)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and($capturada->getCode())->toBe(422)
        ->and($this->chamadas->requisicoes)->toBe([]);
});

// execute (caracterização do fluxo síncrono) ------------------------------------

it('execute faz a mesma requisicao em url_webservice_mni', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(documentoMniConsultarDocumento(4001)));

    (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001, '{LOGIN}', '{SENHA}');

    expect($this->chamadas->urls)->toBe([URL_MNI_CONSULTAR_DOCUMENTO])
        ->and($this->chamadas->requisicoes[0]['operacao'])->toBe('consultarProcesso')
        ->and($this->chamadas->requisicoes[0]['parametros'])->toBe([
            'idConsultante' => '{LOGIN}',
            'senhaConsultante' => '{SENHA}',
            'numeroProcesso' => '0000001-02.2024.8.26.0001',
            'incluirCabecalho' => false,
            'incluirMovimentos' => false,
            'incluirDocumentos' => false,
            'documento' => 4001,
        ]);
});

it('execute usa as credenciais do tribunal quando login e senha nao sao informados', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(documentoMniConsultarDocumento(4001)));

    (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001);

    expect($this->chamadas->requisicoes[0]['parametros']['idConsultante'])->toBe('login-do-tribunal')
        ->and($this->chamadas->requisicoes[0]['parametros']['senhaConsultante'])->toBe('{SENHA}');
});

it('execute codifica em base64 o PDF cru', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(documentoMniConsultarDocumento(4001)));

    $documento = (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001);

    expect($documento->conteudo)->toBe(base64_encode('%PDF-cru-4001'));
});

it('execute mantem inalterado audio/mpeg que ja vem em base64', function () {
    $base64 = base64_encode('ID3-audio');
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(
        documentoMniConsultarDocumento(4001, ['mimetype' => 'audio/mpeg', 'conteudo' => $base64])
    ));

    $documento = (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001);

    expect($documento->conteudo)->toBe($base64);
});

it('execute codifica audio/mpeg cru', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(
        documentoMniConsultarDocumento(4001, ['mimetype' => 'audio/mpeg', 'conteudo' => "\xFF\xFB\x90binario"])
    ));

    $documento = (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001);

    expect($documento->conteudo)->toBe(base64_encode("\xFF\xFB\x90binario"));
});

it('execute codifica text/html', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(
        documentoMniConsultarDocumento(4001, ['mimetype' => 'text/html', 'conteudo' => '<p>despacho</p>'])
    ));

    $documento = (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001);

    expect($documento->conteudo)->toBe(base64_encode('<p>despacho</p>'));
});

it('execute encontra e codifica documento vinculado', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(
        documentoMniConsultarDocumento(4000, ['documentoVinculado' => documentoMniConsultarDocumento(4002)])
    ));

    $documento = (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4002);

    expect($documento->idDocumento)->toBe(4002)
        ->and($documento->conteudo)->toBe(base64_encode('%PDF-cru-4002'));
});

it('execute lanca MNIException com o texto de bloqueio', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => respostaConsultarDocumento(
        documentoMniConsultarDocumento(4001, ['conteudo' => MENSAGEM_BLOQUEIO_CONSULTAR_DOCUMENTO])
    ));

    expect(fn () => (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001))
        ->toThrow(fn (MNIException $e) => expect($e->getError())->toBe(MENSAGEM_BLOQUEIO_CONSULTAR_DOCUMENTO)
            ->and($e->getCode())->toBe(500));
});

it('execute lanca MNIException comum com a mensagem do sucesso false', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => (object) [
        'sucesso' => false,
        'mensagem' => 'Processo não encontrado',
    ]);

    expect(fn () => (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001))
        ->toThrow(fn (MNIException $e) => expect($e)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class)
            ->and($e->getError())->toBe('Processo não encontrado')
            ->and($e->getCode())->toBe(500));
});

it('execute lanca MNIException quando o processo vem sem documento', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => (object) [
        'sucesso' => true,
        'processo' => (object) [],
    ]);

    expect(fn () => (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001))
        ->toThrow(fn (MNIException $e) => expect($e->getError())->toBe('Documento não encontrado neste processo')
            ->and($e->getCode())->toBe(500));
});

it('execute transforma SoapFault em MNIException', function () {
    simularIntegracaoConsultarDocumento($this->chamadas, fn () => throw new SoapFault('Server', 'Could not connect to host'));

    expect(fn () => (new ConsultarDocumentoService())->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001))
        ->toThrow(fn (MNIException $e) => expect($e->getError())->toBe('Could not connect to host')
            ->and($e->getCode())->toBe(500));
});

// execute (classificação da falha) ----------------------------------------------

it('execute lança CredencialPjeRecusadaException 500 quando o tribunal recusa o par informado', function (string $forma, string $texto) {
    simularIntegracaoConsultarDocumento($this->chamadas, responderFalhaConsultarDocumento($forma, $texto));

    $capturada = falhaConsultarDocumento(fn () => (new ConsultarDocumentoService())
        ->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001, '{LOGIN}', '{SENHA}'));

    expect($capturada)->toBeInstanceOf(CredencialPjeRecusadaException::class)
        ->and($capturada->getError())->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.')
        ->and($capturada->getCode())->toBe(500)
        ->and($this->chamadas->requisicoes)->toHaveCount(1);
})->with([
    'sucesso false' => ['sucesso false', LOGIN_RECUSADO_CONSULTAR_DOCUMENTO],
    'fault CXF' => ['SoapFault', FAULT_LOGIN_RECUSADO_CONSULTAR_DOCUMENTO],
]);

// Sem o par a chamada usa a credencial do tribunal: a recusa não é do
// consultante e segue como falha comum.
it('execute lança MNIException comum quando o tribunal recusa a credencial do tribunal', function (string $forma, string $texto) {
    simularIntegracaoConsultarDocumento($this->chamadas, responderFalhaConsultarDocumento($forma, $texto));

    $capturada = falhaConsultarDocumento(fn () => (new ConsultarDocumentoService())
        ->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001));

    expect(get_class($capturada))->toBe(MNIException::class)
        ->and($capturada->getError())->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.')
        ->and($capturada->getCode())->toBe(500)
        ->and($this->chamadas->requisicoes)->toHaveCount(1)
        ->and($this->chamadas->requisicoes[0]['parametros']['idConsultante'])->toBe('login-do-tribunal');
})->with([
    'sucesso false' => ['sucesso false', LOGIN_RECUSADO_CONSULTAR_DOCUMENTO],
    'fault CXF' => ['SoapFault', FAULT_LOGIN_RECUSADO_CONSULTAR_DOCUMENTO],
]);

it('execute lança ProcessoNaoEncontradoNoTribunalException 500 para o texto capturado do tribunal', function (?string $login, ?string $senha) {
    simularIntegracaoConsultarDocumento($this->chamadas, responderFalhaConsultarDocumento('sucesso false', PROCESSO_NAO_ENCONTRADO_CONSULTAR_DOCUMENTO));

    $capturada = falhaConsultarDocumento(fn () => (new ConsultarDocumentoService())
        ->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001, $login, $senha));

    expect($capturada)->toBeInstanceOf(ProcessoNaoEncontradoNoTribunalException::class)
        ->and($capturada->getError())->toBe(PROCESSO_NAO_ENCONTRADO_CONSULTAR_DOCUMENTO)
        ->and($capturada->getCode())->toBe(500)
        ->and($this->chamadas->requisicoes)->toHaveCount(1);
})->with([
    'com o par informado' => ['{LOGIN}', '{SENHA}'],
    'com a credencial do tribunal' => [null, null],
]);

it('execute lança MNIException comum para falha fora dos padrões classificados', function (string $forma, string $texto) {
    simularIntegracaoConsultarDocumento($this->chamadas, responderFalhaConsultarDocumento($forma, $texto));

    $capturada = falhaConsultarDocumento(fn () => (new ConsultarDocumentoService())
        ->execute(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', 4001, '{LOGIN}', '{SENHA}'));

    expect(get_class($capturada))->toBe(MNIException::class)
        ->and($capturada->getCode())->toBe(500)
        ->and($this->chamadas->requisicoes)->toHaveCount(1);
})->with([
    'recusa sem loginfailed' => ['sucesso false', 'Erro ao realizar login via MNI.'],
    'sem permissão de acesso' => ['sucesso false', 'Processo não encontrado ou sem permissão de acesso'],
    'sem exclamação' => ['sucesso false', 'Processo de número 99999901120268030001 não encontrado'],
    'com prefixo' => ['sucesso false', 'Erro: Processo de número 99999901120268030001 não encontrado!'],
    'SoapFault com o texto de não encontrado' => ['SoapFault', PROCESSO_NAO_ENCONTRADO_CONSULTAR_DOCUMENTO],
]);

// buscar (lote) não classifica: o fallback unitário depende de
// ConsultaMNISemSucessoException para todo `sucesso = false`.
it('buscar continua lançando ConsultaMNISemSucessoException para os textos classificados por execute', function (string $texto) {
    simularIntegracaoConsultarDocumento($this->chamadas, responderFalhaConsultarDocumento('sucesso false', $texto));

    $capturada = falhaConsultarDocumento(fn () => (new ConsultarDocumentoService())
        ->buscar(tribunalConsultarDocumento(), '0000001-02.2024.8.26.0001', '4001', '{LOGIN}', '{SENHA}'));

    expect(get_class($capturada))->toBe(ConsultaMNISemSucessoException::class)
        ->and($capturada->getCode())->toBe(500)
        ->and($this->chamadas->requisicoes)->toHaveCount(1);
})->with([
    'loginFailed' => LOGIN_RECUSADO_CONSULTAR_DOCUMENTO,
    'processo não encontrado' => PROCESSO_NAO_ENCONTRADO_CONSULTAR_DOCUMENTO,
]);
