<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MediaHelp extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:help';

    /**
     * The console command description.
     */
    protected $description = 'Mostra ajuda sobre os comandos de reprocessamento de mídia';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== COMANDOS DE REPROCESSAMENTO DE MÍDIA ===');
        $this->line('');

        $this->info('📊 media:check');
        $this->comment('   Verifica o status atual dos documentos de mídia');
        $this->comment('   Mostra quantos estão baixados, pendentes ou com erro');
        $this->line('');

        $this->info('🔧 media:fix-inconsistent');
        $this->comment('   Corrige documentos marcados como baixados mas sem path');
        $this->comment('   Define status como PENDENTE e enfileira para download');
        $this->line('');

        $this->info('🧪 media:redownload-test {limit=5}');
        $this->comment('   Testa o reprocessamento com número limitado de documentos');
        $this->comment('   Útil para verificar se tudo está funcionando antes do processo completo');
        $this->comment('   Exemplo: php artisan media:redownload-test 10');
        $this->line('');

        $this->info('🚀 media:redownload {--batch=50}');
        $this->comment('   Remove do S3 e reenfileira TODOS os documentos de mídia baixados');
        $this->comment('   Processa em lotes para não sobrecarregar o sistema');
        $this->comment('   Exemplo: php artisan media:redownload --batch=100');
        $this->line('');

        $this->info('📁 Tipos de mídia processados:');
        $this->comment('   • video/mp4     → extensão .mp4');
        $this->comment('   • video/quicktime → extensão .mov');
        $this->comment('   • audio/mpeg    → extensão .mp3');
        $this->line('');

        $this->info('📋 Fluxo recomendado:');
        $this->comment('   1. php artisan media:check (verificar status)');
        $this->comment('   2. php artisan media:redownload-test 5 (testar com poucos)');
        $this->comment('   3. php artisan media:redownload --batch=50 (processar todos)');
        $this->comment('   4. php artisan media:check (verificar resultado)');
        $this->line('');

        $this->info('⚠️  IMPORTANTE:');
        $this->comment('   • O reprocessamento remove arquivos do S3 e redefine status para PENDENTE');
        $this->comment('   • Os documentos serão rebaixados pelos jobs BaixarDocumentoMNIJob');
        $this->comment('   • Usar lotes pequenos em produção para não sobrecarregar');

        return 0;
    }
}
