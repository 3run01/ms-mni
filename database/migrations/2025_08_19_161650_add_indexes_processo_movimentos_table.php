<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('processo_movimentos', function (Blueprint $table) {
            // Índice composto para processo_id + identificador_movimento (usado no updateOrCreate)
            $table->index(['processo_id', 'identificador_movimento'], 'idx_processo_movimentos_processo_id_identificador');

            // Índice para processo_id (usado em relacionamentos e filtros)
            $table->index('processo_id', 'idx_processo_movimentos_processo_id');

            // Índice para identificador_movimento (usado em buscas específicas)
            $table->index('identificador_movimento', 'idx_processo_movimentos_identificador');

            // Índice para data_hora (usado em ordenação e filtros temporais)
            $table->index('data_hora', 'idx_processo_movimentos_data_hora');

            // Índice para codigo_nacional (usado em filtros por tipo de movimento)
            $table->index('codigo_nacional', 'idx_processo_movimentos_codigo_nacional');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processo_movimentos', function (Blueprint $table) {
            $table->dropIndex('idx_processo_movimentos_processo_id_identificador');
            $table->dropIndex('idx_processo_movimentos_processo_id');
            $table->dropIndex('idx_processo_movimentos_identificador');
            $table->dropIndex('idx_processo_movimentos_data_hora');
            $table->dropIndex('idx_processo_movimentos_codigo_nacional');
        });
    }
};
