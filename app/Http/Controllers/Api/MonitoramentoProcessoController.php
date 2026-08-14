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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\FormRequest;
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

    /**
     * Cria o monitoramento — ou atualiza o que já existe para o mesmo processo.
     *
     * Repetir o POST não conflita: é o caminho natural para corrigir uma
     * credencial errada sem ter que cancelar e recriar. 201 quando criou,
     * 200 quando atualizou.
     */
    public function store(CriarMonitoramentoProcessoRequest $request): JsonResponse
    {
        $token = $this->token($request);
        $tribunal = Tribunal::findOrFail($request->tribunal_id);

        if ($existente = $this->monitoramentoVivo($token->id, $tribunal->id, $request->numero_processo)) {
            return $this->respostaAtualizada($existente, $request, $tribunal, $token);
        }

        // o limite vale só para quem ocupa uma vaga nova: um token no teto
        // ainda precisa conseguir consertar os monitoramentos que já tem
        $ativos = ProcessoMonitoramento::doToken($token->id)
            ->whereIn('status', self::STATUS_VIVOS)
            ->count();

        if ($ativos >= (int) config('pje.monitoramento.max_ativos_por_token')) {
            return response()->json([
                'error' => 'Limite de monitoramentos ativos por token atingido',
            ], 422);
        }

        $credencial = $this->credencialPjeService->resolver(
            $token,
            $tribunal,
            $request->validated('login_pje'),
            $request->validated('senha_pje')
        );

        try {
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
        } catch (UniqueConstraintViolationException $e) {
            // corrida entre dois POSTs do mesmo processo: o índice parcial
            // barrou o segundo. Ele queria atualizar — então atualiza.
            $existente = $this->monitoramentoVivo($token->id, $tribunal->id, $request->numero_processo);

            if (! $existente) {
                throw $e;
            }

            return $this->respostaAtualizada($existente, $request, $tribunal, $token);
        }

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

    /**
     * Atualização parcial: credenciais PJe, intervalo, webhook e status.
     * Campo ausente não é tocado.
     */
    public function update(AtualizarMonitoramentoProcessoRequest $request, string $uuid): MonitoramentoResource
    {
        $monitoramento = $this->buscarDoToken($request, $uuid);

        return new MonitoramentoResource($this->aplicarAtualizacao(
            $monitoramento,
            $request,
            $monitoramento->tribunal,
            $this->token($request)
        ));
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

    /** O monitoramento que ocupa a vaga do processo, se houver. */
    private function monitoramentoVivo(int $apiTokenId, int $tribunalId, ?string $numeroProcesso): ?ProcessoMonitoramento
    {
        return ProcessoMonitoramento::doToken($apiTokenId)
            ->where('tribunal_id', $tribunalId)
            ->where('numero_processo', $numeroProcesso)
            ->whereIn('status', self::STATUS_VIVOS)
            ->first();
    }

    private function respostaAtualizada(
        ProcessoMonitoramento $monitoramento,
        FormRequest $request,
        Tribunal $tribunal,
        ApiToken $token
    ): JsonResponse {
        return (new MonitoramentoResource($this->aplicarAtualizacao($monitoramento, $request, $tribunal, $token)))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Aplica em um monitoramento existente os campos que vieram na requisição.
     * Serve ao PATCH e ao POST repetido — as regras de reagendamento precisam
     * ser as mesmas nos dois caminhos.
     *
     * Só mexe no que veio: campo ausente fica como está. Vale também para a
     * credencial — omitir o par login/senha mantém a atual, porque zerar aqui
     * jogaria o monitoramento silenciosamente para o par padrão do .env.
     */
    private function aplicarAtualizacao(
        ProcessoMonitoramento $monitoramento,
        FormRequest $request,
        Tribunal $tribunal,
        ApiToken $token
    ): ProcessoMonitoramento {
        // safe() em vez de only(): only() lê a entrada crua, e aí um `status`
        // não validado passaria no POST, que nem tem regra para esse campo.
        $dados = $request->safe()->only(['intervalo_horas', 'callback_url', 'callback_token', 'status']);

        $credencial = $this->credencialPjeService->resolver(
            $token,
            $tribunal,
            $request->validated('login_pje'),
            $request->validated('senha_pje')
        );

        $trocouCredencial = $credencial && $credencial->id !== $monitoramento->credencial_id;

        if ($credencial) {
            $dados['credencial_id'] = $credencial->id;
        }

        // Mudar o intervalo tem que valer agora, não só depois da próxima
        // execução: reancora a agenda na última execução. Quem nunca executou
        // fica de fora — já está esperando o primeiro tick.
        if (array_key_exists('intervalo_horas', $dados)
            && (int) $dados['intervalo_horas'] !== $monitoramento->intervalo_horas
            && $monitoramento->ultima_execucao_em) {
            $proxima = $monitoramento->ultima_execucao_em->copy()->addHours((int) $dados['intervalo_horas']);
            $dados['proxima_execucao_em'] = $proxima->isPast() ? now() : $proxima;
        }

        // Credencial nova é a correção exata da falha que suspende o
        // monitoramento, então tira da suspensão e zera o histórico. `pausado`
        // é decisão de gente e continua valendo — só o PATCH com status=ativo
        // retoma. `bloqueado_ate` só é limpo ao dessuspender: em um
        // monitoramento ativo ele significa job em voo, e limpar duplicaria a
        // consulta no próximo tick.
        if ($trocouCredencial) {
            $dados['falhas_consecutivas'] = 0;

            if ($monitoramento->status === ProcessoMonitoramento::STATUS_SUSPENSO) {
                $dados['status'] ??= ProcessoMonitoramento::STATUS_ATIVO;
                $dados['bloqueado_ate'] = null;
            }

            // quem conserta a credencial quer ver o resultado, não esperar o
            // intervalo inteiro
            if ($monitoramento->proxima_execucao_em?->isFuture()) {
                $dados['proxima_execucao_em'] = now();
            }
        }

        // retomar zera o histórico de falhas e recoloca na fila do próximo tick
        if (($dados['status'] ?? null) === ProcessoMonitoramento::STATUS_ATIVO
            && $monitoramento->status !== ProcessoMonitoramento::STATUS_ATIVO) {
            $dados['falhas_consecutivas'] = 0;
            $dados['bloqueado_ate'] = null;
            $dados['proxima_execucao_em'] = now();
        }

        $monitoramento->update($dados);

        return $monitoramento->fresh()->load('credencial');
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
