<?php

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\MNIException;
use App\Models\Tribunal;
use App\Services\IntegracaoBase;
use App\Services\MNI\Intercomunicacao\ConsultarDocumentoService;
use Illuminate\Support\Facades\Crypt;

const URL_MNI_CONSULTAR_DOCUMENTO = 'https://mni.tribunal.exemplo/intercomunicacao?wsdl';
const URL_CONSULTAR_PROCESSO_CONSULTAR_DOCUMENTO = 'https://consulta.tribunal.exemplo/intercomunicacao?wsdl';
const MENSAGEM_BLOQUEIO_CONSULTAR_DOCUMENTO = 'Visualização do documento bloqueada enquanto não houver ciência de seus destinatários';

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
