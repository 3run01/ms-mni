<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function criarTokenApi(string $plain = 'tk-test'): \App\Models\ApiToken
{
    return \App\Models\ApiToken::create([
        'name' => 'token-teste-' . \Illuminate\Support\Str::random(8),
        'token' => \App\Models\ApiToken::hashToken($plain),
        'ativo' => true,
    ]);
}

function definirCredenciaisPadrao(?string $login, ?string $senha): void
{
    config()->set('pje.credenciais_padrao.login', $login);
    config()->set('pje.credenciais_padrao.senha', $senha);
}

/**
 * Processo com tribunal ativo e documentos para o download em lote.
 *
 * `$documentos` é a quantidade de PDFs `pendente` ou uma lista de atributos
 * por documento, mesclados sobre esse padrão (`id_documento` sequencial
 * quando omitido). Passe um Processo em `$processo` para criar mais
 * documentos nele. A senha do tribunal vai cifrada, como no cadastro real,
 * porque as operações SOAP a decifram quando o lote não tem credenciais.
 *
 * @return array{0: \App\Models\Processo, 1: \Illuminate\Support\Collection<int, \App\Models\ProcessoDocumento>}
 */
function criarDocumentosLote(int|array $documentos = 1, array|\App\Models\Processo $processo = [], array $tribunal = []): array
{
    static $ultimoIdDocumento = 7100000;

    if (!$processo instanceof \App\Models\Processo) {
        $tribunalCriado = \App\Models\Tribunal::factory()->create(array_merge([
            'login' => 'login-do-tribunal',
            'password' => \Illuminate\Support\Facades\Crypt::encrypt('{SENHA}'),
            'ativo' => true,
        ], $tribunal));

        $processo = \App\Models\Processo::factory()->create(array_merge([
            'tribunal_id' => $tribunalCriado->id,
        ], $processo));
    }

    $criados = collect();

    foreach (is_int($documentos) ? array_fill(0, $documentos, []) : $documentos as $atributos) {
        $criados->push(\App\Models\ProcessoDocumento::create(array_merge([
            'processo_id' => $processo->id,
            'id_documento' => ++$ultimoIdDocumento,
            'tipo_documento' => '57',
            'data_hora' => '2024-12-12 10:00:00',
            'descricao' => 'Documento do lote',
            'mimetype' => 'application/pdf',
            'status' => \App\Models\ProcessoDocumento::STATUS_PENDENTE,
            'tentativas_download' => 0,
        ], $atributos)));
    }

    return [$processo, $criados];
}

/**
 * `documento` como as operações MNI o devolvem: cru, antes da normalização
 * base64. Com um ProcessoDocumento, usa o id_documento e o mimetype dele;
 * `$atributos` sobrescreve, por exemplo `conteudo` vazio ou bloqueado.
 */
function documentoMniFake(\App\Models\ProcessoDocumento|int|string $documento, array $atributos = []): object
{
    $id = $documento instanceof \App\Models\ProcessoDocumento ? $documento->id_documento : $documento;
    $mimetype = $documento instanceof \App\Models\ProcessoDocumento ? $documento->mimetype : 'application/pdf';

    return (object) array_merge([
        'idDocumento' => (string) $id,
        'mimetype' => $mimetype,
        'tipoDocumento' => '57',
        'dataHora' => '20241212100000',
        'descricao' => 'Documento do lote',
        'conteudo' => $mimetype === 'text/html'
            ? "<html><body><p>Documento {$id}</p></body></html>"
            : "conteudo-binario-{$id}",
    ], $atributos);
}

/**
 * Troca o IntegracaoBase do container por um mock que não toca a rede.
 *
 * Cada makeSoapRequest fica em `->requisicoes` (`operacao`, `parametros`) e
 * é delegado ao `$responder($operacao, $parametros)`, que devolve o retorno
 * do MNI ou lança (ex.: SoapFault). A URL de cada criação fica em `->urls`.
 * Um único binding atende ConsultarProcessoService e ConsultarDocumentoService:
 * a consulta de documento é a que tem a chave `documento` em `parametros`.
 */
function simularIntegracaoMni(Closure $responder): stdClass
{
    $registro = new stdClass();
    $registro->urls = [];
    $registro->requisicoes = [];

    app()->bind(\App\Services\IntegracaoBase::class, function ($app, $params) use ($registro, $responder) {
        $registro->urls[] = $params['url'] ?? null;

        $integracao = Mockery::mock(\App\Services\IntegracaoBase::class);
        $integracao->shouldReceive('makeSoapRequest')
            ->andReturnUsing(function (string $operacao, array $parametros) use ($registro, $responder) {
                $registro->requisicoes[] = ['operacao' => $operacao, 'parametros' => $parametros];

                return $responder($operacao, $parametros);
            });

        return $integracao;
    });

    return $registro;
}
