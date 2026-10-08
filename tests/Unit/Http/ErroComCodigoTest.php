<?php

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
use App\Http\Responses\ErroComCodigo;

// Textos crus com tudo o que não pode vazar para o corpo: o trecho que
// classificou, o número do processo, credenciais e URL do tribunal.
const LOGIN_RECUSADO_ERRO_COM_CODIGO = 'Erro ao realizar login via MNI. exception invoking: loginFailed em https://pje.tjap.jus.br/mni?login_pje=LOGIN-DISTINTO-4471&senha_pje=SENHA-DISTINTA-8823';
const PROCESSO_NAO_ENCONTRADO_ERRO_COM_CODIGO = 'Processo de número 99999901120268030001 não encontrado!';

function recusaErroComCodigo(): CredencialPjeRecusadaException
{
    return new CredencialPjeRecusadaException(LOGIN_RECUSADO_ERRO_COM_CODIGO, 500);
}

function naoEncontradoErroComCodigo(): ProcessoNaoEncontradoNoTribunalException
{
    return new ProcessoNaoEncontradoNoTribunalException(PROCESSO_NAO_ENCONTRADO_ERRO_COM_CODIGO, 500);
}

function classificadaErroComCodigo(string $tipo): CredencialPjeRecusadaException|ProcessoNaoEncontradoNoTribunalException
{
    return match ($tipo) {
        'credencial recusada' => recusaErroComCodigo(),
        'processo não encontrado' => naoEncontradoErroComCodigo(),
    };
}

function falhaComumErroComCodigo(string $tipo): Throwable
{
    return match ($tipo) {
        'MNIException comum' => new MNIException(LOGIN_RECUSADO_ERRO_COM_CODIGO, 500),
        'ConsultaMNISemSucessoException' => new ConsultaMNISemSucessoException(PROCESSO_NAO_ENCONTRADO_ERRO_COM_CODIGO, 500),
        'RuntimeException' => new RuntimeException(LOGIN_RECUSADO_ERRO_COM_CODIGO),
        'Exception com previous comum' => new Exception('Erro ao processar documento PDF: ', 0, new MNIException('MNI indisponível', 500)),
    };
}

it('é uma classe final da camada HTTP', function () {
    $reflexao = new ReflectionClass(ErroComCodigo::class);

    expect($reflexao->isFinal())->toBeTrue()
        ->and($reflexao->getFileName())->toBe(app_path('Http/Responses/ErroComCodigo.php'));
});

it('resposta da credencial recusada é 403 com exatamente codigo e message fixos', function () {
    $resposta = ErroComCodigo::resposta(recusaErroComCodigo());

    expect($resposta->getStatusCode())->toBe(403)
        ->and($resposta->headers->get('Content-Type'))->toContain('application/json')
        ->and($resposta->getData(true))->toBe([
            'codigo' => 'credencial_pje_recusada',
            'message' => 'Credencial PJe recusada pelo tribunal',
        ]);
});

it('resposta do processo não encontrado é 404 com exatamente codigo e message fixos', function () {
    $resposta = ErroComCodigo::resposta(naoEncontradoErroComCodigo());

    expect($resposta->getStatusCode())->toBe(404)
        ->and($resposta->headers->get('Content-Type'))->toContain('application/json')
        ->and($resposta->getData(true))->toBe([
            'codigo' => 'processo_nao_encontrado',
            'message' => 'Processo não encontrado no tribunal',
        ]);
});

it('resposta não reproduz o texto do tribunal nem credenciais ou URL', function (string $tipo) {
    $resposta = ErroComCodigo::resposta(classificadaErroComCodigo($tipo));
    $corpos = [
        $resposta->getContent(),
        json_encode($resposta->getData(true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    foreach ($corpos as $corpo) {
        foreach (['loginFailed', 'Processo de número', 'login_pje=', 'senha_pje=', 'LOGIN-DISTINTO-4471', 'SENHA-DISTINTA-8823', 'http://', 'https://'] as $proibido) {
            expect($corpo)->not->toContain($proibido);
        }
    }
})->with(['credencial recusada', 'processo não encontrado']);

it('classificar devolve a credencial recusada só quando o par é do cliente', function () {
    $recusa = recusaErroComCodigo();

    expect(ErroComCodigo::classificar($recusa, true))->toBe($recusa)
        ->and(ErroComCodigo::classificar($recusa, false))->toBeNull();
});

it('classificar devolve o processo não encontrado com ou sem o par do cliente', function (bool $parDoCliente) {
    $naoEncontrado = naoEncontradoErroComCodigo();

    expect(ErroComCodigo::classificar($naoEncontrado, $parDoCliente))->toBe($naoEncontrado);
})->with([
    'par do cliente' => true,
    'par padrão ou credencial do tribunal' => false,
]);

it('classificar acha a exceção classificada como previous de uma Exception comum', function (string $tipo) {
    $original = classificadaErroComCodigo($tipo);
    $embrulhada = new Exception('Erro ao processar documento PDF: ', 0, $original);

    expect(ErroComCodigo::classificar($embrulhada, true))->toBe($original)
        ->and(ErroComCodigo::classificar(new RuntimeException('externa', 0, $embrulhada), true))->toBe($original);
})->with(['credencial recusada', 'processo não encontrado']);

it('classificar ignora a credencial recusada na cadeia quando o par não é do cliente', function () {
    $embrulhada = new Exception('Erro ao processar documento PDF: ', 0, recusaErroComCodigo());

    expect(ErroComCodigo::classificar($embrulhada, false))->toBeNull();
});

it('classificar devolve null para falhas não classificadas', function (string $tipo, bool $parDoCliente) {
    expect(ErroComCodigo::classificar(falhaComumErroComCodigo($tipo), $parDoCliente))->toBeNull();
})->with(['MNIException comum', 'ConsultaMNISemSucessoException', 'RuntimeException', 'Exception com previous comum'])->with([
    'par do cliente' => true,
    'sem par do cliente' => false,
]);
