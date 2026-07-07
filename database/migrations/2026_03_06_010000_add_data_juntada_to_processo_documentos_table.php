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
            $table->timestamp('data_juntada')->nullable()->after('usuario_juntada_arquivo');
        });
    }

    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropColumn('data_juntada');
        });
    }
};
