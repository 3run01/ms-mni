<?php

namespace App\Exceptions;

//use App\Models\MniLog;
use App\Services\MNI\PadronizarMensagemMniService;
use Exception;

class MNIException extends Exception
{
    public $error;
    public $code;

    public function __construct($error, $code)
    {
        // Ponto único de padronização: toda mensagem crua do MNI/PJe entra no
        // sistema por aqui e sai por getError() — API, webhook, dashboard, log.
        $this->error = PadronizarMensagemMniService::normalizar($error);
        $this->code = $code;
        //        MniLog::create(['mensagem' => $error]);
    }

    public function getError()
    {
        return "{$this->error}";
    }
}
