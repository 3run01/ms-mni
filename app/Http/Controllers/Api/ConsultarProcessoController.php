<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MNIException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Processo\ConsultarProcessoApiComunicacaoPJeResource;
use App\Jobs\BaixarProcessoMNIJob;
use App\Models\Processo;
use App\Models\Tribunal;
use App\Services\ComunicacaoPJe\ConsultarProcessoService;
use App\Services\Processo\ProcessoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Jobs\ConsultarDadosBasicosProcessoMNIJob;
use App\Jobs\ConsultarMovimentosProcessoMNIJob;
use Svg\Tag\Rect;

class ConsultarProcessoController extends Controller
{
    private ProcessoService $processoService;
    private ConsultarProcessoService $consultarProcessoService;
    private const DEFAULT_PER_PAGE = 10;

    public function __construct(ProcessoService $processoService, ConsultarProcessoService $consultarProcessoService)
    {
        $this->processoService = $processoService;
        $this->consultarProcessoService = $consultarProcessoService;
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        try {
            if (!$request->tribunal_id) {
                return response()->json(['error' => 'Tribunal não informado'], 400);
            }

            if (!empty($request->numero_processo)) {
                return $this->buscarPorNumeroProcesso($request);
            }

            return $this->buscarPorNomeParte($request);
        } catch (MNIException $e) {
            return response()->json(['error' => $e->getError(), 'line' => $e->getLine(), 'file' => $e->getFile()], $e->getCode() > 0 ? $e->getCode() : 500);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage(), 'line' => $e->getLine(), 'file' => $e->getFile()], $e->getCode() > 0 ? $e->getCode() : 500);
        }
    }

    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        try {
            if (!$request->tribunal_id) {
                return response()->json(['error' => 'Tribunal não informado'], 400);
            }

            if (!$request->numero_processo) {
                return response()->json(['error' => 'Número do processo não informado'], 400);
            }

            $numero_processo = cleanNumeroProcesso($request->numero_processo);

            $with = [
                'tribunal',
                'partes.representantesProcessual',
                'prioridades',
                'classe',
                'assuntos',
                'movimentos',
                'documentos' => function ($q) {
                    $q->select('id', 'id_documento', 'descricao', 'id_documento_vinculado', 'movimento', 'tipo_documento', 'data_hora', 'nivel_sigilo', 'processo_id');
                },
            ];

            // $processo = '';
            $processo = Processo::with($with)
                ->where('numero_processo', cleanNumeroProcesso($request->numero_processo))
                ->where('tribunal_id', $request->tribunal_id)
                ->first();

            //verifica se o processo existe, caso não exista, tenta baixar o processo
            if (empty($processo)) {
                $this->processoService->consultarNumero(
                    Tribunal::find($request->tribunal_id),
                    $numero_processo,
                    $request->login_pje ?? null,
                    $request->senha_pje ?? null
                );

                $processo = Processo::with($with)
                    ->where('numero_processo', $numero_processo)
                    ->where('tribunal_id', $request->tribunal_id)
                    ->first();
            } else {
                BaixarProcessoMNIJob::dispatch(Tribunal::find($request->tribunal_id), $numero_processo, $request->login_pje, $request->senha_pje);
            }

            return response()->json($processo);
        } catch (MNIException $e) {
            return response()->json(['error' => $e->getError(), 'line' => $e->getLine(), 'file' => $e->getFile()], $e->getCode() > 0 ? $e->getCode() : 500);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage(), 'line' => $e->getLine(), 'file' => $e->getFile()], $e->getCode() > 0 ? $e->getCode() : 500);
        }
    }

    private function buscarPorNumeroProcesso(Request $request): JsonResponse
    {
        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        $processos = Processo::where('numero_processo', $numero_processo)
            ->paginate(self::DEFAULT_PER_PAGE);

        if ($processos->total() == 0) {
            //baixa o processo caso nao exista
            $this->processoService->consultarNumero(
                Tribunal::find($request->tribunal_id),
                $numero_processo,
                $request->login_pje,
                $request->senha_pje
            );

            $processos = Processo::where('numero_processo', $numero_processo)
                ->paginate(self::DEFAULT_PER_PAGE);
        } else {
            //atualiza o processo em background caso existe
            BaixarProcessoMNIJob::dispatch(Tribunal::find($request->tribunal_id), $numero_processo, $request->login_pje, $request->senha_pje);
        }

        return response()->json($processos);
    }

    private function buscarPorNomeParte(Request $request): JsonResponse
    {
        $with = [
            'tribunal',
            'partes',
            'prioridades',
            'classe',
            'assuntos',
            'partes' => function ($q) {
                $q->select('id', 'nome', 'cpf_cnpj', 'processo_id');
            },
        ];

        $processos = Processo::with($with)
            ->when($request->nomeParte, function ($query, $nomeParte) {
                return $query->whereHas('partes', function ($query) use ($nomeParte) {
                    $query->where('nome', 'ilike', "%{$nomeParte}%");
                });
            })
            ->paginate(self::DEFAULT_PER_PAGE);

        return response()->json($processos);
    }

    public function consultarDadosBasicos(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        $processo = Processo::with('tribunal', 'classe', 'assuntos', 'prioridades', 'partes.representantesProcessual')
            ->where('numero_processo', $numero_processo)
            ->where('tribunal_id', $request->tribunal_id)
            ->first();

        //Verifica se o processo existe, caso exisa retorna o que está salvo no banco de dados
        if (!empty($processo)) {
            return response()->json($processo);
        }

        $processo = $this->processoService->consultarDadosBasicos(
            Tribunal::find($request->tribunal_id),
            $numero_processo,
            $request->login_pje,
            $request->senha_pje
        );

        return response()->json($processo);
    }

    public function consultarMovimentos(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        $processo = Processo::with('movimentos')
            ->where('numero_processo', $numero_processo)
            ->where('tribunal_id', $request->tribunal_id)
            ->first();

        if ($processo && $processo->movimentos->count() > 0) {
            return response()->json($processo->movimentos);
        }

        $processo = $this->processoService->consultarMovimentos(
            Tribunal::find($request->tribunal_id),
            $numero_processo,
            $request->login_pje,
            $request->senha_pje,
            $request->data_referencia ?? null,
        );

        return response()->json($processo->movimentos);
    }

    public function consultarDadosBasicosAsync(Request $request)
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        ConsultarDadosBasicosProcessoMNIJob::dispatch($request->tribunal_id, $numero_processo, $request->login_pje, $request->senha_pje)->onQueue('alta');
    }

    public function consultarMovimentosAsync(Request $request)
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        ConsultarMovimentosProcessoMNIJob::dispatch($request->tribunal_id, $numero_processo, $request->login_pje, $request->senha_pje)->onQueue('alta');
    }

}
