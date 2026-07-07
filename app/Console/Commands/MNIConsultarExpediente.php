<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MNI\Intercomunicacao\ConsultarExpedienteService;

class MNIConsultarExpediente extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mni:consultar-expedientes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consultar expedientes pendentes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = new ConsultarExpedienteService();
        $expedientes = $service->execute();
    }
}
