<?php

namespace App\Services\Callback;

use Illuminate\Support\Facades\Http;

class CallbackNotifier
{
    /**
     * @param  array<string, string>  $headers  Headers extras (o X-API-Token sempre prevalece).
     * @return int Status HTTP da resposta bem-sucedida.
     */
    public function notificar(string $url, string $token, array $payload, array $headers = []): int
    {
        app(CallbackUrlValidator::class)->assertValida($url);

        $response = Http::withHeaders(array_merge($headers, ['X-API-Token' => $token]))
            ->timeout(10)
            ->post($url, $payload);

        if ($response->successful()) {
            return $response->status();
        }

        if ($response->status() >= 400 && $response->status() < 500) {
            throw new CallbackPermanentException(
                $response->status(),
                "Callback rejeitado (HTTP {$response->status()}): {$response->body()}"
            );
        }

        $response->throw();
    }
}
