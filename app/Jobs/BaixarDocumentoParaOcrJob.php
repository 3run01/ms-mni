<?php

namespace App\Jobs;

use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrProcessamento;
use App\Services\MNI\ClassificarFalhaMniService;
use App\Services\Ocr\FormatosOcr;
use App\Services\Ocr\ProcessamentoOcrService;
use App\Services\Ocr\ResultadoRevelacaoOcr;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Closure;
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
 * Mimetype vazio (DEP-07 (c); RF-09 a RF-13): o documento que o tribunal não
 * informou na lista é sempre pedido ao tribunal, mesmo `baixado` no S3, e o
 * mimetype da resposta decide entre o envio, `ignorados` com aviso e
 * `com_erro` (`ProcessamentoOcrService::registrarMimetypeRevelado`, sob as
 * travas). Um mimetype conhecido fora da lista nunca vai ao envio: o job sai
 * sem SOAP e só encerra o processamento quando nada mais resta (Q-05).
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

        // 3. Mimetype não vazio fora da lista que não foi revelado neste job,
        //    por exemplo regravado pela lista enquanto o documento esperava
        //    na fila (RF-13, Q-05).
        if (FormatosOcr::ehIgnorado($documento->mimetype)) {
            $this->sairForaDaLista($documento, $processamentos);

            return;
        }

        // 4. Mimetype não informado pelo tribunal na lista (RF-09): pede o
        //    documento ao tribunal com o par do dono, mesmo `baixado` no S3,
        //    e decide pelo mimetype da resposta, gravado sob as travas.
        if ($documento->mimetype === FormatosOcr::MIMETYPE_NAO_INFORMADO) {
            $revelado = null;

            $baixou = $this->baixarComOParDoDono($documento, $processamentos, function () use ($salvarDocumento, $documento, &$revelado) {
                $revelado = $salvarDocumento->baixarRevelandoMimetype($documento, $this->login, $this->senha);
            });

            if (! $baixou) {
                return;
            }

            $resultado = $processamentos->registrarMimetypeRevelado($documento, (string) $revelado);

            if ($resultado === ResultadoRevelacaoOcr::Enviar) {
                $this->despacharEnvio($documento);

                return;
            }

            // `Ignorado` e `SemMimetype` já avisaram e, se nada resta,
            // encerraram o processamento (RF-11, RF-12).
            if ($resultado !== ResultadoRevelacaoOcr::JaRevelado) {
                return;
            }

            // `JaRevelado`: outro caminho gravou o mimetype entre a resposta
            // do tribunal e a trava. A decisão segue pelo valor gravado, sem
            // nova chamada SOAP e sem aviso desta revelação (RNF-05).
            $documento->refresh();

            if (FormatosOcr::ehIgnorado($documento->mimetype)) {
                $this->sairForaDaLista($documento, $processamentos);

                return;
            }

            if (! FormatosOcr::ehLegivel($documento->mimetype)) {
                // Ainda `''`: o `ocr_job_id` mudou desde a leitura, e quem o
                // mudou cuida do documento. Nada a enviar.
                Log::warning("[ProcessoDocumento:{$documento->id}] mimetype ainda não informado depois da revelação; envio ao OCR não despachado", [
                    'processamento_id' => $this->processamentoId,
                ]);

                return;
            }
        }

        // 5. Legível já `baixado` com o objeto no S3 (R2-1): direto ao envio,
        //    com 0 SOAP. Vale também para o HTML legado (NC-2), que vai pelo
        //    PDF já gravado: o baixarDocumento() reconsultaria o MNI para
        //    gravar o .html, e o fluxo do OCR não precisa dele.
        if ($this->temObjetoNoS3($documento)) {
            $this->despacharEnvio($documento);

            return;
        }

        // 6. Legível: download com o par do dono, pelo mimetype gravado.
        $baixou = $this->baixarComOParDoDono($documento, $processamentos, function () use ($salvarDocumento, $documento) {
            $salvarDocumento->baixarDocumento($documento, $this->login, $this->senha);
        });

        if ($baixou) {
            $this->despacharEnvio($documento);
        }
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

    /**
     * O atalho do objeto no S3 só vale para mimetype da lista legível
     * (RF-13): o `''` precisa da chamada ao tribunal que revela o mimetype,
     * e o fora da lista nunca vai ao envio.
     */
    private function temObjetoNoS3(ProcessoDocumento $documento): bool
    {
        return FormatosOcr::ehLegivel($documento->mimetype)
            && $documento->status === ProcessoDocumento::STATUS_BAIXADO
            && filled($documento->path)
            && Storage::disk('s3')->exists($documento->path);
    }

    /**
     * Roda o download com o par do dono (RF-09). A recusa do par encerra o
     * processamento como `falhou` e devolve `false`, sem relançar (RF-10,
     * D7): não vai a `failed_jobs`, o documento fica pendente (não conta em
     * `com_erro`) e os downloads ainda na fila saem no item 1 do handle(),
     * sem chamar o MNI com o par recusado. As demais falhas relançam para a
     * nova tentativa; esgotadas, `failed()` marca o erro.
     *
     * @param  Closure(): void  $download
     */
    private function baixarComOParDoDono(ProcessoDocumento $documento, ProcessamentoOcrService $processamentos, Closure $download): bool
    {
        try {
            $download();
        } catch (Throwable $e) {
            if (ClassificarFalhaMniService::recusaNaCadeia($e)) {
                $processamentos->encerrarPorRecusa($this->processamentoId);

                Log::warning("[ProcessamentoOcr:{$this->processamentoId}] o tribunal recusou o par do dono no download do documento {$documento->id}; processamento encerrado");

                return false;
            }

            // Só a classe: a mensagem pode trazer o texto cru do tribunal.
            Log::warning("[ProcessoDocumento:{$documento->id}] download do OCR falhou", [
                'processamento_id' => $this->processamentoId,
                'erro' => class_basename($e),
                'tentativa' => $this->attempts(),
            ]);

            throw $e;
        }

        return true;
    }

    /**
     * Saída Q-05 (RF-13; DP-07): mimetype conhecido fora da lista que este
     * job não revelou. Sem SOAP, sem envio e sem aviso intermediário; o único
     * aviso possível é o final, quando nada mais resta no processamento.
     */
    private function sairForaDaLista(ProcessoDocumento $documento, ProcessamentoOcrService $processamentos): void
    {
        $concluido = $processamentos->concluirSeNadaResta($this->processamentoId);

        Log::info("[ProcessoDocumento:{$documento->id}] mimetype fora da lista no download do OCR; documento não baixado nem enviado", [
            'mimetype' => $documento->mimetype,
            'processamento_id' => $this->processamentoId,
            'processamento_concluido' => $concluido,
        ]);
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
