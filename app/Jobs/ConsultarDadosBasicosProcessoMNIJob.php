<?php

namespace App\Jobs;

use App\Models\Tribunal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Processo\ProcessoService;
use App\Services\Callback\CallbackNotifier;

class ConsultarDadosBasicosProcessoMNIJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public $numero_processo;
    public $tribunal_id;
    public $login_pje;
    public $senha_pje;

    public function __construct($tribunal_id, $numero_processo, $login_pje = null, $senha_pje = null, public ?string $callback_url = null, public ?string $callback_token = null)
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
        $processoService = app(ProcessoService::class);
        $processo = $processoService->consultarDadosBasicos(
            Tribunal::find($this->tribunal_id),
            $this->numero_processo,
            $this->login_pje,
            $this->senha_pje
        );

        app(CallbackNotifier::class)->notificar($this->callback_url, $this->callback_token, [
            'numero_processo' => $this->numero_processo,
            'tipo' => 'dados-basicos',
            'status' => 'concluido',
        ]);
    }
}
