<?php

namespace App\Exceptions;

use Exception;

class JsonException extends Exception
{
    public $errors;
    public $code;

    public function __construct(array $errors, $code)
    {
        $this->errors = $errors;
        $this->code = $code;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
