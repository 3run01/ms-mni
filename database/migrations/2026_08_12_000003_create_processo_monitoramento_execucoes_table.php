<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processo_monitoramento_execucoes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('monitoramento_id')->constrained('processo_monitoramentos')->cascadeOnDelete();
            $table->timestamp('iniciado_em');
            $table->timestamp('finalizado_em')->nullable();
            $table->string('status', 12);
            $table->boolean('houve_alteracao')->default(false);
            $table->unsignedInteger('movimentos_novos')->default(0);
            $table->unsignedInteger('documentos_novos')->default(0);
            $table->json('delta')->nullable();
            $table->text('erro_resumo')->nullable();
            $table->timestamp('webhook_enviado_em')->nullable();
            $table->unsignedTinyInteger('webhook_tentativas')->default(0);
            $table->unsignedSmallInteger('webhook_status_http')->nullable();
            $table->timestamps();

            $table->index(['monitoramento_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processo_monitoramento_execucoes');
    }
};
