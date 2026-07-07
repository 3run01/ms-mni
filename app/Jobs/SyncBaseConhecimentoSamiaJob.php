<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Jobs\OCRRequestJob;
use App\Models\Notificacao;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncBaseConhecimentoSamiaJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */


    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->executarSyncBaseConhecimento();
    }

    public function executarSyncBaseConhecimento(): void
    {
        //verifica se possui algum processo para sincronizar
        $processoParaSincronizar = Processo::whereIn('knowledge_base_status_sync', [
            Processo::KNOWLEDGE_BASE_STATUS_STARTING,
            Processo::KNOWLEDGE_BASE_STATUS_IN_PROGRESS
        ])->exists();

        if (!$processoParaSincronizar) {
            return;
        }

        $sync = samia()->consultarSyncBaseConhecimento();

        Log::info('Sync Base Conhecimento Samia', ['response' => $sync]);

        // if ($sync && in_array($sync['status'], [
        //     Processo::KNOWLEDGE_BASE_STATUS_IN_PROGRESS
        // ])) {
        //     return;
        // }

        $completed_at = $sync['completed_at'] ?? null;
        //Verifica o status
        // if ($sync && in_array($sync['status'], [
        //     Processo::KNOWLEDGE_BASE_STATUS_COMPLETE,
        //     'COMPLETED'
        // ]))

        // if($completed_at > $){


        // {
            //Atualiza o processo que está sincronizando para COMPLETE
            $this->concluirProcessosSincronizando($completed_at);

            //Pega o próximo processo que está em STARTING e atualiza para IN_PROGRESS
            $this->proximoProcessoFila();

            $samiaStatus = $sync['status'] ?? null;
            $possuiProcessosInProgress = Processo::where('knowledge_base_status_sync', Processo::KNOWLEDGE_BASE_STATUS_IN_PROGRESS)->exists();

            if ($possuiProcessosInProgress && $samiaStatus !== 'IN_PROGRESS') {
                samia()->executarSyncBaseConhecimento();
            } else {
                Log::info('Sync não disparado', [
                    'possui_processos_in_progress' => $possuiProcessosInProgress,
                    'samia_status' => $samiaStatus,
                ]);
            }
        // }
    }

    /**
     * Verifica os próximos processos na fila e atualiza múltiplos de uma vez
     * @return bool
     */

    public function proximoProcessoFila(): bool
    {
        // Busca todos os processos com status STARTING
        $processosStarting = Processo::where('knowledge_base_status_sync', Processo::KNOWLEDGE_BASE_STATUS_STARTING)
            ->select('id', 'numero_processo', 'knowledge_base_sequence_job', 'knowledge_base_status_sync')
            ->orderBy('knowledge_base_sequence_job', 'asc')
            ->get();

        if ($processosStarting->isEmpty()) {
            Log::info('Nenhum processo na fila para sincronização');
            return false;
        }

        $processosAtualizados = 0;
        $dataAtualJob = now()->setTimezone('America/Sao_Paulo');

        foreach ($processosStarting as $processo) {
            // Verificar se o último documento OCR foi concluído antes da execução deste job
            $ultimoDocumentoOCR = $processo->documentos()
                ->whereNotNull('ocr_concluido_data')
                ->orderBy('ocr_concluido_data', 'desc')
                ->first();

            // Se existe último documento e sua data é maior que a data do job, pular este processo
            if ($ultimoDocumentoOCR && $ultimoDocumentoOCR->ocr_concluido_data > $dataAtualJob) {
                Log::info('Processo possui OCR em andamento, pulando', [
                    'processo' => $processo->numero_processo,
                    'ultimo_ocr_data' => $ultimoDocumentoOCR->ocr_concluido_data,
                    'data_job' => $dataAtualJob
                ]);
                continue;
            }

            // Verificar se possui documentos pendentes de OCR
            if ($this->verificarDocumentosPendentesOCR($processo)) {
                Log::info('Processo possui documentos pendentes de OCR, aguardando', [
                    'processo' => $processo->numero_processo
                ]);
                continue;
            }

            // Processo pronto para sincronizar, atualizar para IN_PROGRESS
            $processo->knowledge_base_status_sync = Processo::KNOWLEDGE_BASE_STATUS_IN_PROGRESS;

            $processo->save();

            Log::info('Processo atualizado para IN_PROGRESS', ['processo' => $processo->id]);
            $processosAtualizados++;

            Log::info('Processo atualizado para IN_PROGRESS', [
                'processo' => $processo->numero_processo
            ]);
        }

        Log::info('Processos atualizados para sincronização', [
            'total_starting' => $processosStarting->count(),
            'atualizados' => $processosAtualizados
        ]);

        return $processosAtualizados > 0;
    }

    /**
     * Verifica se todos os documentos do processo já passaram por OCR
     * @param Processo $processo
     * @return bool
     */
    public function verificarDocumentosPendentesOCR($processo): bool
    {

        // Verifica se possui documentos pendente para OCR
        $documentosNaoProcessados = $processo->documentos()
            ->select('id', 'id_documento', 'mimetype', 'status', 'ocr_processado', 'ocr_enviado_fila')
            ->whereIn('mimetype', ['application/pdf', 'text/html'])
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->where('ocr_processado', false)
            ->get();

        Log::info('Documentos não processados para OCR do processo', [
            'processo' => $processo->numero_processo,
            'documentos_nao_processados' => $documentosNaoProcessados->count()
        ]);

        // Se existir documentos pendentes, retorna true
        if ($documentosNaoProcessados->count() > 0) {

            // Envia apenas documentos que ainda não foram enviados para a fila.
            // O UPDATE atômico garante que só um dispatcher reivindica cada documento,
            // evitando duplicatas quando o scheduler e o controller rodam simultaneamente.
            $documentosParaEnviar = $documentosNaoProcessados->where('ocr_enviado_fila', false);

            foreach ($documentosParaEnviar as $documento) {
                $claimed = ProcessoDocumento::where('id', $documento->id)
                    ->where('ocr_enviado_fila', false)
                    ->update(['ocr_enviado_fila' => true]);

                if ($claimed) {
                    OCRRequestJob::dispatch($documento)->onQueue('ocr-request');
                }
            }

            return true;
        }

        return false;
    }

    public function concluirProcessosSincronizando($completed_at): void
    {
        $query = Processo::where('knowledge_base_status_sync', Processo::KNOWLEDGE_BASE_STATUS_IN_PROGRESS);

        // Só adiciona a condição de data se completed_at não for null
        if ($completed_at !== null) {
            $query->where('knowledge_base_created_at', '<=', $completed_at);
        }

        $processosSincronizando = $query->get();

        $simAppUrl = config('services.sim_app.url');
        $simAppToken = config('services.sim_app.token');
        $simAppTimeout = config('services.sim_app.timeout', 10);

        foreach ($processosSincronizando as $processo) {
            Log::info('Concluindo processo sincronizando na base de conhecimento Samia', ['processo' => $processo->numero_processo]);
            $processo->knowledge_base_status_sync = Processo::KNOWLEDGE_BASE_STATUS_COMPLETE;
            $processo->save();

            try {
                Http::timeout($simAppTimeout)->get("{$simAppUrl}/webhook/atualizar-processo/{$processo->numero_processo}");
            } catch (ConnectionException $e) {
                Log::error('Falha ao notificar webhook atualizar-processo', [
                    'processo' => $processo->numero_processo,
                    'error' => $e->getMessage(),
                ]);
            }

            // Enviar webhook para notificações pendentes de OCR
            $notificacoesPendentes = $processo->notificacoes()
                ->where('tipo', Notificacao::TIPO_OCR_PROCESSO)
                ->where('notificado', false)
                ->get();

            foreach ($notificacoesPendentes as $notificacao) {
                try {
                    Http::timeout($simAppTimeout)
                        ->withHeaders(['X-API-Token' => $simAppToken])
                        ->post("{$simAppUrl}/webhook/notificacao", [
                            'notificacao_id' => $notificacao->notificacao_id,
                        ]);
                    $notificacao->update(['notificado' => true]);
                } catch (ConnectionException $e) {
                    Log::error('Falha ao enviar webhook de notificação OCR', [
                        'notificacao_id' => $notificacao->notificacao_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
