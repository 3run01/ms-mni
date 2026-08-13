<?php

namespace App\Services\Monitoramento;

use App\Models\ApiToken;
use App\Models\CredencialPje;
use App\Models\Tribunal;

class CredencialPjeService
{
    /**
     * Par incompleto é descartado inteiro (mesma regra atômica do
     * InjectCredenciaisPjePadrao): sem par completo → null → o job usa o
     * par padrão do .env na hora da execução.
     */
    public function resolver(ApiToken $token, Tribunal $tribunal, ?string $login, ?string $senha): ?CredencialPje
    {
        if (blank($login) || blank($senha)) {
            return null;
        }

        $credencial = CredencialPje::query()
            ->where('api_token_id', $token->id)
            ->where('tribunal_id', $tribunal->id)
            ->where('login_hash', CredencialPje::hashLogin($login))
            ->first();

        if ($credencial) {
            if ($credencial->senha !== $senha) {
                $credencial->update(['senha' => $senha]);
            }

            return $credencial;
        }

        return CredencialPje::create([
            'api_token_id' => $token->id,
            'tribunal_id' => $tribunal->id,
            'login' => $login,
            'senha' => $senha,
            'login_hash' => CredencialPje::hashLogin($login),
        ]);
    }
}
