<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tribunais');

        Schema::create('tribunais', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique()->nullable();
            $table->string('nome', 255);
            $table->string('login', 255)->nullable();
            $table->text('password')->nullable();
            $table->string('url_webservice_mni', 255);
            $table->string('url_webservice_mni_complementar', 255);
            $table->string('url_webservice_mni_consultar_processo', 255)->nullable();
            $table->string('url_webservice_mni_criminal', 255)->nullable();
            $table->string('url_consulta_pje', 255)->nullable();
            $table->string('url_recuperar_senha_tribunal', 255)->nullable();
            $table->string('tipo', 255)->nullable();
            $table->boolean('ativo')->nullable();
            $table->string('versao_mni', 255)->nullable();
            $table->string('codigo_peticao_inicial', 255)->nullable();
            $table->string('codigo_peticao_avulsa', 255)->nullable();
            $table->string('codigo_certidao_inicio_fim', 255)->nullable();
            $table->string('codigo_seeu', 255)->nullable();
            $table->string('usar_codigo_documento_padrao', 255)->nullable();
            $table->boolean('usar_credencial_tribunal')->default(false);
            $table->boolean('enviar_dados_criminais')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tribunais');
    }
};
