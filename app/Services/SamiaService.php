<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SamiaService
{
    /**
     * URL base da API Samia
     */
    protected string $baseUrl;

    /**
     * Chave de API para autenticação
     */
    protected string $apiKey;

    /**
     * Tempo limite para requisições
     */
    protected int $timeout = 10000;

    /**
     * ID da origem do sistema (1 para SIM)
     */
    protected int $origemId;

    /**
     * Construtor do serviço
     */
    public function __construct()
    {
        $this->baseUrl = config('services.samia.url');
        $this->apiKey = config('services.samia.api_key');
        $this->timeout = config('services.samia.timeout', 30);
        $this->origemId = config('services.samia.origem_id', 2);
    }

    public function executarSyncBaseConhecimento(): ?array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                ])->timeout($this->timeout)
                ->asJson()
                ->post("{$this->baseUrl}/gestao/kb/sync", [
                    'knowledge_base_name' => 'sim-mni-documentos',
                ]);

            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error("Erro na execução do sync: {$response->body()}");
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Exceção na execução do sync: {$e->getMessage()}");
            return null;
        }
    }

    public function consultarSyncBaseConhecimento(): ?array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'Accept' => 'application/json',
                ])
                ->acceptJson()
                ->timeout($this->timeout)
                ->get("{$this->baseUrl}/gestao/kb/sync/status/sim-mni-documentos");

            if ($response->successful()) {
                $data = $response->json();

                // Converte completed_at para timezone America/Sao_Paulo se existir
                if (isset($data['completed_at']) && $data['completed_at']) {
                    $data['completed_at'] = \Carbon\Carbon::parse($data['completed_at'])
                        ->setTimezone('America/Sao_Paulo')
                        ->toIso8601String();
                }

                return $data;
            } else {
                Log::error("Erro na consulta de status sync: {$response->body()}");
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Exceção na consulta de status sync: {$e->getMessage()}");
            return null;
        }
    }
}
