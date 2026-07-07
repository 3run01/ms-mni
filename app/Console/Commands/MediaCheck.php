<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ProcessoDocumento;

class MediaCheck extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:check';

    /**
     * The console command description.
     */
    protected $description = 'Verifica status dos documentos de mídia antes do reprocessamento';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Verificando documentos de mídia para reprocessamento...');
        $this->line('');

        // Tipos de mídia para verificar
        $mimetypes = ['video/mp4', 'video/quicktime', 'audio/mpeg'];

        foreach ($mimetypes as $mimetype) {
            $this->info("=== {$mimetype} ===");

            $baixados = ProcessoDocumento::where('mimetype', $mimetype)
                ->where('status', ProcessoDocumento::STATUS_BAIXADO)
                ->count();

            $baixadosComPath = ProcessoDocumento::where('mimetype', $mimetype)
                ->where('status', ProcessoDocumento::STATUS_BAIXADO)
                ->whereNotNull('path')
                ->count();

            $baixadosSemPath = ProcessoDocumento::where('mimetype', $mimetype)
                ->where('status', ProcessoDocumento::STATUS_BAIXADO)
                ->whereNull('path')
                ->count();

            $pendentes = ProcessoDocumento::where('mimetype', $mimetype)
                ->where('status', ProcessoDocumento::STATUS_PENDENTE)
                ->count();

            $erros = ProcessoDocumento::where('mimetype', $mimetype)
                ->where('status', ProcessoDocumento::STATUS_ERRO)
                ->count();

            $total = ProcessoDocumento::where('mimetype', $mimetype)->count();

            $this->info("Total: {$total}");
            $this->info("Baixados total: {$baixados}");
            $this->info("  - Com path (serão reprocessados): {$baixadosComPath}");
            $this->info("  - Sem path: {$baixadosSemPath}");
            $this->info("Pendentes: {$pendentes}");
            $this->info("Com erro: {$erros}");
            $this->line('');
        }

        $totalBaixadosComPath = ProcessoDocumento::whereIn('mimetype', $mimetypes)
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->whereNotNull('path')
            ->count();

        $totalBaixadosSemPath = ProcessoDocumento::whereIn('mimetype', $mimetypes)
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->whereNull('path')
            ->count();

        $this->info("RESUMO GERAL:");
        $this->info("Documentos baixados com path (serão reprocessados): {$totalBaixadosComPath}");
        $this->info("Documentos baixados sem path (inconsistência): {$totalBaixadosSemPath}");

        if ($totalBaixadosComPath > 0) {
            $this->line('');
            $this->comment('Para executar o reprocessamento, use: php artisan media:redownload');
        }

        if ($totalBaixadosSemPath > 0) {
            $this->line('');
            $this->error("ATENÇÃO: {$totalBaixadosSemPath} documentos estão marcados como baixados mas não têm path definido!");
            $this->comment('Considere investigar essa inconsistência ou usar: php artisan media:fix-inconsistent');
        }

        return 0;
    }
}
