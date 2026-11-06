<?php

use App\Models\Tribunal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

// Matriz da recusa do par (RF-01, CT-06): o tribunal recusa o par nas três
// formas cruas que chegam ao ms-mni, em cada caminho de /processo/visualizar e
// /documento/visualizar que consulta o MNI. O par do cliente vira 403 com
// `codigo`; o par padrão do .env segue no 500 sem `codigo`, porque a recusa
// dele não é culpa da credencial do usuário. O HTML legado fica em
// DocumentoControllerTest.

uses(DatabaseTransactions::class);

const TEXTO_RECUSA_DO_PAR = 'Erro ao realizar login via MNI. exception invoking: loginFailed';
const LOGIN_CLIENTE_RECUSA_DO_PAR = 'LOGIN-CLIENTE-6127';
const SENHA_CLIENTE_RECUSA_DO_PAR = 'SENHA-CLIENTE-3854';
const LOGIN_PADRAO_RECUSA_DO_PAR = 'LOGIN-PADRAO-2290';
const SENHA_PADRAO_RECUSA_DO_PAR = 'SENHA-PADRAO-7413';
const LOGIN_TRIBUNAL_RECUSA_DO_PAR = 'LOGIN-TRIBUNAL-9046';
const SENHA_TRIBUNAL_RECUSA_DO_PAR = 'SENHA-TRIBUNAL-1578';

beforeEach(function () {
    criarTokenApi();
    // isola do .env da máquina: cada teste declara o par padrão que quer
    definirCredenciaisPadrao(null, null);
    // o processo novo grava os documentos e enfileira o download em lote
    Queue::fake();
    Storage::fake('s3');
});

/**
 * Responder do SOAP simulado para cada forma crua da recusa. Fica numa
 * função, e não no dataset, porque o Pest executa closures do dataset antes
 * do teste, e as formas que lançam estourariam ali.
 */
function responderRecusaDoPar(string $forma): Closure
{
    return match ($forma) {
        'sucesso_false' => fn () => (object) ['sucesso' => false, 'mensagem' => TEXTO_RECUSA_DO_PAR],
        // como o recibo `sucesso = false` que IntegracaoBase::makeSoapRequest lança
        'excecao_do_recibo' => fn () => throw new Exception(TEXTO_RECUSA_DO_PAR, 0),
        'soap_fault' => fn () => throw new SoapFault('Server', TEXTO_RECUSA_DO_PAR),
    };
}

/**
 * Monta o estado do caminho e devolve a URL sem o par e o `documento` que a
 * requisição SOAP deve levar (null quando é a consulta do processo).
 *
 * @return array{0: string, 1: int|null}
 */
function cenarioRecusaDoPar(string $caminho): array
{
    // CNJ válido no formato e inexistente no banco de teste
    $numeroAusente = '99999611220268030001';

    if ($caminho === 'processo_ausente') {
        $tribunal = tribunalRecusaDoPar();

        return ['/api/processo/visualizar?' . http_build_query([
            'tribunal_id' => $tribunal->id,
            'numero_processo' => $numeroAusente,
        ]), null];
    }

    if ($caminho === 'documento_ausente') {
        $tribunal = tribunalRecusaDoPar();

        return ['/api/documento/visualizar?' . http_build_query([
            'tribunal_id' => $tribunal->id,
            'numero_processo' => $numeroAusente,
            'id_documento' => 961001,
        ]), null];
    }

    $mimetype = match ($caminho) {
        'pdf_pendente' => 'application/pdf',
        'html_pendente' => 'text/html',
    };

    [$processo, $documentos] = criarDocumentosLote([['mimetype' => $mimetype]], tribunal: [
        'login' => LOGIN_TRIBUNAL_RECUSA_DO_PAR,
        'password' => Crypt::encrypt(SENHA_TRIBUNAL_RECUSA_DO_PAR),
    ]);
    $documento = $documentos->first();

    return ['/api/documento/visualizar?' . http_build_query([
        'tribunal_id' => $processo->tribunal_id,
        'numero_processo' => $processo->numero_processo,
        'id_documento' => $documento->id_documento,
    ]), $documento->id_documento];
}

function tribunalRecusaDoPar(): Tribunal
{
    return Tribunal::factory()->create([
        'login' => LOGIN_TRIBUNAL_RECUSA_DO_PAR,
        'password' => Crypt::encrypt(SENHA_TRIBUNAL_RECUSA_DO_PAR),
        'ativo' => true,
    ]);
}

