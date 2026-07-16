<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documentos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('tribunal_id');
            $table->string('descricao', 255);
            $table->string('codigo', 255);
            $table->boolean('exibir_peticao_incidental');
            $table->boolean('exibir_peticao_inicial');
            $table->boolean('exibir_expediente');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_documentos');
    }
};
