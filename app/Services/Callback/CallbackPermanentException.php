<?php

namespace App\Services\Callback;

use Exception;

class CallbackPermanentException extends Exception
{
    public function __construct(public readonly int $statusCode, string $message = '')
    {
        parent::__construct($message ?: "Callback permanently rejected (HTTP {$statusCode})");
    }
}
