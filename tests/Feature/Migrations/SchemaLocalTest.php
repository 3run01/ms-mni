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
