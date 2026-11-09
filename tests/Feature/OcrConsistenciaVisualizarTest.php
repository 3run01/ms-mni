<?php

use App\Jobs\BaixarDocumentoParaOcrJob;
use App\Jobs\EnviarDocumentoAoOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\Ocr\ContagemOcrService;
use App\Services\Ocr\FormatosOcr;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * Consistência entre a lista de `/processo/visualizar`, a contagem do OCR e o
 * pedido (T14; RF-05, RNF-03). A situação mostrada na lista, as contagens e a
 * escolha do que o pedido enfileira saem da mesma regra (FormatosOcr), e o
 * documento conta em `total` exatamente quando a lista não o mostra
 * `ignorado` e, pendente, exatamente quando o pedido o enfileira.
 *
 * Queue::fake(): a lista não roda o job de atualização em segundo plano, e
 * os jobs do pedido ficam guardados para a conferência.
 */

uses(DatabaseTransactions::class);

const NUMERO_CONSISTENCIA_OCR = '12345674220268269100';
const TOKEN_CONSISTENCIA_OCR = 'tk-consistencia-ocr-5e1';
const LOGIN_CONSISTENCIA_OCR = 'login-consistencia-ocr-2b7';
const SENHA_CONSISTENCIA_OCR = 'senha-consistencia-ocr-8d4';
const PRIMEIRO_DOCUMENTO_CONSISTENCIA_OCR = 9500001;

beforeEach(function () {
    isolarProcessamentosOcrDoBancoDeDev();
    Queue::fake();

    config()->set('services.n8n_ocr.url', 'http://n8n.test/webhook/juridico-ocr');
    config()->set('services.n8n_ocr.token', 'token-workflow-consistencia-6c3');
    config()->set('services.n8n_ocr.base_conhecimento', 'base-processos');
    config()->set('services.n8n_ocr.webhook_base_url', 'http://host.docker.internal:8002');
    config()->set('services.n8n_ocr.espera_da_trava_segundos', 45);
    config()->set('pje.callback.hosts_liberados', ['sistema.example']);
    definirCredenciaisPadrao(null, null);

    criarTokenApi(TOKEN_CONSISTENCIA_OCR);

    $this->tribunal = Tribunal::factory()->create([
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'ativo' => true,
    ]);

    $this->processo = Processo::factory()->create([
        'tribunal_id' => $this->tribunal->id,
        'numero_processo' => NUMERO_CONSISTENCIA_OCR,
    ]);
});

/**
 * @param  array<string, mixed>  $atributos
 */
function documentoConsistenciaOcr(int $idDocumento, string $mimetype, ?string $ocrSituacao, array $atributos = []): ProcessoDocumento
{
    return ProcessoDocumento::create(array_merge([
        'processo_id' => test()->processo->id,
        'id_documento' => $idDocumento,
        'tipo_documento' => '57',
        'data_hora' => '2026-03-06 09:00:00',
        'descricao' => "Documento {$idDocumento}",
        'mimetype' => $mimetype,
        'ocr_situacao' => $ocrSituacao,
        'status' => ProcessoDocumento::STATUS_PENDENTE,
        'tentativas_download' => 0,
    ], $atributos));
}

function visualizarConsistenciaOcr(): TestResponse
{
    return test()->withHeaders(['X-API-Token' => TOKEN_CONSISTENCIA_OCR])
        ->getJson('/api/processo/visualizar?'.http_build_query([
            'tribunal_id' => test()->tribunal->id,
            'numero_processo' => NUMERO_CONSISTENCIA_OCR,
            'login_pje' => LOGIN_CONSISTENCIA_OCR,
            'senha_pje' => SENHA_CONSISTENCIA_OCR,
        ]));
}

/**
 * `ocr_situacao` de cada item da lista, por id_documento.
 *
 * @return array<int, string>
 */
function situacoesNaListaConsistenciaOcr(TestResponse $resposta): array
{
    $resposta->assertOk();

    return collect($resposta->json('documentos'))
        ->mapWithKeys(fn (array $item) => [(int) $item['id_documento'] => $item['ocr_situacao']])
        ->all();
}

/**
 * MNI simulado da lista do pedido: devolve os documentos do processo com o
 * mimetype gravado, para que a gravação da lista não mude nada.
 */
