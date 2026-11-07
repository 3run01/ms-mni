<?php

namespace App\Jobs;

use App\Exceptions\MNIException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Processo\ProcessoService;
use Illuminate\Support\Facades\Log;

/**
 * ShouldBeEncrypted: no ms-mni, o par do PJe só fica em jobs cifrados. Sem a
 * cifra, login e senha iriam em texto claro para a fila (Redis/Horizon,
 * `jobs`) e para `failed_jobs`. Um payload antigo sem cifra, já na fila no
 * deploy, segue processável: o CallQueuedHandler desserializa direto o que
 * começa com `O:`.
 */
class BaixarProcessoMNIJob implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    public $tribunal;
    public $numero_processo;
    public $login_pje;
    public $senha_pje;
    public $data_referencia;

    /**
     * Create a new job instance.
     */
    public function __construct(
        $tribunal,
        $numero_processo,
        #[\SensitiveParameter] $login_pje = null,
        #[\SensitiveParameter] $senha_pje = null,
        $data_referencia = null
    ) {
        $this->tribunal = $tribunal;
        $this->numero_processo = $numero_processo;
        $this->login_pje = $login_pje;
        $this->senha_pje = $senha_pje;
        $this->data_referencia = $data_referencia;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $service = app(ProcessoService::class);

            $service->consultarDadosBasicos(
                $this->tribunal,
                $this->numero_processo,
                $this->login_pje,
                $this->senha_pje
            );

            $service->consultarMovimentos(
                $this->tribunal,
                $this->numero_processo,
                $this->login_pje,
                $this->senha_pje,
                $this->data_referencia
            );

            $service->consultarDocumentos(
                $this->tribunal,
                $this->numero_processo,
                $this->login_pje,
                $this->senha_pje,
                $this->data_referencia
            );

            //Dispara evento para webhook

        } catch (MNIException $e) {
            Log::error('BaixarProcessoMNIJob: ' . $this->numero_processo . ' - ' . $e->getError() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        } catch (\Exception $e) {
            Log::error('BaixarProcessoMNIJob: ' . $this->numero_processo . ' - ' . $e->getMessage() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        }
    }
}
