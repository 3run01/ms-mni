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
    public $login_pje;
    public $senha_pje;

    public function __construct($tribunal_id, $numero_processo, $login_pje = null, $senha_pje = null)
    {
        $this->numero_processo = $numero_processo;
        $this->tribunal_id = $tribunal_id;
        $this->login_pje = $login_pje;
        $this->senha_pje = $senha_pje;
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
            $this->login_pje,
            $this->senha_pje
        );

        Http::timeout(1000)->get(env('SIM_APP_URL')."/webhook/atualizar-processo/{$this->numero_processo}");
    }
}
