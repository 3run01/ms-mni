<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\Processo;
use App\Models\ProcessoMonitoramento;
use App\Models\Tribunal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MonitoramentoController extends Controller
{
    private const POR_PAGINA = 20;

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:255'],
            'tribunal_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([
                ProcessoMonitoramento::STATUS_ATIVO,
                ProcessoMonitoramento::STATUS_PAUSADO,
                ProcessoMonitoramento::STATUS_SUSPENSO,
            ])],
            'api_token_id' => ['nullable', 'integer'],
        ]);

        $monitoramentos = ProcessoMonitoramento::query()
            ->with(['tribunal:id,nome', 'apiToken:id,name', 'ultimaExecucao'])
            ->when($filtros['busca'] ?? null,
                fn ($q, $v) => $q->where('numero_processo', 'ilike', '%' . preg_replace('/\D/', '', $v) . '%'))
            ->when($filtros['tribunal_id'] ?? null,
                fn ($q, $v) => $q->where('tribunal_id', $v))
            ->when($filtros['status'] ?? null,
                fn ($q, $v) => $q->where('status', $v))
            ->when($filtros['api_token_id'] ?? null,
                fn ($q, $v) => $q->where('api_token_id', $v))
            // vencidos primeiro: quem está esperando execução aparece no topo
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ProcessoMonitoramento::STATUS_ATIVO])
            ->orderBy('proxima_execucao_em')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        // processos não têm FK com monitoramentos (o vínculo é numero+tribunal):
        // um único SELECT resolve o link da página inteira, sem N+1.
        $processoIds = Processo::query()
            ->whereIn('numero_processo', $monitoramentos->pluck('numero_processo'))
            ->whereIn('tribunal_id', $monitoramentos->pluck('tribunal_id'))
            ->get(['id', 'numero_processo', 'tribunal_id'])
            ->keyBy(fn (Processo $p) => $p->tribunal_id . '|' . $p->numero_processo);

        $monitoramentos->through(function (ProcessoMonitoramento $m) use ($processoIds) {
                $ultima = $m->ultimaExecucao;

                return [
                    'uuid' => $m->uuid,
                    'numero_processo' => $m->numero_processo,
                    'processo_id' => $processoIds->get($m->tribunal_id . '|' . $m->numero_processo)?->id,
                    'tribunal' => $m->tribunal?->nome,
                    'token' => $m->apiToken?->name,
                    'status' => $m->status,
                    'intervalo_horas' => $m->intervalo_horas,
                    'proxima_execucao_em' => $m->proxima_execucao_em?->toIso8601String(),
                    'ultima_execucao_em' => $m->ultima_execucao_em?->toIso8601String(),
                    'falhas_consecutivas' => $m->falhas_consecutivas,
                    'ultima_execucao' => $ultima ? [
                        'status' => $ultima->status,
                        'houve_alteracao' => $ultima->houve_alteracao,
                        'movimentos_novos' => $ultima->movimentos_novos,
                        'documentos_novos' => $ultima->documentos_novos,
                        'erro_resumo' => $ultima->erro_resumo,
                    ] : null,
                ];
        });

        return Inertia::render('monitoramentos/index', [
            'monitoramentos' => $monitoramentos,
            'filtros' => $filtros,
            'tribunais' => Tribunal::query()
                ->whereIn('id', ProcessoMonitoramento::query()->select('tribunal_id')->distinct())
                ->orderBy('nome')
                ->get(['id', 'nome']),
            'tokens' => ApiToken::query()
                ->whereIn('id', ProcessoMonitoramento::query()->select('api_token_id')->distinct())
                ->orderBy('name')
                ->get(['id', 'name']),
            'statusOptions' => [
                ProcessoMonitoramento::STATUS_ATIVO,
                ProcessoMonitoramento::STATUS_PAUSADO,
                ProcessoMonitoramento::STATUS_SUSPENSO,
            ],
            'resumo' => [
                'ativos' => ProcessoMonitoramento::where('status', ProcessoMonitoramento::STATUS_ATIVO)->count(),
                'pausados' => ProcessoMonitoramento::where('status', ProcessoMonitoramento::STATUS_PAUSADO)->count(),
                'suspensos' => ProcessoMonitoramento::where('status', ProcessoMonitoramento::STATUS_SUSPENSO)->count(),
            ],
        ]);
    }
}