function mniConsistenciaOcr(): stdClass
{
    return simularIntegracaoMni(fn () => (object) [
        'sucesso' => true,
        'processo' => (object) [
            'dadosBasicos' => (object) [
                'numero' => NUMERO_CONSISTENCIA_OCR,
                'codigoLocalidade' => '1',
                'classeProcessual' => '7',
                'nivelSigilo' => 0,
                'valorCausa' => 1500,
                'polo' => [],
            ],
            'movimento' => [],
            'documento' => test()->processo->documentos()->orderBy('id')->get()
                ->map(fn (ProcessoDocumento $documento) => documentoMniFake($documento))
                ->all(),
        ],
    ]);
}

function pedirOcrConsistencia(): TestResponse
{
    return test()->postJson('/api/processo/ocr', [
        'numero_processo' => NUMERO_CONSISTENCIA_OCR,
        'tribunal_id' => test()->tribunal->id,
        'login_pje' => LOGIN_CONSISTENCIA_OCR,
        'senha_pje' => SENHA_CONSISTENCIA_OCR,
        'callback_url' => 'https://sistema.example/mni/processamentos/consistencia/progresso',
        'callback_token' => 'tok-callback-consistencia-4a9',
    ], ['X-API-Token' => TOKEN_CONSISTENCIA_OCR]);
}

/**
 * Os documentos enfileirados pelo pedido (download ou envio), pelo id do
 * registro.
 *
 * @return list<int>
 */
function enfileiradosPeloPedidoConsistenciaOcr(): array
{
    return Queue::pushed(BaixarDocumentoParaOcrJob::class)->pluck('documentoId')
        ->merge(Queue::pushed(EnviarDocumentoAoOcrJob::class)->pluck('documentoId'))
        ->values()
        ->all();
}

// ---------- RF-05: a lista e a contagem no mesmo processo ----------

it('as 4 igualdades de RF-05 valem entre /processo/visualizar e a contagem do mesmo processo, para o dataset de RF-03', function () {
    // RF-03: mimetype / coluna ocr_situacao → situação mostrada.
    $dataset = [
        ['video/mp4', null, 'ignorado'],
        ['application/pdf', null, 'pendente'],
        ['', null, 'pendente'],
        ['application/pdf', ProcessoDocumento::OCR_PROCESSADO, 'processado'],
        ['text/html', ProcessoDocumento::OCR_ENVIADO, 'enviado'],
        ['image/png', ProcessoDocumento::OCR_ERRO, 'erro'],
        ['', ProcessoDocumento::OCR_ERRO, 'erro'],
        ['audio/mpeg', null, 'ignorado'],
        ['application/zip', ProcessoDocumento::OCR_PENDENTE, 'ignorado'],
        ['video/mp4', ProcessoDocumento::OCR_PROCESSADO, 'ignorado'],
    ];

    $esperadas = [];

    foreach ($dataset as $i => [$mimetype, $coluna, $situacao]) {
        $id = PRIMEIRO_DOCUMENTO_CONSISTENCIA_OCR + $i;
        documentoConsistenciaOcr($id, $mimetype, $coluna);
        $esperadas[$id] = $situacao;
    }

    $lista = situacoesNaListaConsistenciaOcr(visualizarConsistenciaOcr());
    $contagem = app(ContagemOcrService::class)->contar($this->processo->id);
    $quantas = fn (string $situacao) => count(array_keys($lista, $situacao, true));

    // A lista é a da tabela de RF-03.
    ksort($lista);
    expect($lista)->toBe($esperadas);

    // As 4 igualdades de RF-05.
    expect($quantas('processado'))->toBe($contagem->processados)
        ->and($quantas('erro'))->toBe($contagem->comErro)
        ->and($quantas('ignorado'))->toBe($contagem->ignorados)
        ->and(count($lista) - $quantas('ignorado'))->toBe($contagem->total);

    // As outras 2 situações também batem, e os números são os do dataset.
    expect($quantas('pendente'))->toBe($contagem->pendentes)
        ->and($quantas('enviado'))->toBe($contagem->aguardando)
        ->and([$contagem->total, $contagem->processados, $contagem->comErro, $contagem->ignorados, $contagem->pendentes, $contagem->aguardando])
        ->toBe([6, 1, 2, 4, 2, 1])
        ->and($contagem->total + $contagem->ignorados)->toBe(count($dataset));
});

// ---------- RNF-03: os consumidores da regra concordam ----------

