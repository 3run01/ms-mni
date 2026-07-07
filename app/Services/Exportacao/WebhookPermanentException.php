<?php

namespace App\Services\Exportacao;

use Exception;

class WebhookPermanentException extends Exception
{
    public function __construct(public readonly int $statusCode, string $message = '')
    {
        parent::__construct($message ?: "Webhook permanently rejected (HTTP {$statusCode})");
    }
}
