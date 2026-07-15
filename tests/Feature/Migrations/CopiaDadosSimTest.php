<?php

use Illuminate\Support\Facades\DB;

it('copiou tribunais e tipos_documentos do sim para o default com ids e uuids', function () {
    $simTribunais = DB::connection('sim')->table('tribunais')->count();
    $simTipos = DB::connection('sim')->table('tipos_documentos')->count();

    expect($simTribunais)->toBeGreaterThan(0);

    expect(DB::connection()->table('tribunais')->count())->toBe($simTribunais);
    expect(DB::connection()->table('tipos_documentos')->count())->toBe($simTipos);

    // ids preservados: todo id do sim existe no default
    $idsSim = DB::connection('sim')->table('tribunais')->pluck('id')->all();
    $idsDefault = DB::connection()->table('tribunais')->pluck('id')->all();
    expect(array_diff($idsSim, $idsDefault))->toBe([]);

    // uuid gerado em todos os tribunais
    expect(DB::connection()->table('tribunais')->whereNull('uuid')->count())->toBe(0);

    // integridade: todo tipos_documento aponta para um tribunal existente
    expect(
        DB::connection()->table('tipos_documentos')
            ->whereNotIn('tribunal_id', DB::connection()->table('tribunais')->pluck('id'))
            ->count()
    )->toBe(0);
});