it('conta em total ⇔ não sai ignorado na lista ⇔ o pedido o enfileira, para um documento pendente com ocr_situacao null (RNF-03)', function (string $mimetype, bool $entraNoTotal) {
    $documento = documentoConsistenciaOcr(PRIMEIRO_DOCUMENTO_CONSISTENCIA_OCR, $mimetype, null);

    // Contagem e lista no mesmo estado do banco, antes do pedido.
    $contagem = app(ContagemOcrService::class)->contar($this->processo->id);
    $situacao = situacoesNaListaConsistenciaOcr(visualizarConsistenciaOcr())[$documento->id_documento];

    $mni = mniConsistenciaOcr();
    $resposta = pedirOcrConsistencia()->assertStatus(202);
    $enfileirados = enfileiradosPeloPedidoConsistenciaOcr();

    $naContagem = $contagem->total === 1;
    $naoIgnoradoNaLista = $situacao !== FormatosOcr::SITUACAO_IGNORADO;
    $enfileirado = in_array($documento->id, $enfileirados, true);

    expect($naContagem)->toBe($entraNoTotal)
        ->and($naoIgnoradoNaLista)->toBe($entraNoTotal)
        ->and($enfileirado)->toBe($entraNoTotal)
        ->and($contagem->total + $contagem->ignorados)->toBe(1)
        ->and($contagem->pendentes)->toBe($entraNoTotal ? 1 : 0)
        ->and($situacao)->toBe($entraNoTotal ? ProcessoDocumento::OCR_PENDENTE : FormatosOcr::SITUACAO_IGNORADO)
        ->and($enfileirados)->toBe($entraNoTotal ? [$documento->id] : [])
        // A regra pura é a mesma dos 3 consumidores.
        ->and(FormatosOcr::entraNoTotal($mimetype))->toBe($entraNoTotal);

    // O 202 traz a mesma contagem; o pedido só abre processamento com o que ler.
    expect($resposta->json())->toMatchArray([
        'total' => $contagem->total,
        'ignorados' => $contagem->ignorados,
        'situacao' => $entraNoTotal ? 'em_andamento' : 'concluido',
    ]);

    // O pendente vai sempre ao download (não está baixado), na fila do OCR.
    if ($entraNoTotal) {
        expect(Queue::pushed(BaixarDocumentoParaOcrJob::class)->sole()->queue)->toBe(BaixarDocumentoParaOcrJob::FILA);
    }

    // A lista do pedido foi lida do MNI simulado e não mudou o mimetype.
    expect($mni->requisicoes)->toHaveCount(1)
        ->and($documento->fresh()->mimetype)->toBe($mimetype);
})->with([
    'application/pdf' => ['application/pdf', true],
    'text/html' => ['text/html', true],
    'image/png' => ['image/png', true],
    'image/jpeg' => ['image/jpeg', true],
    'image/webp' => ['image/webp', true],
    'image/tiff' => ['image/tiff', true],
    'image/bmp' => ['image/bmp', true],
    'image/gif' => ['image/gif', true],
    "'' (não informado)" => ['', true],
    'video/mp4' => ['video/mp4', false],
    'audio/mpeg' => ['audio/mpeg', false],
    'application/zip' => ['application/zip', false],
]);

it('os 8 mimetypes legíveis do dataset são exatamente a lista legível de FormatosOcr (RNF-03)', function () {
    $legiveis = ['application/pdf', 'text/html', 'image/png', 'image/jpeg', 'image/webp', 'image/tiff', 'image/bmp', 'image/gif'];

    expect(FormatosOcr::MIMETYPES_LEGIVEIS)->toEqualCanonicalizing($legiveis);
});

it('a lista legível é declarada num só arquivo de app/: a varredura por image/webp acha só FormatosOcr (RNF-03)', function () {
    $arquivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    $varridos = 0;
    $comWebp = [];

    foreach ($arquivos as $arquivo) {
        if ($arquivo->getExtension() !== 'php') {
            continue;
        }

        $varridos++;

        // Entre aspas simples ou duplas: a constante, e não um comentário.
        if (preg_match('~[\'"]image/webp[\'"]~', (string) file_get_contents($arquivo->getPathname())) === 1) {
            $comWebp[] = str_replace(base_path().'/', '', $arquivo->getPathname());
        }
    }

    expect($varridos)->toBeGreaterThan(100)
        ->and($comWebp)->toBe(['app/Services/Ocr/FormatosOcr.php']);
});
