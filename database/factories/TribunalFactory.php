<?php

namespace Database\Factories;

use App\Models\Tribunal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tribunal>
 */
class TribunalFactory extends Factory
{
    protected $model = Tribunal::class;

    public function definition(): array
    {
        return [
            'nome' => 'Tribunal ' . fake()->unique()->company(),
            'tipo' => null,
            'login' => fake()->userName(),
            'password' => fake()->password(12),
            'url_webservice_mni' => fake()->url(),
            'url_webservice_mni_complementar' => fake()->url(),
            'ativo' => true,
            'enviar_dados_criminais' => false,
            'usar_credencial_tribunal' => false,
            'versao_mni' => '2.2.2',
        ];
    }
}
