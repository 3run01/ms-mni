<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SyncBaseConhecimentoSamiaJob;

class SyncBaseConhecimento extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'samia:sync-base-conhecimento';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consulta o status do sync da base de conhecimento na API Samia';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        SyncBaseConhecimentoSamiaJob::dispatchSync();
    }
}
