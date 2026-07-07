<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->unique(['processo_id', 'id_documento'], 'uq_processo_documentos_processo_id_documento');
        });
    }

    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropUnique('uq_processo_documentos_processo_id_documento');
        });
    }
};
