<?php

namespace App\Jobs;

use App\Exceptions\MNIException;
use App\Models\ProcessoDocumento;
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
            $salvarDocumentoProcessoService = app(SalvarDocumentoProcessoService::class);
            $salvarDocumentoProcessoService->baixarDocumento($this->documento, $this->login_pje, $this->senha_pje);
            $this->documento->tentativas_download++;
            $this->documento->save();
        } catch (MNIException $e) {
            $this->marcarErro($e->getError() ?: $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->marcarErro($e->getMessage());
            throw $e;
        }
    }

    /**
     * Marca o documento como erro (visivel e recuperavel pelo comando
     * mni:baixar-documento-pendente) e registra a falha. A excecao e
     * relancada pelo handle() para a fila registrar o job como falho.
     */
    private function marcarErro(string $mensagem): void
    {
        $this->documento->tentativas_download++;
        $this->documento->status = ProcessoDocumento::STATUS_ERRO;
        $this->documento->save();

        Log::error('Erro ao baixar documento: ' . $this->documento->id_documento . ' - ' . $mensagem);
    }
}
