<?php

namespace App\Services\Ocr;

use App\Exceptions\FalhaTransitoriaWorkflowOcrException;
use App\Jobs\EnviarDocumentoAoOcrJob;
use App\Jobs\VerificarReenvioOcrJob;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrProcessamento;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Acompanhamento de reserva dos processamentos de OCR em andamento (T18;
 * RF-13, RF-18 item 3, CT-05). Roda a cada minuto pelo `ocr:acompanhar`,
 * com ordem fixa em cada rodada (R4-1), para cada processamento aberto e
 * com `base = max(aberto_em, ultimo_progresso_em)`:
 *
 * 1. Se P venceu (`now() ≥ base + P`), consulta o job (CT-05) de cada
 *    documento do processo aguardando retorno, inclusive os enviados em
 *    processamentos anteriores, e aplica o resultado pelas regras de RF-12:
 *    `success` → processado; `failed`, `cancelled` ou 404 → `com_erro`;
 *    `pending`, `running` ou falha da consulta → nada. Se ainda sobra
 *    documento pendente ou aguardando retorno, o processamento termina
 *    `falhou`, sem `codigo` (RF-13 item 2). A rodada para ali: sem consulta
 *    de reenvio e sem reenvio (R4-1, LIM-02).
 * 2. Senão, cada documento aguardando retorno com idade ≥ A vai para
 *    VerificarReenvioOcrJob na fila `ocr-envio` (D9), que consulta o job
 *    antigo e decide entre reenviar, aguardar ou marcar processado (R5-1,
 *    R6-1 a R6-3, R7-1). A rodada não devolve nada à fila: quem devolve é o
 *    job, só depois da consulta; e o ShouldBeUnique dele evita consultas
 *    acumuladas entre rodadas.
 *
 * O envio e o reenvio não contam como progresso (R4-1): só o retorno muda
 * `ultimo_progresso_em` (ProcessamentoOcrService).
 */
class AcompanharProcessamentosOcrService
{
    public function __construct(
        private readonly ClienteWorkflowOcr $workflow,
        private readonly ProcessamentoOcrService $processamentos,
    ) {}

    /**
     * Uma rodada sobre todos os processamentos em andamento. A falha de um
     * processamento é registrada e não impede os outros.
     *
     * @return array{processamentos: int, vencidos: int, encerrados: int, consultas: int, verificacoes: int, falhas: int}
     */
    public function rodada(): array
    {
        $resumo = [
            'processamentos' => 0,
            'vencidos' => 0,
            'encerrados' => 0,
            'consultas' => 0,
            'verificacoes' => 0,
            'falhas' => 0,
        ];

        $ids = ProcessoOcrProcessamento::query()
            ->emAndamento()
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            // Relido aqui, e não na lista: a rodada anterior pode ter levado
            // tempo nas consultas, e o retorno pode ter chegado nesse meio.
            $processamento = ProcessoOcrProcessamento::query()->emAndamento()->find($id);

            if ($processamento === null) {
                continue;
            }

            $resumo['processamentos']++;

            try {
                if ($this->prazoVenceu($processamento)) {
                    $resumo['vencidos']++;
                    [$consultas, $encerrado] = $this->encerrarPeloPrazo($processamento);
                    $resumo['consultas'] += $consultas;
                    $resumo['encerrados'] += $encerrado ? 1 : 0;

                    continue;
                }

                $resumo['verificacoes'] += $this->verificarAguardandoComIdadeDeReenvio($processamento);
            } catch (Throwable $e) {
                $resumo['falhas']++;

                Log::error("[ProcessamentoOcr:{$processamento->id}] falha no acompanhamento do OCR", [
                    'erro' => class_basename($e),
                ]);
            }
        }

