<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MNIException;
use App\Http\Controllers\Controller;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\Processo\ProcessoService;
use Illuminate\Http\JsonResponse;
use App\Jobs\ConsultarDocumentosProcessoMNIJob;

class DocumentoController extends Controller
{
    public $processoService;

    public function __construct(ProcessoService $processoService)
    {
        $this->processoService = $processoService;
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $maxTentativas = 3;
            $tentativa = 0;

            do {
                $tribunal = Tribunal::find($request->tribunal_id);
                $documento = $this->getDocumento(
                    $request->id_documento,
                    $request->numero_processo,
                    $tribunal,
                    $request->login_pje ?? null,
                    $request->senha_pje ?? null
                );

                if (!empty($documento) && !empty($documento->link)) {
                    return response()->json([
                        'message' => 'Documento ' . $request->id_documento . ' consultado com sucesso',
                        'documento' => $documento
                    ]);
                }
            } while ($tentativa < $maxTentativas);

            return response()->json([
                'message' => 'Não foi possível obter o documento ' . $request->id_documento . ' do processo ' . $request->numero_processo . ' após ' . $maxTentativas . ' tentativas'
            ], 404);
        } catch (MNIException $e) {
            Log::error('MNIException no show: ' . $e->getError() . ' - ' . $e->getLine() . ' - ' . $e->getFile());
            return response()->json(['message' => $e->getError()], 500);
        } catch (\Exception $e) {
            Log::error('Erro no show: ' . $e->getMessage() . ' - ' . $e->getLine() . ' - ' . $e->getFile());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function getDocumento($id_documento, $numero_processo, $tribunal, $login_pje = null, $senha_pje = null)
    {


        try {
            $service = new SalvarDocumentoProcessoService();
            $documento = $this->vericarExistenciaDocumento($id_documento, $numero_processo, $tribunal, $login_pje, $senha_pje);

            //verifica se o documento existe e se estar sem conteudo html
            if (empty($documento->conteudo_html)) {
                $documento = $service->baixarDocumento($documento, $login_pje, $senha_pje);
            }


            // Se o documento já estiver baixado e existir no S3, apenas gera o link
            if ($documento->status == ProcessoDocumento::STATUS_BAIXADO && Storage::disk('s3')->exists($documento->path)) {
                try {
                    $documento->link = Storage::disk('s3')->temporaryUrl(
                        $documento->path,
                        now()->addMinutes(60)
                    );

                    return $documento;
                } catch (\Exception $e) {
                    Log::error('Erro ao gerar link temporário para documento: ' . $e->getMessage(), [
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }


            $documento = $service->baixarDocumento(
                $documento,
                $login_pje ?? null,
                $senha_pje ?? null
            );

            // Verifica se o documento foi baixado com sucesso
            if ($documento && $documento->status == ProcessoDocumento::STATUS_BAIXADO && Storage::disk('s3')->exists($documento->path)) {
                try {
                    $documento->link = Storage::disk('s3')->temporaryUrl(
                        $documento->path,
                        now()->addMinutes(60)
                    );

                    return $documento;
                } catch (\Exception $e) {
                    Log::error('Erro ao gerar link temporário para documento após download: ' . $e->getMessage(), [
                        'trace' => $e->getTraceAsString()
                    ]);
                    return null;
                }
            }

            return null;
        } catch (MNIException $e) {
            Log::error('MNIException ao obter documento: ' . $e->getError());
            throw new MNIException($e->getError(), 500);
        } catch (\Exception $e) {
            Log::error('Erro ao obter documento: ' . $e->getMessage());
            throw new MNIException($e->getMessage(), 500);
        }
    }

    public function vericarExistenciaDocumento($id_documento, $numero_processo, $tribunal, $login_pje = null, $senha_pje = null)
    {
        $documento = ProcessoDocumento::where('id_documento', $id_documento)
            ->whereHas('processo', function ($query) use ($numero_processo) {
                $query->where('numero_processo', $numero_processo);
            })->first();


        if (empty($documento)) {
            $processoService = new ProcessoService();
            $processoService->consultarNumero($tribunal, $numero_processo, $login_pje, $senha_pje);
            $documento = ProcessoDocumento::where('id_documento', $id_documento)
                ->whereHas('processo', function ($query) use ($numero_processo) {
                    $query->where('numero_processo', $numero_processo);
                })->first();
        }

        if (empty($documento)) {
            throw new MNIException('Documento não encontrado', 404);
        }

        return $documento;
    }

    public function listarDocumentos(Request $request): JsonResponse
    {
        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        $processo = Processo::with('documentos')
            ->where('numero_processo', $numero_processo)
            ->where('tribunal_id', $request->tribunal_id)
            ->first();

        if ($processo && $processo->documentos->count() > 0) {
            return response()->json($processo->documentos);
        }

        $processo = $this->processoService->consultarDocumentos(
            Tribunal::find($request->tribunal_id),
            $numero_processo,
            $request->login_pje ?? null,
            $request->senha_pje ?? null,
            $request->data_referencia ?? null,
        );

        // Carregar tipos de documento manualmente para cada documento
        $documentos = $processo->documentos->map(function ($documento) {
            $documento->tipo = $documento->getTipoDocumento();
            return $documento;
        });

        return response()->json($documentos);
    }

    public function consultarDocumentosAsync(Request $request)
    {
        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        ConsultarDocumentosProcessoMNIJob::dispatch($request->tribunal_id, $numero_processo)->onQueue('alta');
    }
}
