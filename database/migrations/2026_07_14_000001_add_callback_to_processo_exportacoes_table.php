<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processo_exportacoes', function (Blueprint $table) {
            $table->string('callback_url', 2048)->nullable()->after('filtros');
            $table->string('callback_token', 500)->nullable()->after('callback_url');
        });
    }

    public function down(): void
    {
        Schema::table('processo_exportacoes', function (Blueprint $table) {
            $table->dropColumn(['callback_url', 'callback_token']);
        });
    }
};
