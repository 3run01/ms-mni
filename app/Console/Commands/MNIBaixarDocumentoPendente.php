<?php

namespace App\Console\Commands;

use App\Models\ProcessoDocumento;
use App\Services\Processo\EnfileirarDownloadDocumentosService;
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

        // Sem credenciais: cada lote usa as cadastradas no tribunal do processo.
        $resumo = app(EnfileirarDownloadDocumentosService::class)->enfileirar($documentos);

        $this->info("Documentos em erro selecionados: {$documentos->count()}");
        $this->info("Lotes enfileirados: {$resumo['lotes']} ({$resumo['documentos_em_lote']} documentos)");
        $this->info("Vídeos enfileirados por documento: {$resumo['videos']}");
        $this->info("Ignorados por já estarem num lote em andamento: {$resumo['ignorados_trava']}");
    }
}
