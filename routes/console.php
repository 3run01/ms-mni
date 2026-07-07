<?php

ini_set('memory_limit', '2048M');

use App\Models\Tribunal;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use App\Services\MNI\Intercomunicacao\ConsultarProcessoService;

// Schedule::command('samia:sync-base-conhecimento')->everyMinute();
Schedule::command('ocr:poll-status')->everyMinute()->withoutOverlapping();

Artisan::command('teste-executar-sync-base-conhecimento', function(){
    $sync = samia()->executarSyncBaseConhecimento();
    dd($sync);
});


Artisan::command('teste-consultar-processo', function(){
    $numero_processo = '60000038120268030000';
    $tribunal = Tribunal::find(6);
    $service = new ConsultarProcessoService();
    $retorno = $service->execute($tribunal, $numero_processo);
    dd($retorno);
});
