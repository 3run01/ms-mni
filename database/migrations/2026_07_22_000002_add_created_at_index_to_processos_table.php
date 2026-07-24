<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evita reter lock ACCESS EXCLUSIVE durante a criação do índice em
     * tabela grande de produção.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            $table->index('created_at', 'idx_processos_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            $table->dropIndex('idx_processos_created_at');
        });
    }
};
