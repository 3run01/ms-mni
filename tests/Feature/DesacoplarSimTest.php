<?php

use App\Models\Tribunal;
use App\Models\TipoDocumento;

it('models Tribunal e TipoDocumento usam a conexão default (não sim)', function () {
    $default = config('database.default');
    expect((new Tribunal)->getConnection()->getName())->toBe($default)
        ->and((new TipoDocumento)->getConnection()->getName())->toBe($default);
});
