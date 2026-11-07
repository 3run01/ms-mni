<?php

namespace App\Jobs;

use App\Models\Tribunal;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Processo\ProcessoService;
use App\Services\Callback\CallbackNotifier;

/**
 * ShouldBeEncrypted: no ms-mni, o par do PJe só fica em jobs cifrados. Sem a
 * cifra, login e senha iriam em texto claro para a fila (Redis/Horizon,
 * `jobs`) e para `failed_jobs`. Um payload antigo sem cifra, já na fila no
 * deploy, segue processável: o CallQueuedHandler desserializa direto o que
 * começa com `O:`.
 */
class ConsultarDadosBasicosProcessoMNIJob implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public $numero_processo;
    public $tribunal_id;
    public $login_pje;
    public $senha_pje;

    public function __construct(
        $tribunal_id,
        $numero_processo,
        #[\SensitiveParameter] $login_pje = null,
        #[\SensitiveParameter] $senha_pje = null,
        public ?string $callback_url = null,
        public ?string $callback_token = null,
    )
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