        return $resumo;
    }

    /**
     * P conta da abertura ou do último progresso, o que for mais recente
     * (NC-4). No minuto exato de P, já venceu.
     */
    public function prazoVenceu(ProcessoOcrProcessamento $processamento): bool
    {
        $prazo = (int) config('services.n8n_ocr.prazo_sem_progresso_minutos');

        return now()->gte($this->base($processamento)->copy()->addMinutes($prazo));
    }

    private function base(ProcessoOcrProcessamento $processamento): CarbonInterface
    {
        $aberto = $processamento->aberto_em ?? $processamento->created_at ?? now();
        $ultimoProgresso = $processamento->ultimo_progresso_em;

        return $ultimoProgresso !== null && $ultimoProgresso->gt($aberto) ? $ultimoProgresso : $aberto;
    }

    /**
     * RF-13 itens 1 e 2. O resultado de cada consulta segue as regras de
     * RF-12: o `success` prevalece, e o `error` não rebaixa um processado.
     *
     * @return array{0: int, 1: bool} consultas feitas e se o processamento terminou nesta rodada
     */
    private function encerrarPeloPrazo(ProcessoOcrProcessamento $processamento): array
    {
        $prefixo = "[ProcessamentoOcr:{$processamento->id}]";
        $aguardando = $this->aguardandoRetorno($processamento)->whereNotNull('ocr_job_id')->get();
        $consultas = 0;

        Log::info("{$prefixo} prazo sem progresso vencido; consultando os jobs aguardando retorno", [
            'aguardando' => $aguardando->count(),
        ]);

        foreach ($aguardando as $documento) {
            $consultas++;
            $this->aplicarConsultaNoPrazo($documento);
        }

        // O retorno do último documento pode ter concluído o processamento
        // agora mesmo (ProcessamentoOcrService): `encerrarPorPrazo` é
        // idempotente e só age em `em_andamento`. Sem pendente nem
        // aguardando retorno, ele termina `concluido`, como manda o RF-15.
        $this->processamentos->encerrarPorPrazo($processamento);

        return [$consultas, $processamento->situacao !== ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO];
    }

    private function aplicarConsultaNoPrazo(ProcessoDocumento $documento): void
    {
        $prefixo = "[ProcessoDocumento:{$documento->id}]";
        $jobId = (string) $documento->ocr_job_id;

        try {
            $situacao = $this->workflow->consultarJob($jobId);
        } catch (FalhaTransitoriaWorkflowOcrException $e) {
            // Sem resposta, o documento segue aguardando retorno e fica para
            // a regra da abertura seguinte (RF-18).
            Log::warning("{$prefixo} consulta do job no fim do prazo falhou", array_filter([
                'job_id' => $jobId,
                'status' => $e->statusHttp,
            ], fn ($valor) => $valor !== null));

            return;
        } catch (Throwable $e) {
            Log::warning("{$prefixo} consulta do job no fim do prazo falhou", [
                'job_id' => $jobId,
                'erro' => class_basename($e),
            ]);

            return;
        }

        $resultado = match ($situacao) {
            SituacaoJobOcr::Sucesso => $this->processamentos->aplicarRetorno($documento, true),
            SituacaoJobOcr::Falhou,
            SituacaoJobOcr::Cancelado,
            SituacaoJobOcr::Inexistente => $this->processamentos->aplicarRetorno(
                $documento,
                false,
                'Consulta do job no fim do prazo sem progresso: '.$this->descreverSituacao($situacao),
            ),
            // `pending` e `running` seguem aguardando retorno.
            SituacaoJobOcr::Pendente,
            SituacaoJobOcr::EmExecucao => null,
        };

        Log::info("{$prefixo} job do OCR consultado no fim do prazo", [
            'job_id' => $jobId,
            'situacao' => $situacao->name,
            'resultado' => $resultado?->value,
        ]);
    }

    private function descreverSituacao(SituacaoJobOcr $situacao): string
    {
        return match ($situacao) {
            SituacaoJobOcr::Falhou => 'job terminou em erro (failed)',
            SituacaoJobOcr::Cancelado => 'job cancelado (cancelled)',
            SituacaoJobOcr::Inexistente => 'job inexistente no workflow (HTTP 404)',
            default => $situacao->name,
        };
    }

    /**
     * RF-18 item 3 na rodada (R2-3): só despacha a consulta antes do
     * reenvio. Sem a data do envio, a idade é desconhecida, e a consulta
     * decide (VerificarReenvioOcrJob faz o mesmo).
     *
     * @return int consultas despachadas
     */
    private function verificarAguardandoComIdadeDeReenvio(ProcessoOcrProcessamento $processamento): int
    {
        $idadeDeReenvio = (int) config('services.n8n_ocr.idade_maxima_sem_retorno_minutos');
        $limite = now()->subMinutes($idadeDeReenvio);

        /** @var Collection<int, int> $documentos */
        $documentos = $this->aguardandoRetorno($processamento)
            ->where(fn (Builder $consulta) => $consulta
                ->whereNull('ocr_enviado_em')
                ->orWhere('ocr_enviado_em', '<=', $limite))
            ->pluck('id');

        foreach ($documentos as $documentoId) {
            VerificarReenvioOcrJob::dispatch($processamento->id, $documentoId)
                ->onQueue(EnviarDocumentoAoOcrJob::FILA);
        }

        if ($documentos->isNotEmpty()) {
            Log::info("[ProcessamentoOcr:{$processamento->id}] consulta antes do reenvio despachada", [
                'documentos' => $documentos->count(),
            ]);
        }

        return $documentos->count();
    }

    /**
     * Os documentos do processo aguardando retorno, de qualquer
     * processamento (RF-13 item 1, RF-18).
     *
     * @return Builder<ProcessoDocumento>
     */
    private function aguardandoRetorno(ProcessoOcrProcessamento $processamento): Builder
    {
        return ProcessoDocumento::query()
            ->where('processo_id', $processamento->processo_id)
            ->where('ocr_situacao', ProcessoDocumento::OCR_ENVIADO)
            ->orderBy('id');
    }
}
