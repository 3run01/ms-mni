<?php

namespace Database\Factories;

use App\Models\Processo;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcessoFactory extends Factory
{
    protected $model = Processo::class;

    public function definition(): array
    {
        return [
            // tribunais ficam na conexão `sim` — sem FK; sobrescrever nos
            // testes que precisam da relação com um Tribunal::factory() real
            'tribunal_id' => 999999,
            'numero_processo' => $this->faker->numerify('####################'),
            'status' => Processo::STATUS_PETICIONADO,
            'valor_causa' => 1000.50,
            'nivel_sigilo' => '0',
        ];
    }
}
