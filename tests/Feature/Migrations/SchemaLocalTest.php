<?php

use Illuminate\Support\Facades\Schema;

it('tipos_documentos existe no banco default com as colunas canônicas', function () {
    expect(Schema::hasTable('tipos_documentos'))->toBeTrue();

    foreach ([
        'id', 'tribunal_id', 'descricao', 'codigo',
        'exibir_peticao_incidental', 'exibir_peticao_inicial', 'exibir_expediente',
        'created_at', 'updated_at', 'deleted_at',
    ] as $coluna) {
        expect(Schema::hasColumn('tipos_documentos', $coluna))->toBeTrue("faltou coluna {$coluna}");
    }
});

it('tribunais existe no default com o schema canônico', function () {
    expect(Schema::hasTable('tribunais'))->toBeTrue();

    foreach ([
        'id', 'uuid', 'nome', 'login', 'password',
        'url_webservice_mni', 'url_webservice_mni_complementar',
        'url_webservice_mni_consultar_processo', 'url_webservice_mni_criminal',
        'url_consulta_pje', 'url_recuperar_senha_tribunal', 'tipo', 'ativo',
        'versao_mni', 'codigo_peticao_inicial', 'codigo_peticao_avulsa',
        'codigo_certidao_inicio_fim', 'codigo_seeu', 'usar_codigo_documento_padrao',
        'usar_credencial_tribunal', 'enviar_dados_criminais',
        'created_at', 'updated_at', 'deleted_at',
    ] as $coluna) {
        expect(Schema::hasColumn('tribunais', $coluna))->toBeTrue("faltou coluna {$coluna}");
    }

    // a coluna antiga divergente não deve existir mais
    expect(Schema::hasColumn('tribunais', 'url_recuperar_senha'))->toBeFalse();
});
