<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evita que o ALTER, o CREATE INDEX e o backfill em chunks rodem dentro
     * de uma única transação — em tabela grande isso reteria lock
     * ACCESS EXCLUSIVE por todo o tempo do backfill (downtime real).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable();
            $table->index('downloaded_at', 'idx_processo_documentos_downloaded_at');
        });

        // Backfill histórico em chunks, fora de transação: updated_at é o
        // último save, que para docs baixados normalmente é o momento do
        // download.
        while (true) {
            $affected = DB::update("
                UPDATE processo_documentos
                SET downloaded_at = updated_at
                WHERE id IN (
                    SELECT id FROM processo_documentos
                    WHERE status = 'baixado' AND downloaded_at IS NULL
                    LIMIT 5000
                )
            ");

            if ($affected === 0) {
                break;
            }
        }
    }

    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropIndex('idx_processo_documentos_downloaded_at');
            $table->dropColumn('downloaded_at');
        });
    }
};
