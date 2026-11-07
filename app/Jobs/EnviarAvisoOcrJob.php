<?php

namespace App\Jobs;

use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Services\Callback\CallbackNotifier;
use App\Services\Callback\CallbackPermanentException;
use App\Services\Callback\InvalidCallbackUrlException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entrega um aviso `ocr_progresso` (CT-02) ao callback de um assinante, no
 * padrão de EnviarWebhookMonitoramentoJob (RF-16):
 * - 2xx: aviso entregue; no final, o assinante deixa de receber (RF-15);
 * - 4xx ou URL recusada pelo validador: o assinante fica encerrado, sem
 *   nova tentativa, e não recebe mais nada desse processamento;
 * - 5xx, tempo esgotado ou falha de rede: relança para a fila tentar de novo.
 *
 * Um job por (assinante, aviso): a falha de um assinante não atrasa os
 * outros. Leva só o id do aviso, nunca o par do PJe nem o callback_token.
 */
class EnviarAvisoOcrJob implements ShouldQueue
{
    use Queueable;

    public const EVENTO = 'processo.ocr.progresso';

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $avisoId) {}

    /**
     * O mesmo backoff do webhook de monitoramento (RF-16).
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(CallbackNotifier $notifier): void
    {
        $preparado = DB::transaction(function () {
            $aviso = ProcessoOcrAviso::query()->lockForUpdate()->find($this->avisoId);

            if (! $aviso) {
                return null;
            }

            $assinante = ProcessoOcrAssinante::query()->lockForUpdate()->find($aviso->assinante_id);

            if (! $assinante) {
                return null;
            }

            if ($this->naoDeveEnviar($aviso, $assinante)) {
                return false;
            }

            $aviso->increment('tentativas');

            return [$aviso->fresh(), $assinante];
        });

        if ($preparado === null) {
            Log::warning("[ProcessoOcrAviso:{$this->avisoId}] aviso ou assinante não encontrado ao enviar");

            return;
        }

        if ($preparado === false) {
            return;
        }

        /** @var ProcessoOcrAviso $aviso */
        /** @var ProcessoOcrAssinante $assinante */
        [$aviso, $assinante] = $preparado;
        $prefixo = "[ProcessamentoOcr:{$assinante->processamento_id}]";

        try {
            $status = $notifier->notificar(
                $assinante->callback_url,
                $assinante->callback_token,
                $aviso->payload,
                [
                    'X-Evento' => self::EVENTO,
                    'X-Idempotency-Key' => $aviso->uuid,
                ],
            );
        } catch (CallbackPermanentException $e) {
            $this->encerrarAssinante($aviso, $assinante, $e->statusCode);

            Log::critical("{$prefixo} aviso {$aviso->id} rejeitado pelo callback (permanente); assinante {$assinante->id} encerrado", [
                'status' => $e->statusCode,
            ]);

            $this->fail($e);

            return;
        } catch (InvalidCallbackUrlException $e) {
            $this->encerrarAssinante($aviso, $assinante, null);

            Log::critical("{$prefixo} aviso {$aviso->id} com callback_url recusada; assinante {$assinante->id} encerrado", [
                'motivo' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        DB::transaction(function () use ($aviso, $assinante, $status) {
            $aviso->update(['enviado_em' => now(), 'status_http' => $status]);

            // Entregue o final, as novas tentativas pendentes dos avisos
            // anteriores desse assinante saem sem enviar (Q-03).
            if ($aviso->final) {
                ProcessoOcrAssinante::query()
                    ->whereKey($assinante->id)
                    ->whereNull('aviso_final_entregue_em')
                    ->update(['aviso_final_entregue_em' => now()]);
            }
        });

        Log::info("{$prefixo} aviso {$aviso->id} entregue ao assinante {$assinante->id}", [
            'status' => $status,
            'final' => $aviso->final,
        ]);
    }

    /**
     * Já entregue; assinante encerrado por 4xx; ou aviso não final de um
     * assinante cujo final já foi entregue (Q-03): nenhum aviso chega ao
     * Sistema depois do final.
     */
    private function naoDeveEnviar(ProcessoOcrAviso $aviso, ProcessoOcrAssinante $assinante): bool
    {
        return $aviso->enviado_em !== null
            || $assinante->encerrado_em !== null
            || (! $aviso->final && $assinante->aviso_final_entregue_em !== null);
    }

    private function encerrarAssinante(ProcessoOcrAviso $aviso, ProcessoOcrAssinante $assinante, ?int $statusHttp): void
    {
        DB::transaction(function () use ($aviso, $assinante, $statusHttp) {
            $aviso->update(['status_http' => $statusHttp]);

            ProcessoOcrAssinante::query()
                ->whereKey($assinante->id)
                ->whereNull('encerrado_em')
                ->update(['encerrado_em' => now()]);
        });
    }

    /**
     * Tentativas esgotadas (5xx, tempo, rede) ou rejeição permanente. Só o
     * tipo e o status vão ao log: a mensagem das exceções HTTP traz o corpo
     * da resposta do callback.
     */
    public function failed(\Throwable $e): void
    {
        $processamentoId = ProcessoOcrAviso::query()
            ->whereKey($this->avisoId)
            ->join('processo_ocr_assinantes', 'processo_ocr_assinantes.id', '=', 'processo_ocr_avisos.assinante_id')
            ->value('processo_ocr_assinantes.processamento_id');

        $prefixo = $processamentoId !== null
            ? "[ProcessamentoOcr:{$processamentoId}]"
            : "[ProcessoOcrAviso:{$this->avisoId}]";

        Log::critical("{$prefixo} aviso {$this->avisoId} não entregue", array_filter([
            'erro' => class_basename($e),
            'status' => match (true) {
                $e instanceof CallbackPermanentException => $e->statusCode,
                $e instanceof RequestException => $e->response->status(),
                default => null,
            },
        ], fn ($valor) => $valor !== null));
    }
}
