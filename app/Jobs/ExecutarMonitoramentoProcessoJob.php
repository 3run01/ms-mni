<?php

namespace App\Jobs;

use App\Exceptions\MNIException;
use App\Models\Processo;
use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use App\Services\Processo\DetectarAlteracoesProcessoService;
use App\Services\Processo\ProcessoService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecutarMonitoramentoProcessoJob implements ShouldQueue
{
    use Queueable;

    /**
     * Sem retry de fila: o reagendamento é responsabilidade do despachador.
     * Retry cego aqui multiplicaria consultas ao tribunal.
     */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $monitoramentoId) {}

    public function handle(): void
    {
        $monitoramento = ProcessoMonitoramento::with('credencial', 'tribunal')->find($this->monitoramentoId);

        if (! $monitoramento) {
            Log::warning("[Monitoramento:{$this->monitoramentoId}] não encontrado ao executar");
            return;
        }

        $iniciadoEm = now();
        $detector = app(DetectarAlteracoesProcessoService::class);

        $processoAntes = Processo::where('numero_processo', $monitoramento->numero_processo)
            ->where('tribunal_id', $monitoramento->tribunal_id)
            ->first();

        $snapshot = $detector->snapshot($processoAntes);

        [$login, $senha] = $this->resolverCredenciais($monitoramento);

        try {
            $processo = app(ProcessoService::class)->consultarNumero(
                $monitoramento->tribunal,
                $monitoramento->numero_processo,
                $login,
                $senha,
                $this->dataReferencia($monitoramento),
                false,
            );

            $delta = $detector->delta($processo, $snapshot);

            $execucao = ProcessoMonitoramentoExecucao::create([
                'monitoramento_id' => $monitoramento->id,
                'iniciado_em' => $iniciadoEm,
                'finalizado_em' => now(),
                'status' => ProcessoMonitoramentoExecucao::STATUS_SUCESSO,
                'houve_alteracao' => $delta['houve_alteracao'],
                'movimentos_novos' => $delta['movimentos_novos'],
                'documentos_novos' => $delta['documentos_novos'],
                'delta' => $delta,
            ]);

            $monitoramento->update([
                'ultima_execucao_em' => now(),
                'data_referencia' => now()->format('Ymd'),
                'falhas_consecutivas' => 0,
                'bloqueado_ate' => null,
            ]);

            Log::info("[Monitoramento:{$monitoramento->id}] execução concluída", [
                'movimentos_novos' => $delta['movimentos_novos'],
                'documentos_novos' => $delta['documentos_novos'],
            ]);
        } catch (\Throwable $e) {
            $execucao = $this->registrarFalha($monitoramento, $iniciadoEm, $e);
        }

        EnviarWebhookMonitoramentoJob::dispatch($execucao->id)->onQueue('monitoramento-webhook');
    }

    private function resolverCredenciais(ProcessoMonitoramento $monitoramento): array
    {
        if ($monitoramento->credencial) {
            return [$monitoramento->credencial->login, $monitoramento->credencial->senha];
        }

        $login = config('pje.credenciais_padrao.login');
        $senha = config('pje.credenciais_padrao.senha');

        return filled($login) && filled($senha) ? [$login, $senha] : [null, null];
    }

    /**
     * Véspera da última execução bem-sucedida: o MNI trunca `dataReferencia`
     * para o dia, e a véspera garante não perder o próprio dia da execução.
     */
    private function dataReferencia(ProcessoMonitoramento $monitoramento): ?string
    {
        return $monitoramento->data_referencia
            ? Carbon::createFromFormat('Ymd', $monitoramento->data_referencia)->subDay()->format('Y-m-d')
            : null;
    }

    private function registrarFalha(
        ProcessoMonitoramento $monitoramento,
        Carbon $iniciadoEm,
        \Throwable $e
    ): ProcessoMonitoramentoExecucao {
        $mensagem = $e instanceof MNIException && filled($e->getError()) ? $e->getError() : $e->getMessage();

        Log::error("[Monitoramento:{$monitoramento->id}] falha na consulta", ['erro' => $mensagem]);

        $execucao = ProcessoMonitoramentoExecucao::create([
            'monitoramento_id' => $monitoramento->id,
            'iniciado_em' => $iniciadoEm,
            'finalizado_em' => now(),
            'status' => ProcessoMonitoramentoExecucao::STATUS_FALHA,
            'erro_resumo' => mb_substr((string) $mensagem, 0, 1000),
        ]);

        $falhas = $monitoramento->falhas_consecutivas + 1;
        $suspender = $falhas >= (int) config('pje.monitoramento.max_falhas_consecutivas');

        $monitoramento->update([
            'falhas_consecutivas' => $falhas,
            'bloqueado_ate' => null,
            'status' => $suspender ? ProcessoMonitoramento::STATUS_SUSPENSO : $monitoramento->status,
        ]);

        return $execucao;
    }
}
