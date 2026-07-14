<?php

namespace App\Services\Callback;

class CallbackUrlValidator
{
    public function ehValida(string $url): bool
    {
        try {
            $this->assertValida($url);
            return true;
        } catch (InvalidCallbackUrlException) {
            return false;
        }
    }

    public function assertValida(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw new InvalidCallbackUrlException('callback_url deve ser uma URL https válida');
        }

        $host = $parts['host'];

        if (strcasecmp($host, 'localhost') === 0) {
            throw new InvalidCallbackUrlException('callback_url não pode apontar para localhost');
        }

        // Resolve o host (ou usa o próprio literal se já for IP)
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidCallbackUrlException('callback_url não pode apontar para IP interno/privado');
        }
    }
}