function parDoClienteRecusaDoPar(): string
{
    return '&' . http_build_query([
        'login_pje' => LOGIN_CLIENTE_RECUSA_DO_PAR,
        'senha_pje' => SENHA_CLIENTE_RECUSA_DO_PAR,
    ]);
}

/**
 * Exatamente 1 chamada SOAP, com o par esperado e no alvo do caminho: a
 * consulta do processo (sem `documento`) ou a do documento pendente.
 */
function expectUmaRequisicaoSoapRecusaDoPar(stdClass $simulador, string $login, string $senha, ?int $documento): void
{
    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe($login)
        ->and($simulador->requisicoes[0]['parametros']['senhaConsultante'])->toBe($senha);

    if ($documento === null) {
        expect($simulador->requisicoes[0]['parametros'])->not->toHaveKey('documento');
    } else {
        expect($simulador->requisicoes[0]['parametros']['documento'])->toBe($documento);
    }
}

/**
 * Confere o corpo cru e o decodificado: o JSON escapa `/` e acentos, então
 * parte do texto só aparece depois de decodificar.
 */
function expectCorpoSemSigiloRecusaDoPar(TestResponse $response): void
{
    $response->assertJsonMissingPath('error')
        ->assertJsonMissingPath('line')
        ->assertJsonMissingPath('file');

    $corpos = [
        $response->getContent(),
        json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $proibidos = [
        LOGIN_CLIENTE_RECUSA_DO_PAR,
        SENHA_CLIENTE_RECUSA_DO_PAR,
        LOGIN_PADRAO_RECUSA_DO_PAR,
        SENHA_PADRAO_RECUSA_DO_PAR,
        LOGIN_TRIBUNAL_RECUSA_DO_PAR,
        SENHA_TRIBUNAL_RECUSA_DO_PAR,
        'login_pje=',
        'senha_pje=',
        'loginFailed',
        'exception invoking',
        'Erro ao realizar login via MNI',
    ];

    foreach ($corpos as $corpo) {
        foreach ($proibidos as $proibido) {
            expect($corpo)->not->toContain($proibido);
        }
    }
}

dataset('formas_da_recusa_do_par', [
    'sucesso false' => 'sucesso_false',
    'exceção do recibo' => 'excecao_do_recibo',
    'SoapFault' => 'soap_fault',
]);

dataset('caminhos_da_recusa_do_par', [
    'processo ausente em /processo/visualizar' => 'processo_ausente',
    'documento ausente do banco em /documento/visualizar' => 'documento_ausente',
    'PDF pendente em /documento/visualizar' => 'pdf_pendente',
    'HTML pendente em /documento/visualizar' => 'html_pendente',
]);

it('responde 403 credencial_pje_recusada quando o tribunal recusa o par do cliente', function (string $forma, string $caminho) {
    [$url, $documento] = cenarioRecusaDoPar($caminho);
    $simulador = simularIntegracaoMni(responderRecusaDoPar($forma));

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson($url . parDoClienteRecusaDoPar())
        ->assertStatus(403)
        ->assertExactJson([
            'codigo' => 'credencial_pje_recusada',
            'message' => 'Credencial PJe recusada pelo tribunal',
        ]);

    expectCorpoSemSigiloRecusaDoPar($response);
    expectUmaRequisicaoSoapRecusaDoPar($simulador, LOGIN_CLIENTE_RECUSA_DO_PAR, SENHA_CLIENTE_RECUSA_DO_PAR, $documento);
})->with('formas_da_recusa_do_par')->with('caminhos_da_recusa_do_par');

it('segue com 500 sem codigo quando o tribunal recusa o par padrão', function (string $forma, string $caminho) {
    definirCredenciaisPadrao(LOGIN_PADRAO_RECUSA_DO_PAR, SENHA_PADRAO_RECUSA_DO_PAR);
    [$url, $documento] = cenarioRecusaDoPar($caminho);
    $simulador = simularIntegracaoMni(responderRecusaDoPar($forma));

    // sem o par na query: o InjectCredenciaisPjePadrao injeta o par padrão
    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson($url)
        ->assertStatus(500)
        ->assertJsonMissingPath('codigo');

    expectUmaRequisicaoSoapRecusaDoPar($simulador, LOGIN_PADRAO_RECUSA_DO_PAR, SENHA_PADRAO_RECUSA_DO_PAR, $documento);
})->with('formas_da_recusa_do_par')->with('caminhos_da_recusa_do_par');
