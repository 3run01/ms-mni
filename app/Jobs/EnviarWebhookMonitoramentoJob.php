<?php

namespace App\Jobs;

use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use App\Services\Callback\CallbackNotifier;
use App\Services\Callback\CallbackPermanentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnviarWebhookMonitoramentoJob implements ShouldQueue
{
    use Queueable;

    public const EVENTO = 'processo.monitoramento.executado';

    public int $tries = 5;

    public function __construct(public int $execucaoId) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(CallbackNotifier $notifier): void
    {
        $execucao = DB::transaction(function () {
            $execucao = ProcessoMonitoramentoExecucao::query()->lockForUpdate()->find($this->execucaoId);

            if (! $execucao) {
                return null;
            }
            if ($execucao->jaFoiNotificado()) {
                return false;
            }

            $execucao->increment('webhook_tentativas');

            return $execucao->fresh();
        });

        if ($execucao === null) {
            Log::warning("[MonitoramentoExecucao:{$this->execucaoId}] não encontrada ao enviar callback");
            return;
        }
        if ($execucao === false) {
            return;
        }

        $monitoramento = ProcessoMonitoramento::withTrashed()->find($execucao->monitoramento_id);

        if (! $monitoramento) {
            Log::warning("[MonitoramentoExecucao:{$execucao->id}] monitoramento não encontrado");
            return;
        }

        try {
            $status = $notifier->notificar(
                $monitoramento->callback_url,
                $monitoramento->callback_token,
                $this->montarPayload($execucao, $monitoramento),
                [
                    'X-Evento' => self::EVENTO,
                    'X-Idempotency-Key' => $execucao->uuid,
                ],
            );
        } catch (CallbackPermanentException $e) {
            $execucao->update(['webhook_status_http' => $e->statusCode]);

            Log::critical("[MonitoramentoExecucao:{$execucao->id}] callback rejeitado (permanente)", [
                'status' => $e->statusCode,
                'mensagem' => $e->getMessage(),
            ]);

            $this->fail($e);
            return;
        }

        $execucao->update(['webhook_enviado_em' => now(), 'webhook_status_http' => $status]);

        Log::info("[MonitoramentoExecucao:{$execucao->id}] callback enviado");
    }

    private function montarPayload(
        ProcessoMonitoramentoExecucao $execucao,
        ProcessoMonitoramento $monitoramento
    ): array {
        $base = [
            'evento' => self::EVENTO,
            'monitoramento_id' => $monitoramento->uuid,
            'execucao_id' => $execucao->uuid,
            'numero_processo' => $monitoramento->numero_processo,
            'tribunal_id' => $monitoramento->tribunal_id,
            'executado_em' => $execucao->finalizado_em?->toIso8601String(),
            'status' => $execucao->status,
            'houve_alteracao' => $execucao->houve_alteracao,
        ];

        if ($execucao->status === ProcessoMonitoramentoExecucao::STATUS_FALHA) {
            return $base + [
                'erro_resumo' => $execucao->erro_resumo,
                'falhas_consecutivas' => $monitoramento->falhas_consecutivas,
                'monitoramento_status' => $monitoramento->status,
            ];
        }

        $delta = $execucao->delta ?? [];

        return $base + [
            'primeira_execucao' => $delta['primeira_execucao'] ?? false,
            'resumo' => [
                'movimentos_novos' => $execucao->movimentos_novos,
                'documentos_novos' => $execucao->documentos_novos,
                'truncado' => $delta['truncado'] ?? false,
            ],
            'movimentos' => $delta['movimentos'] ?? [],
            'documentos' => $delta['documentos'] ?? [],
            'proxima_execucao_em' => $monitoramento->proxima_execucao_em?->toIso8601String(),
        ];
    }

    public function failed(\Throwable $e): void
    {
        Log::critical("[MonitoramentoExecucao:{$this->execucaoId}] esgotou tentativas de callback", [
            'erro' => $e->getMessage(),
        ]);
    }
}
