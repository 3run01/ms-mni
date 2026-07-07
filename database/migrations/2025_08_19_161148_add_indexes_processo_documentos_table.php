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
        Schema::table('processo_documentos', function (Blueprint $table) {
            // Índice composto para processo_id + id_documento (usado no updateOrCreate)
            $table->index(['processo_id', 'id_documento'], 'idx_processo_documentos_processo_id_documento');

            // Índice para status (muito usado em comandos de console)
            $table->index('status', 'idx_processo_documentos_status');

            // Índice para mimetype (usado em filtros de tipo de mídia)
            $table->index('mimetype', 'idx_processo_documentos_mimetype');

            // Índice para id_documento (usado em buscas específicas)
            $table->index('id_documento', 'idx_processo_documentos_id_documento');

            // Índice para data_hora (usado em ordenação e filtros temporais)
            $table->index('data_hora', 'idx_processo_documentos_data_hora');

            // Índice composto para status + mimetype (usado em comandos de verificação)
            $table->index(['status', 'mimetype'], 'idx_processo_documentos_status_mimetype');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropIndex('idx_processo_documentos_processo_id_documento');
            $table->dropIndex('idx_processo_documentos_status');
            $table->dropIndex('idx_processo_documentos_mimetype');
            $table->dropIndex('idx_processo_documentos_id_documento');
            $table->dropIndex('idx_processo_documentos_data_hora');
            $table->dropIndex('idx_processo_documentos_status_mimetype');
        });
    }
};
