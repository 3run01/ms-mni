<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * OCR do processo (DEP-03 a DEP-05): processamentos, assinantes e avisos,
 * mais a situação do OCR em cada documento.
 *
 * Nenhuma tabela guarda o par do PJe (RNF-01): o par só existe nos jobs
 * cifrados. As colunas `ocr_*` de `processo_documentos` são novas e não
 * reaproveitam as que a migration 2026_07_07_000000 derrubou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processo_ocr_processamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->string('situacao', 20)->default('em_andamento')->index();
            $table->string('codigo_falha', 50)->nullable();
            $table->timestamp('aberto_em');
            $table->timestamp('ultimo_progresso_em')->nullable();
            $table->timestamp('finalizado_em')->nullable();
            $table->timestamps();
        });

        // Rede de segurança da trava do pedido (RF-06): no máximo 1
        // processamento em andamento por processo, que já implica a linha
        // (tribunal_id). Se a trava do cache vencer durante uma consulta
        // longa ao MNI, o 2º insert falha aqui e o pedido vira carona.
        // Concluídos e falhos ficam fora e não impedem a reabertura.
        DB::statement("CREATE UNIQUE INDEX processo_ocr_processamentos_aberto_unico
            ON processo_ocr_processamentos (processo_id)
            WHERE situacao = 'em_andamento'");

        Schema::create('processo_ocr_assinantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processamento_id')->constrained('processo_ocr_processamentos')->cascadeOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->boolean('dono')->default(false);
            $table->string('callback_url', 2048);
            // Cifrado pelo cast `encrypted` do model: o texto cifrado não cabe em 255.
            $table->text('callback_token');
            // sha256 de callback_url + "\n" + callback_token: o token cifrado
            // muda a cada gravação e não serve para achar o assinante repetido.
            $table->char('callback_hash', 64);
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamp('aviso_final_entregue_em')->nullable();
            $table->timestamps();

            $table->unique(['processamento_id', 'callback_hash']);
        });

        Schema::create('processo_ocr_avisos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('assinante_id')->constrained('processo_ocr_assinantes')->cascadeOnDelete();
            $table->boolean('final')->default(false);
            // Congelado na geração: as contagens do momento (RF-14).
            $table->json('payload');
            $table->timestamp('enviado_em')->nullable();
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->smallInteger('status_http')->nullable();
            $table->timestamps();

            $table->index(['assinante_id', 'final']);
        });

        Schema::table('processo_documentos', function (Blueprint $table) {
            // null e `pendente` contam como pendente; `enviado` é o
            // "aguardando retorno"; `processado`; `erro` é o com_erro.
            $table->string('ocr_situacao', 20)->nullable();
            // Só o job atual é conhecido pelo webhook do workflow.
            $table->string('ocr_job_id', 64)->nullable()->index();
            // Job antigo a cancelar (DELETE jobs/{job_id}/chunks) antes do reenvio.
            $table->string('ocr_job_id_cancelar', 64)->nullable();
            // Base da idade A do documento aguardando retorno (RF-18).
            $table->timestamp('ocr_enviado_em')->nullable();
            $table->timestamp('ocr_concluido_em')->nullable();
            // Resumo da falha, sem credencial.
            $table->string('ocr_erro', 1000)->nullable();

            $table->index(['processo_id', 'ocr_situacao']);
        });
    }

    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropIndex(['processo_id', 'ocr_situacao']);
            $table->dropIndex(['ocr_job_id']);
            $table->dropColumn([
                'ocr_situacao',
                'ocr_job_id',
                'ocr_job_id_cancelar',
                'ocr_enviado_em',
                'ocr_concluido_em',
                'ocr_erro',
            ]);
        });

        Schema::dropIfExists('processo_ocr_avisos');
        Schema::dropIfExists('processo_ocr_assinantes');
        Schema::dropIfExists('processo_ocr_processamentos');
    }
};
