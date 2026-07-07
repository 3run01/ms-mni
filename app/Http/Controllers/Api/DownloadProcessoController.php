<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CriarExportacaoProcessoRequest;
use App\Jobs\GerarPdfExportacaoJob;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Http\JsonResponse;

class DownloadProcessoController extends Controller
{
    public function __construct(private readonly ExportacaoProcessoService $service) {}

    public function store(CriarExportacaoProcessoRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $filtros = [
            'ids_selecionados' => $dados['ids_selecionados'] ?? null,
            'periodo_inicial' => $dados['periodo_inicial'] ?? null,
            'periodo_final' => $dados['periodo_final'] ?? null,
            'id_inicial' => $dados['id_inicial'] ?? null,
            'id_final' => $dados['id_final'] ?? null,
        ];

        if (!$this->service->temDocumentosDisponiveis($filtros, $dados['numero_processo'])) {
            return response()->json([
                'error' => 'Nenhum documento encontrado para o processo informado com os filtros aplicados.',
            ], 404);
        }

        $exportacao = $this->service->criar($dados);

        GerarPdfExportacaoJob::dispatch($exportacao->id)->onQueue('exportar-processo');

        return response()->json([
            'message' => 'Exportação enfileirada',
            'exportacao_id' => $exportacao->id,
        ], 200);
    }
}
