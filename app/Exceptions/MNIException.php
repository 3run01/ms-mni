<?php

namespace App\Exceptions;

//use App\Models\MniLog;
use Exception;

class MNIException extends Exception
{
    public $error;
    public $code;

    public function __construct($error, $code)
    {
        $this->error = $error;
        $this->code = $code;
        //        MniLog::create(['mensagem' => $error]);
    }

    public function getError()
    {
        return "{$this->error}";
    }
}
