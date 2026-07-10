<?php
// database/factories/ApiTokenFactory.php

namespace Database\Factories;

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    public function definition(): array
    {
        return [
            'name' => 'token-' . fake()->unique()->slug(2),
            'token' => ApiToken::hashToken(ApiToken::generatePlainToken()),
            'ativo' => true,
            'expires_at' => null,
            'last_used_at' => null,
        ];
    }
}
