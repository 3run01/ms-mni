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
        Schema::create('processo_movimentos', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('processo_id')->constrained('processos')->onDelete('cascade');
            $table->string('identificador_movimento');
            $table->string('codigo_nacional');
            $table->string('complemento');
            $table->dateTime('data_hora');
            $table->string('id_documento_vinculado')->nullable();
            // $table->string
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processo_movimentos');
    }
};
