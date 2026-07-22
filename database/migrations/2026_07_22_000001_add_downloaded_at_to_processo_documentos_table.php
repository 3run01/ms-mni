<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable()->after('status');
            $table->index('downloaded_at', 'idx_processo_documentos_downloaded_at');
        });

        // Backfill histórico: aproximação — updated_at é o último save,
        // que para docs baixados normalmente é o momento do download.
        DB::table('processo_documentos')
            ->where('status', 'baixado')
            ->whereNull('downloaded_at')
            ->update(['downloaded_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropIndex('idx_processo_documentos_downloaded_at');
            $table->dropColumn('downloaded_at');
        });
    }
};
