<?php

use App\Models\ProcessoDocumento;
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
            $table->dropColumn('baixado');
        });

        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->string('status')->default(ProcessoDocumento::STATUS_PENDENTE);
            $table->integer('tentativas_download')->default(0);
        });

        ProcessoDocumento::whereNotNull('path')->update(['status' => ProcessoDocumento::STATUS_BAIXADO]);
        ProcessoDocumento::whereNull('path')->update(['status' => ProcessoDocumento::STATUS_PENDENTE]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->dropColumn('tentativas_download');
        });

        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->boolean('baixado')->default(false);
        });
    }
};
