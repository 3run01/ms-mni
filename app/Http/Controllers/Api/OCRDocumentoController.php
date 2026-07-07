<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\OCRRequestJob;
use Illuminate\Http\Request;

class OCRDocumentoController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'documento_id' => 'required|integer|exists:processo_documentos,id_documento',
        ]);

        $documentos = \App\Models\ProcessoDocumento::where('id_documento', $request->input('documento_id'))->get();

        foreach ($documentos as $documento) {
            OCRRequestJob::dispatch($documento)->onQueue('ocr-request');
        }

        return response()->json(['message' => 'Job de OCR enfileirado com sucesso.'], 200);
    }
}
