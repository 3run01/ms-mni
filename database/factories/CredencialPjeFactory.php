<?php

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\CredencialPje;
use App\Models\Tribunal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CredencialPje>
 */
class CredencialPjeFactory extends Factory
{
    protected $model = CredencialPje::class;

    public function definition(): array
    {
        return [
            'api_token_id' => ApiToken::factory(),
            'tribunal_id' => Tribunal::factory(),
            'login' => fake()->numerify('###########'),
            'senha' => fake()->password(12),
            'ativo' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CredencialPje $credencial) {
            $credencial->login_hash = $credencial->login_hash ?? CredencialPje::hashLogin($credencial->login);
        });
    }
}
