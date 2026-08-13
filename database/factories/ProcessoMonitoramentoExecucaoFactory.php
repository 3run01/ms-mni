<?php

namespace Database\Factories;

use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessoMonitoramentoExecucao>
 */
class ProcessoMonitoramentoExecucaoFactory extends Factory
{
    protected $model = ProcessoMonitoramentoExecucao::class;

    public function definition(): array
    {
        return [
            'monitoramento_id' => ProcessoMonitoramento::factory(),
            'iniciado_em' => now(),
            'finalizado_em' => now(),
            'status' => ProcessoMonitoramentoExecucao::STATUS_SUCESSO,
            'houve_alteracao' => false,
            'movimentos_novos' => 0,
            'documentos_novos' => 0,
            'delta' => null,
        ];
    }

    public function sucesso(): static
    {
        return $this->state([
            'status' => ProcessoMonitoramentoExecucao::STATUS_SUCESSO,
            'houve_alteracao' => true,
            'movimentos_novos' => 2,
            'documentos_novos' => 1,
            'delta' => [
                'primeira_execucao' => false,
                'houve_alteracao' => true,
                'movimentos_novos' => 2,
                'documentos_novos' => 1,
                'truncado' => false,
                'movimentos' => [],
                'documentos' => [],
            ],
        ]);
    }

    public function falha(): static
    {
        return $this->state([
            'status' => ProcessoMonitoramentoExecucao::STATUS_FALHA,
            'houve_alteracao' => false,
            'erro_resumo' => 'MNI: erro simulado',
            'delta' => null,
        ]);
    }

    public function webhookEnviado(): static
    {
        return $this->state([
            'webhook_enviado_em' => now(),
            'webhook_status_http' => 200,
        ]);
    }
}
