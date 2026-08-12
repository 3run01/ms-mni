<?php

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\ProcessoMonitoramento;
use App\Models\Tribunal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessoMonitoramento>
 */
class ProcessoMonitoramentoFactory extends Factory
{
    protected $model = ProcessoMonitoramento::class;

    public function definition(): array
    {
        return [
            'api_token_id' => ApiToken::factory(),
            'tribunal_id' => Tribunal::factory(),
            'numero_processo' => fake()->unique()->numerify('####################'),
            'intervalo_horas' => 6,
            'credencial_id' => null,
            'callback_url' => 'https://cliente.exemplo.gov.br/webhook',
            'callback_token' => 'tok-' . fake()->sha1(),
            'status' => ProcessoMonitoramento::STATUS_ATIVO,
            'proxima_execucao_em' => now()->addHours(6),
            'bloqueado_ate' => null,
        ];
    }

    public function vencido(): static
    {
        return $this->state(['proxima_execucao_em' => now()->subMinute()]);
    }

    public function pausado(): static
    {
        return $this->state(['status' => ProcessoMonitoramento::STATUS_PAUSADO]);
    }

    public function suspenso(): static
    {
        return $this->state(['status' => ProcessoMonitoramento::STATUS_SUSPENSO]);
    }
}
