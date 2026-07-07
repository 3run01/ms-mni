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
        Schema::table('processos', function (Blueprint $table) {
            $table->string('nome_orgao_julgador')->nullable();
            $table->string('codigo_orgao_julgador')->nullable();
            $table->string('instancia_orgao_julgador')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            $table->dropColumn('nome_orgao_julgador');
            $table->dropColumn('codigo_orgao_julgador');
            $table->dropColumn('instancia_orgao_julgador');
        });
    }
};
