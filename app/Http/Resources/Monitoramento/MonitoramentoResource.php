<?php

namespace App\Http\Resources\Monitoramento;

use Illuminate\Http\Resources\Json\JsonResource;

class MonitoramentoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'numero_processo' => $this->numero_processo,
            'tribunal_id' => $this->tribunal_id,
            'intervalo_horas' => $this->intervalo_horas,
            'status' => $this->status,
            'callback_url' => $this->callback_url,
            'proxima_execucao_em' => $this->proxima_execucao_em?->toIso8601String(),
            'ultima_execucao_em' => $this->ultima_execucao_em?->toIso8601String(),
            'falhas_consecutivas' => $this->falhas_consecutivas,
            'credencial' => $this->credencial
                ? [
                    'uuid' => $this->credencial->uuid,
                    'login_mascarado' => $this->credencial->login_mascarado,
                ]
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'execucoes' => MonitoramentoExecucaoResource::collection($this->whenLoaded('execucoes')),
        ];
    }
}
