<?php

namespace App\Services\Callback;

use Illuminate\Support\Facades\Http;

class CallbackNotifier
{
    public function notificar(string $url, string $token, array $payload): void
    {
        app(CallbackUrlValidator::class)->assertValida($url);

        $response = Http::withHeaders(['X-API-Token' => $token])
            ->timeout(10)
            ->post($url, $payload);

        if ($response->successful()) {
            return;
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
