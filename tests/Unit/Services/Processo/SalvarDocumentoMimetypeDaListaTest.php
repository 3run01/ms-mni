<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

/*
 * A gravação da lista não troca mimetype não vazio por '' (T02, RF-14,
 * decisão Q-01, LIM-06). O '' da lista é "o tribunal não informou": cria o
 * documento novo, mas não apaga o mimetype revelado pelo OCR.
 */

uses(DatabaseTransactions::class);

const NUMERO_MIMETYPE_DA_LISTA = '00012345620268030011';

beforeEach(function () {
    Queue::fake();

    $this->processo = Processo::factory()->create([
        'numero_processo' => NUMERO_MIMETYPE_DA_LISTA,
    ]);
});

function documentoGravadoDaLista(Processo $processo, int $idDocumento, string $mimetype, array $atributos = []): ProcessoDocumento
{
    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => $idDocumento,
        'tipo_documento' => '57',
        'data_hora' => '2024-12-12 10:00:00',
        'descricao' => 'Documento gravado',
        'mimetype' => $mimetype,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
    ], $atributos));
}

it('com o mimetype da lista, o gravado fica como na tabela de RF-14', function (string $gravado, string $daLista, string $depois) {
    $documento = documentoGravadoDaLista($this->processo, 9410001, $gravado);

    $salvo = (new SalvarDocumentoProcessoService())
        ->salvarDocumento($this->processo, documentoMniFake(9410001, ['mimetype' => $daLista]));

    expect($salvo->id)->toBe($documento->id)
        ->and($salvo->mimetype)->toBe($depois)
        ->and($documento->fresh()->mimetype)->toBe($depois)
        ->and(ProcessoDocumento::where('processo_id', $this->processo->id)->count())->toBe(1);
})->with([
    "video/mp4 / '' → video/mp4" => ['video/mp4', '', 'video/mp4'],
    "application/pdf / '' → application/pdf" => ['application/pdf', '', 'application/pdf'],
    "'' / application/pdf → application/pdf" => ['', 'application/pdf', 'application/pdf'],
    'application/pdf / text/html → text/html' => ['application/pdf', 'text/html', 'text/html'],
    "'' / '' → ''" => ['', '', ''],
]);

it("cria o documento novo com '' quando a lista não informa o mimetype", function () {
    $salvo = (new SalvarDocumentoProcessoService())
        ->salvarDocumento($this->processo, documentoMniFake(9410002, ['mimetype' => '', 'descricao' => 'Documento novo']));

    $gravado = ProcessoDocumento::where('processo_id', $this->processo->id)
        ->where('id_documento', 9410002)
        ->sole();

    expect($salvo)->toBeInstanceOf(ProcessoDocumento::class)
        ->and($salvo->wasRecentlyCreated)->toBeTrue()
        ->and($salvo->id)->toBe($gravado->id)
        ->and($gravado->mimetype)->toBe('')
        ->and($gravado->descricao)->toBe('Documento novo')
        ->and($gravado->tipo_documento)->toBe('57')
        ->and($gravado->data_hora)->toBe('2024-12-12 10:00:00');
});

it("com '' na lista, os demais campos do documento existente são atualizados como hoje", function () {
    $documento = documentoGravadoDaLista($this->processo, 9410003, 'video/mp4', [
        'descricao' => 'Descrição antiga',
        'data_juntada' => '2024-01-01 08:00:00',
        'usuario_juntada_arquivo' => 'USUARIO ANTIGO',
        'tipo_documento' => '10',
    ]);

    // Mesma conversão do service: o tribunal manda a data de juntada em ms.
    $dataJuntadaMs = 1772791200000;
    $dataJuntada = \Carbon\Carbon::createFromTimestampMs($dataJuntadaMs)->format('Y-m-d H:i:s');

    (new SalvarDocumentoProcessoService())->salvarDocumento($this->processo, documentoMniFake(9410003, [
        'mimetype' => '',
        'descricao' => 'Descrição nova',
        'tipoDocumento' => '57',
        'outroParametro' => [
            (object) ['nome' => 'mni:pje:documento:dataJuntada', 'valor' => $dataJuntadaMs],
            (object) ['nome' => 'pessoaJuntadaArquivo', 'valor' => 'LUCAS MONTEIRO PEREIRA'],
        ],
    ]));

    $depois = $documento->fresh();

    expect($depois->mimetype)->toBe('video/mp4')
        ->and($depois->descricao)->toBe('Descrição nova')
        ->and($depois->tipo_documento)->toBe('57')
        ->and($depois->data_juntada)->toBe($dataJuntada)
        ->and($dataJuntada)->not->toBe('2024-01-01 08:00:00')
        ->and($depois->usuario_juntada_arquivo)->toBe('LUCAS MONTEIRO PEREIRA')
        ->and($depois->status)->toBe(ProcessoDocumento::STATUS_BAIXADO);
});

it("o '' da lista não apaga o mimetype de um documento vinculado já gravado", function () {
    $principal = documentoGravadoDaLista($this->processo, 9410004, 'application/pdf');
    $vinculado = documentoGravadoDaLista($this->processo, 9410005, 'video/mp4');

    (new SalvarDocumentoProcessoService())->execute($this->processo, [
        documentoMniFake(9410004, [
            'mimetype' => '',
            'documentoVinculado' => documentoMniFake(9410005, [
                'mimetype' => '',
                'idDocumentoVinculado' => '9410004',
            ]),
        ]),
    ], null, null, false);

    expect($principal->fresh()->mimetype)->toBe('application/pdf')
        ->and($vinculado->fresh()->mimetype)->toBe('video/mp4')
        ->and($vinculado->fresh()->id_documento_vinculado)->toBe('9410004');
});

it("pela consulta de documentos do tribunal, um video/mp4 continua video/mp4 com '' na lista", function () {
    $tribunal = Tribunal::factory()->create([
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni_consultar_processo' => 'https://tjap-1grau.example/mni/consultar-processo',
        'ativo' => true,
    ]);
    $processo = Processo::factory()->create([
        'tribunal_id' => $tribunal->id,
        'numero_processo' => NUMERO_MIMETYPE_DA_LISTA,
    ]);
    $video = documentoGravadoDaLista($processo, 9410006, 'video/mp4');
    $novo = 9410007;

    $mni = simularIntegracaoMni(fn () => (object) [
        'sucesso' => true,
        'processo' => (object) [
            'documento' => [
                documentoMniFake(9410006, ['mimetype' => '']),
                documentoMniFake($novo, ['mimetype' => '']),
            ],
        ],
    ]);

    app(ProcessoService::class)->consultarDocumentos($tribunal, NUMERO_MIMETYPE_DA_LISTA, 'login-lista', 'senha-lista');

    expect($mni->requisicoes)->toHaveCount(1)
        ->and($mni->requisicoes[0]['operacao'])->toBe('consultarProcesso')
        ->and($video->fresh()->mimetype)->toBe('video/mp4')
        ->and(ProcessoDocumento::where('processo_id', $processo->id)->where('id_documento', $novo)->sole()->mimetype)->toBe('');
});
