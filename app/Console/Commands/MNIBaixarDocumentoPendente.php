<?php

namespace App\Console\Commands;

use App\Jobs\BaixarDocumentoMNIJob;
use App\Models\ProcessoDocumento;
use Illuminate\Console\Command;

class MNIBaixarDocumentoPendente extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mni:baixar-documento-pendente';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Baixar documentos pendentes dos processos';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $documentos = ProcessoDocumento::whereIn('status', [ProcessoDocumento::STATUS_ERRO])->get();
        foreach ($documentos as $documento) {
            BaixarDocumentoMNIJob::dispatch($documento)->onQueue('mni-download');
        }
    }
}
