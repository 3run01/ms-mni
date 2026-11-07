<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\EsperaDaTravaOcrEsgotadaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SolicitarOcrProcessoRequest;
use App\Http\Responses\ErroComCodigo;
use App\Models\ApiToken;
use App\Services\Ocr\DadosSolicitacaoOcr;
use App\Services\Ocr\SolicitarOcrProcessoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pedido de OCR do processo (`POST /api/processo/ocr`, CT-01; DEP-03).
 *
 * Controller fino: valida pelo FormRequest, chama
 * SolicitarOcrProcessoService e escolhe o status. A trava, a carona, a
 * consulta ao MNI e o despacho dos jobs ficam no service.
 *
 * - 202 `ProcessamentoDeOcr`: processamento novo, carona ou `concluido`;
 * - 403/404 com `codigo` (ErroComCodigo): recusa do par do pedido ou
 *   processo inexistente no tribunal (RF-05). O par é sempre o do cliente,
 *   porque a rota nunca usa o par padrão (RF-02);
 * - 500 `{message}`: espera pela trava esgotada (RF-06, W) ou qualquer outra
 *   falha. O corpo usa literais fixos, sem `error`, `line`, `file`, texto do
 *   tribunal nem o par.
 *
 * O corpo do pedido nunca vai para o log (RNF-01): só a linha, o número e a
 * classe da exceção.
 */
class OcrProcessoController extends Controller
{
    public const MENSAGEM_ESPERA_ESGOTADA = 'Pedido de OCR em andamento para este processo; tente de novo';

    public const MENSAGEM_FALHA = 'Não foi possível processar o pedido de OCR';

    public function __construct(private readonly SolicitarOcrProcessoService $solicitar) {}

    public function store(SolicitarOcrProcessoRequest $request): JsonResponse
    {
        $apiToken = $request->attributes->get('apiToken');

        $dados = new DadosSolicitacaoOcr(
            numeroProcesso: (string) $request->validated('numero_processo'),
            tribunalId: (int) $request->validated('tribunal_id'),
            login: (string) $request->validated('login_pje'),
            senha: (string) $request->validated('senha_pje'),
            callbackUrl: (string) $request->validated('callback_url'),
            callbackToken: (string) $request->validated('callback_token'),
            apiTokenId: $apiToken instanceof ApiToken ? $apiToken->id : null,
        );

        try {
            $resultado = $this->solicitar->solicitar($dados);
        } catch (Throwable $e) {
            return $this->falha($e, $dados);
        }

        return response()->json($resultado->paraCorpo(), 202);
    }

    private function falha(Throwable $e, DadosSolicitacaoOcr $dados): JsonResponse
    {
        // A rota só usa o par do pedido: a recusa é sempre do cliente.
        if ($classificada = ErroComCodigo::classificar($e, parDoCliente: true)) {
            return ErroComCodigo::resposta($classificada);
        }

        // O service já registrou a desistência; nada foi feito (RF-06 AC d).
        if ($e instanceof EsperaDaTravaOcrEsgotadaException) {
            return response()->json(['message' => self::MENSAGEM_ESPERA_ESGOTADA], 500);
        }

        // Só ids e a classe: a mensagem pode trazer texto do tribunal.
        Log::error('Pedido de OCR falhou', [
            'tribunal_id' => $dados->tribunalId,
            'numero_processo' => $dados->numeroProcesso,
            'excecao' => $e::class,
        ]);

        return response()->json(['message' => self::MENSAGEM_FALHA], 500);
    }
}
