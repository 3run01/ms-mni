<?php

namespace App\Jobs;

use App\Exceptions\MNIException;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Jobs\OCRRequestJob;
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
     *
     * O 4o parametro (legado, antes era $dispatchOcrOnSuccess) eh ignorado:
     * o gating do OCR agora vem do ocr_status do processo. Mantido por
     * retro-compat com chamadores que ainda passam o valor.
     */
    public function __construct($documento, $login_pje = null, $senha_pje = null, $_legacyDispatchOcrFlag = null)
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

            if ($this->documento->status === ProcessoDocumento::STATUS_BAIXADO && $this->processoTemOcrSolicitado()) {
                OCRRequestJob::dispatch($this->documento)->onQueue('ocr-request');
            }
        } catch (MNIException $e) {
            Log::error('Erro ao baixar documento: ' . $this->documento->id_documento . ' - Processo: ' . $this->documento->processo->numero_processo . ' - ' . $e->getError() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        } catch (\Exception $e) {
            Log::error('Erro ao baixar documento: ' . $this->documento->id_documento . ' - Processo: ' . $this->documento->processo->numero_processo . ' - ' . $e->getMessage() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        }
    }

    /**
     * O OCR so eh disparado se o processo foi marcado pelo /processo/ocr.
     * Fluxos de /processo/consultar e /processo/visualizar nao setam
     * ocr_status, entao o download ocorre mas o OCR nao.
     */
    private function processoTemOcrSolicitado(): bool
    {
        $processo = $this->documento->processo;

        if (!$processo) {
            return false;
        }

        return in_array($processo->ocr_status, [
            Processo::OCR_STATUS_PENDENTE,
            Processo::OCR_STATUS_PROCESSANDO,
            Processo::OCR_STATUS_CONCLUIDO,
        ], true);
    }
}
