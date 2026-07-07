<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('processo_exportacoes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('numero_processo', 25);
            $table->unsignedBigInteger('tribunal_id')->nullable();
            $table->string('titulo');
            $table->string('formato', 10);
            $table->enum('status', ['enfileirado', 'processando', 'concluido', 'falhou'])
                  ->default('enfileirado');
            $table->uuid('uuid_arquivo')->nullable();
            $table->string('s3_path', 500)->nullable();
            $table->unsignedBigInteger('tamanho_bytes')->nullable();
            $table->text('erro_resumo')->nullable();
            $table->json('filtros');
            $table->timestamp('webhook_enviado_em')->nullable();
            $table->unsignedTinyInteger('webhook_tentativas')->default(0);
            $table->timestamps();

            $table->index('status');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processo_exportacoes');
    }
};
