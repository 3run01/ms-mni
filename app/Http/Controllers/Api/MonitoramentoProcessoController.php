<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AtualizarMonitoramentoProcessoRequest;
use App\Http\Requests\Api\CriarMonitoramentoProcessoRequest;
use App\Http\Resources\Monitoramento\MonitoramentoExecucaoResource;
use App\Http\Resources\Monitoramento\MonitoramentoResource;
use App\Jobs\ExecutarMonitoramentoProcessoJob;
use App\Models\ApiToken;
use App\Models\ProcessoMonitoramento;
use App\Models\Tribunal;
use App\Services\Monitoramento\CredencialPjeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MonitoramentoProcessoController extends Controller
{
    private const DEFAULT_PER_PAGE = 10;

    /** Status que ocupam a vaga do processo: só `cancelado` libera. */
    private const STATUS_VIVOS = [
        ProcessoMonitoramento::STATUS_ATIVO,
        ProcessoMonitoramento::STATUS_PAUSADO,
        ProcessoMonitoramento::STATUS_SUSPENSO,
    ];

    public function __construct(private CredencialPjeService $credencialPjeService) {}

    public function store(CriarMonitoramentoProcessoRequest $request): JsonResponse
    {
        $token = $this->token($request);
        $tribunal = Tribunal::findOrFail($request->tribunal_id);

        $ativos = ProcessoMonitoramento::doToken($token->id)
            ->whereIn('status', self::STATUS_VIVOS)
            ->count();

        if ($ativos >= (int) config('pje.monitoramento.max_ativos_por_token')) {
            return response()->json([
                'error' => 'Limite de monitoramentos ativos por token atingido',
            ], 422);
        }

        $existente = ProcessoMonitoramento::doToken($token->id)
            ->where('tribunal_id', $tribunal->id)
            ->where('numero_processo', $request->numero_processo)
            ->whereIn('status', self::STATUS_VIVOS)
            ->first();

        if ($existente) {
            return response()->json([
                'error' => 'Já existe monitoramento para este processo',
                'uuid' => $existente->uuid,
            ], 409);
        }

        $credencial = $this->credencialPjeService->resolver(
            $token,
            $tribunal,
            $request->login_pje,
            $request->senha_pje
        );

        $monitoramento = ProcessoMonitoramento::create([
            'api_token_id' => $token->id,
            'tribunal_id' => $tribunal->id,
            'numero_processo' => $request->numero_processo,
            'intervalo_horas' => $request->intervalo_horas,
            'credencial_id' => $credencial?->id,
            'callback_url' => $request->callback_url,
            'callback_token' => $request->callback_token,
            'status' => ProcessoMonitoramento::STATUS_ATIVO,
            'falhas_consecutivas' => 0,
            // entra no próximo tick do despachador (a cada 30 min)
            'proxima_execucao_em' => now(),
        ]);

        return (new MonitoramentoResource($monitoramento->load('credencial')))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $monitoramentos = ProcessoMonitoramento::doToken($this->token($request)->id)
            ->with('credencial')
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(self::DEFAULT_PER_PAGE);

        return MonitoramentoResource::collection($monitoramentos);
    }

    public function show(Request $request, string $uuid): MonitoramentoResource
    {
        $monitoramento = $this->buscarDoToken($request, $uuid);

        $monitoramento->load([
            'credencial',
            'execucoes' => fn ($query) => $query->limit(10),
        ]);

        return new MonitoramentoResource($monitoramento);
    }

    public function update(AtualizarMonitoramentoProcessoRequest $request, string $uuid): MonitoramentoResource
    {
        $monitoramento = $this->buscarDoToken($request, $uuid);

        $dados = $request->only(['intervalo_horas', 'callback_url', 'callback_token', 'status']);

        // retomar zera o histórico de falhas e recoloca na fila do próximo tick
        if (($dados['status'] ?? null) === ProcessoMonitoramento::STATUS_ATIVO
            && $monitoramento->status !== ProcessoMonitoramento::STATUS_ATIVO) {
            $dados['falhas_consecutivas'] = 0;
            $dados['bloqueado_ate'] = null;
            $dados['proxima_execucao_em'] = now();
        }

        $monitoramento->update($dados);

        return new MonitoramentoResource($monitoramento->fresh()->load('credencial'));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $monitoramento = $this->buscarDoToken($request, $uuid);

        $monitoramento->update(['status' => ProcessoMonitoramento::STATUS_CANCELADO]);
        $monitoramento->delete();

        return response()->json(null, 204);
    }

    public function execucoes(Request $request, string $uuid): AnonymousResourceCollection
    {
        $monitoramento = $this->buscarDoToken($request, $uuid);

        return MonitoramentoExecucaoResource::collection(
            $monitoramento->execucoes()->paginate(self::DEFAULT_PER_PAGE)
        );
    }

    public function executar(Request $request, string $uuid): JsonResponse
    {
        $monitoramento = $this->buscarDoToken($request, $uuid);

        ExecutarMonitoramentoProcessoJob::dispatch($monitoramento->id)->onQueue('monitoramento');

        return response()->json([
            'message' => 'Execução enfileirada',
            'uuid' => $monitoramento->uuid,
        ], 202);
    }

    private function token(Request $request): ApiToken
    {
        return $request->attributes->get('apiToken');
    }

    /** uuid de outro token cai em 404 — não vaza a existência do recurso. */
    private function buscarDoToken(Request $request, string $uuid): ProcessoMonitoramento
    {
        return ProcessoMonitoramento::doToken($this->token($request)->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
