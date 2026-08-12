<?php

namespace App\Http\Resources\Monitoramento;

use Illuminate\Http\Resources\Json\JsonResource;

class MonitoramentoExecucaoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'houve_alteracao' => $this->houve_alteracao,
            'movimentos_novos' => $this->movimentos_novos,
            'documentos_novos' => $this->documentos_novos,
            'erro_resumo' => $this->erro_resumo,
            'iniciado_em' => $this->iniciado_em?->toIso8601String(),
            'finalizado_em' => $this->finalizado_em?->toIso8601String(),
            'webhook_enviado_em' => $this->webhook_enviado_em?->toIso8601String(),
            'webhook_status_http' => $this->webhook_status_http,
        ];
    }
}
