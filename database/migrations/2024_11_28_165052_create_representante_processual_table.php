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
        Schema::create('processo_parte_representante', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('processo_id')->constrained('processos')->onDelete('cascade');
            $table->foreignId('parte_id')->constrained('processo_partes')->onDelete('cascade');
            $table->string('nome');
            $table->string('numero_documento_principal');
            $table->string('inscricao')->nullable();
            $table->string('tipo_representante')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processo_parte_representante');
    }
};
