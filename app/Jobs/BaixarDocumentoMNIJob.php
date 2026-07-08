<?php

namespace App\Jobs;

use App\Exceptions\MNIException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Support\Facades\Log;

class BaixarDocumentoMNIJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public $priority = 2;
    public int $uniqueFor = 1800;

    private $documento;
    private $login_pje;
    private $senha_pje;

    /**
     * Create a new job instance.
     */
    public function __construct($documento, $login_pje = null, $senha_pje = null)
    {
        $this->documento = $documento;
        $this->login_pje = $login_pje;
        $this->senha_pje = $senha_pje;
    }

    public function uniqueId(): string
    {
        return (string) $this->documento->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $salvarDocumentoProcessoService = new SalvarDocumentoProcessoService();
            $salvarDocumentoProcessoService->baixarDocumento($this->documento, $this->login_pje, $this->senha_pje);
            $this->documento->tentativas_download++;
            $this->documento->save();
        } catch (MNIException $e) {
            Log::error('Erro ao baixar documento: ' . $this->documento->id_documento . ' - Processo: ' . $this->documento->processo->numero_processo . ' - ' . $e->getError() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        } catch (\Exception $e) {
            Log::error('Erro ao baixar documento: ' . $this->documento->id_documento . ' - Processo: ' . $this->documento->processo->numero_processo . ' - ' . $e->getMessage() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        }
    }
}
