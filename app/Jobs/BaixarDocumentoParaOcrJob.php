<?php

namespace App\Jobs;

use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrProcessamento;
use App\Services\MNI\ClassificarFalhaMniService;
use App\Services\Ocr\ProcessamentoOcrService;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Baixa do MNI, com o par do dono do processamento, um documento pendente do
 * OCR e o entrega ao envio ao workflow (RF-09). O binário é gravado como no
 * download de hoje (`SalvarDocumentoProcessoService::baixarDocumento`: mesmo
 * `path` no S3 e `status` = `baixado`), para que `/documento/visualizar`
 * sirva o documento depois sem nova chamada ao MNI.
 *
 * ShouldBeEncrypted: o par do PJe só fica em job cifrado (RNF-01). O envio
 * (EnviarDocumentoAoOcrJob) leva só os ids.
 *
 * Recusa do par (RF-10, D7): o job encerra o processamento como `falhou` e
 * retorna sem relançar. Assim, não vai a `failed_jobs`, o documento fica
 * pendente (não conta em `com_erro`) e os downloads que ainda estão na fila
 * saem no item 1 do handle(), sem chamar o MNI com o par recusado.
 */
class BaixarDocumentoParaOcrJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const FILA = 'ocr-download';

    public int $tries = 3;

    public int $timeout = 300;

    public int $processamentoId;

    public int $documentoId;

    private string $login;

    private string $senha;

    public function __construct(
        int $processamentoId,
        int $documentoId,
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $senha,
    ) {
        $this->processamentoId = $processamentoId;
        $this->documentoId = $documentoId;
        $this->login = $login;
        $this->senha = $senha;

        $this->onQueue(self::FILA);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ProcessamentoOcrService $processamentos, SalvarDocumentoProcessoService $salvarDocumento): void
    {
        $prefixo = "[ProcessamentoOcr:{$this->processamentoId}]";

        // 1. Processamento encerrado (recusa do par, prazo ou concluído): sai
        //    com 0 SOAP (RF-10 item 2). O documento fica como está e segue a
        //    regra da abertura seguinte.
        $processamento = ProcessoOcrProcessamento::find($this->processamentoId);

        if ($processamento?->situacao !== ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO) {
            Log::info("{$prefixo} download do documento {$this->documentoId} para o OCR ignorado: processamento encerrado");

            return;
        }

        $documento = ProcessoDocumento::find($this->documentoId);

        if ($documento === null || (int) $documento->processo_id !== (int) $processamento->processo_id) {
            Log::warning("{$prefixo} documento {$this->documentoId} não pertence ao processo do processamento; download ignorado");

            return;
        }

        // 2. Processado nunca volta ao workflow.
        if ($documento->ocr_situacao === ProcessoDocumento::OCR_PROCESSADO) {
            return;
        }

        // 3. Já `baixado` com o objeto no S3 (R2-1): direto ao envio, com 0
        //    SOAP. Vale também para o HTML legado (NC-2), que vai pelo PDF já
        //    gravado: o baixarDocumento() reconsultaria o MNI para gravar o
        //    .html, e o fluxo do OCR não precisa dele.
        if ($this->temObjetoNoS3($documento)) {
            $this->despacharEnvio($documento);

            return;
        }

        // 4. Download com o par do dono.
        try {
            $salvarDocumento->baixarDocumento($documento, $this->login, $this->senha);
        } catch (Throwable $e) {
            if (ClassificarFalhaMniService::recusaNaCadeia($e)) {
                // RF-10: encerra antes de liberar os outros downloads, que
                // conferem a situação no item 1. Sem relançar e sem
                // `com_erro`: o documento fica pendente para o pedido seguinte.
                $processamentos->encerrarPorRecusa($this->processamentoId);

                Log::warning("{$prefixo} o tribunal recusou o par do dono no download do documento {$documento->id}; processamento encerrado");

                return;
            }

            // Só a classe: a mensagem pode trazer o texto cru do tribunal.
            Log::warning("[ProcessoDocumento:{$documento->id}] download do OCR falhou", [
                'processamento_id' => $this->processamentoId,
                'erro' => class_basename($e),
                'tentativa' => $this->attempts(),
            ]);

            throw $e;
        }

        $this->despacharEnvio($documento);
    }

    /**
     * Tentativas esgotadas sem binário (RF-09): o documento vira `com_erro`
     * e gera o aviso, se o processamento ainda está aberto. Um processamento
     * encerrado (pela recusa, por exemplo) não muda: o documento segue a
     * regra da abertura seguinte. `registrarErroDefinitivo` não rebaixa um
     * processado.
     */
    public function failed(?Throwable $e): void
    {
        $processamento = ProcessoOcrProcessamento::find($this->processamentoId);

        if ($processamento?->situacao !== ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO) {
            return;
        }

        $documento = ProcessoDocumento::find($this->documentoId);

        if ($documento === null || (int) $documento->processo_id !== (int) $processamento->processo_id) {
            return;
        }

        // Só a classe da falha: o texto do tribunal fica fora da tabela (RNF-01).
        $motivo = $e !== null ? class_basename($e) : 'motivo desconhecido';

        app(ProcessamentoOcrService::class)->registrarErroDefinitivo(
            $documento,
            "Download do MNI para o OCR esgotou as tentativas: {$motivo}",
        );

        Log::error("[ProcessamentoOcr:{$this->processamentoId}] download do documento {$this->documentoId} para o OCR esgotou as tentativas", [
            'erro' => $e !== null ? class_basename($e) : null,
        ]);
    }

    private function temObjetoNoS3(ProcessoDocumento $documento): bool
    {
        return $documento->status === ProcessoDocumento::STATUS_BAIXADO
            && filled($documento->path)
            && Storage::disk('s3')->exists($documento->path);
    }

    /**
     * O envio leva só os ids (RNF-01) e confere de novo o objeto no S3.
     */
    private function despacharEnvio(ProcessoDocumento $documento): void
    {
        EnviarDocumentoAoOcrJob::dispatch($this->processamentoId, $documento->id)
            ->onQueue(EnviarDocumentoAoOcrJob::FILA);
    }
}
