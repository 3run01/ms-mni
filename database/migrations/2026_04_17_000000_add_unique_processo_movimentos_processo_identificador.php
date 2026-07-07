<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            DELETE FROM processo_movimentos m1
            USING processo_movimentos m2
            WHERE m1.processo_id = m2.processo_id
              AND m1.identificador_movimento = m2.identificador_movimento
              AND m1.id > m2.id
        ');

        Schema::table('processo_movimentos', function (Blueprint $table) {
            $table->dropIndex('idx_processo_movimentos_processo_id_identificador');
            $table->unique(
                ['processo_id', 'identificador_movimento'],
                'uq_processo_movimentos_processo_identificador'
            );
        });
    }

    public function down(): void
    {
        Schema::table('processo_movimentos', function (Blueprint $table) {
            $table->dropUnique('uq_processo_movimentos_processo_identificador');
            $table->index(
                ['processo_id', 'identificador_movimento'],
                'idx_processo_movimentos_processo_id_identificador'
            );
        });
    }
};
