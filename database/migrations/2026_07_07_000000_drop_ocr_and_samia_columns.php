<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove as colunas de OCR e SAMIA (base de conhecimento).
     * Funcionalidades removidas — ver spec
     * docs/superpowers/specs/2026-07-07-remocao-ocr-samia-credenciais-obrigatorias-design.md
     */
    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            if (Schema::hasColumn('processo_documentos', 'ocr_job_id')) {
                $table->dropIndex(['ocr_job_id']);
            }
            foreach (['ocr_processado', 'ocr_enviado_fila', 'ocr_concluido_data', 'ocr_job_id'] as $coluna) {
                if (Schema::hasColumn('processo_documentos', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });

        Schema::table('processos', function (Blueprint $table) {
            if (Schema::hasColumn('processos', 'ocr_status')) {
                $table->dropIndex(['ocr_status']);
                $table->dropColumn('ocr_status');
            }
            foreach (['knowledge_base_status_sync', 'knowledge_base_sequence_job', 'knowledge_base_created_at'] as $coluna) {
                if (Schema::hasColumn('processos', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }

    /**
     * Recria as colunas com os tipos que tinham antes do drop
     * (knowledge_base_sequence_job ja como BIGINT, pos-2025_12_17).
     */
    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->boolean('ocr_processado')->default(false);
            $table->boolean('ocr_enviado_fila')->default(false);
            $table->dateTime('ocr_concluido_data')->nullable();
            $table->string('ocr_job_id')->nullable();
            $table->index('ocr_job_id');
        });

        Schema::table('processos', function (Blueprint $table) {
            $table->string('knowledge_base_status_sync')->default('PENDING');
            $table->bigInteger('knowledge_base_sequence_job')->nullable();
            $table->dateTime('knowledge_base_created_at')->nullable();
            $table->string('ocr_status', 32)->nullable();
            $table->index('ocr_status');
        });
    }
};
