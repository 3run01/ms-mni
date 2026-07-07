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
        Schema::create('processo_documentos', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('processo_id')->constrained('processos')->onDelete('cascade');
            $table->string('id_documento');
            $table->string('id_documento_vinculado')->nullable();
            $table->string('tipo_documento');
            $table->dateTime('data_hora');
            $table->string('mimetype');
            $table->string('movimento')->nullable();
            $table->string('hash');
            $table->string('nivel_sigilo')->default(0);
            $table->string('descricao');
            $table->boolean('baixado')->default(false);
            $table->string('url')->nullable();
            $table->string('path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processo_documentos');
    }
};
