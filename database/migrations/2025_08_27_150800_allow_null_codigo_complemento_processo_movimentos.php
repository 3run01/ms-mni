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
        Schema::table('processo_movimentos', function (Blueprint $table) {
            $table->string('codigo_nacional')->nullable()->change();
            $table->longText('complemento')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processo_movimentos', function (Blueprint $table) {
            $table->string('codigo_nacional')->nullable(false)->change();
            $table->longText('complemento')->nullable(false)->change();
        });
    }
};
