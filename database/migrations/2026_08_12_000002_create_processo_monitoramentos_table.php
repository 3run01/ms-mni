<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processo_monitoramentos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('api_token_id')->constrained('api_tokens');
            $table->foreignId('tribunal_id')->constrained('tribunais');
            $table->string('numero_processo', 25);
            $table->unsignedSmallInteger('intervalo_horas');
            $table->foreignId('credencial_id')->nullable()->constrained('credenciais_pje');
            $table->string('callback_url', 2048);
            $table->string('callback_token', 500);
            $table->string('status', 20)->default('ativo');
            $table->timestamp('proxima_execucao_em');
            $table->timestamp('ultima_execucao_em')->nullable();
            $table->string('data_referencia', 8)->nullable();
            $table->unsignedTinyInteger('falhas_consecutivas')->default(0);
            $table->timestamp('bloqueado_ate')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'proxima_execucao_em']);
        });

        // unique parcial: 1 monitoramento vivo por (token, tribunal, processo);
        // linhas canceladas/soft-deletadas ficam fora e liberam a recriação.
        DB::statement("CREATE UNIQUE INDEX processo_monitoramentos_ativo_unico
            ON processo_monitoramentos (api_token_id, tribunal_id, numero_processo)
            WHERE deleted_at IS NULL AND status <> 'cancelado'");
    }

    public function down(): void
    {
        Schema::dropIfExists('processo_monitoramentos');
    }
};
