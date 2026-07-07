<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use App\Services\Processo\ProcessoService;
use App\Models\Tribunal;

class ConsultarMovimentosProcessoMNIJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public $numero_processo;
    public $tribunal_id;

    public function __construct($tribunal_id, $numero_processo)
    {
        $this->numero_processo = $numero_processo;
        $this->tribunal_id = $tribunal_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processoService = new ProcessoService();
        $processo = $processoService->consultarMovimentos(
            Tribunal::find($this->tribunal_id),
            $this->numero_processo,
            $request->login_pje ?? null,
            $request->senha_pje ?? null
        );


        Http::timeout(1000)->get(env('SIM_APP_URL')."/webhook/atualizar-processo/{$this->numero_processo}");

    }
}
