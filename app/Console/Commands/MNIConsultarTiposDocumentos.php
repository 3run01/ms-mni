<?php

namespace App\Console\Commands;

use App\Models\Tribunal;
use App\Services\MNI\Complementar\ConsultarTipoDocumentoProcessualService;
use Illuminate\Console\Command;

class MNIConsultarTiposDocumentos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mni:consultar-tipos-documentos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consultar tipos de documentos via MNI.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $tribunais = Tribunal::where('ativo', true)->get();
        foreach ($tribunais as $tribunal) {
            $service = new ConsultarTipoDocumentoProcessualService;
            $retorno = $service->execute($tribunal->url_webservice_mni_complementar);

            // dd($retorno);
            foreach ($retorno as $tipo) {
                $tribunal->tiposDocumentos()->updateOrCreate(
                    [
                        'codigo' => $tipo->codigo,
                    ],
                    [
                        'descricao' => $tipo->descricao,
                    ]
                );
            }
        }
    }
}
