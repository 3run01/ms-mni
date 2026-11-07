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

        if ($this->hostLiberadoForaDeProducao($parts)) {
            return;
        }

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

    /**
     * Hosts liberados (`pje.callback.hosts_liberados`, RF-17): fora de
     * `production`, o host da lista passa com `http` ou `https`, mesmo que
     * resolva para IP interno. É o que deixa o Sistema de desenvolvimento
     * receber em `http://host.docker.internal`. O host é comparado sem
     * diferenciar maiúsculas, com qualquer porta e caminho.
     *
     * O validador é um só: a regra vale para todo `callback_url` do ms-mni
     * (OCR, monitoramento, exportação e consulta assíncrona), na validação do
     * pedido (regra CallbackUrl) e antes de cada envio (CallbackNotifier). Em
     * `production` a lista é ignorada e valem só as regras de sempre.
     *
     * @param  array<string, mixed>|false  $parts  retorno de parse_url
     */
    private function hostLiberadoForaDeProducao(array|false $parts): bool
    {
        if ($parts === false || config('app.env') === 'production') {
            return false;
        }

        $esquema = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if ($host === '' || ! in_array($esquema, ['http', 'https'], true)) {
            return false;
        }

        foreach ((array) config('pje.callback.hosts_liberados', []) as $liberado) {
            if (is_string($liberado) && trim($liberado) !== '' && strcasecmp($host, trim($liberado)) === 0) {
                return true;
            }
        }

        return false;
    }
}
