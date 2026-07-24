<?php

use Illuminate\Support\Facades\Schema;

it('processo_documentos tem a coluna downloaded_at', function () {
    expect(Schema::hasColumn('processo_documentos', 'downloaded_at'))->toBeTrue();
});
