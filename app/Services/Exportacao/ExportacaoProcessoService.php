<?php

namespace App\Services\Exportacao;

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoExportacao;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;

class ExportacaoProcessoService
{
    public function criar(array $dados): ProcessoExportacao
    {
        return ProcessoExportacao::create([
            'user_id' => $dados['user_id'],
            'numero_processo' => $dados['numero_processo'],
            'tribunal_id' => $dados['tribunal_id'] ?? null,
            'titulo' => $dados['titulo'],
            'formato' => $dados['formato'],
            'callback_url' => $dados['callback_url'],
            'callback_token' => $dados['callback_token'],
            'status' => ProcessoExportacao::STATUS_ENFILEIRADO,
            'filtros' => [
                'ids_selecionados' => $dados['ids_selecionados'] ?? null,
                'periodo_inicial' => $dados['periodo_inicial'] ?? null,
                'periodo_final' => $dados['periodo_final'] ?? null,
                'id_inicial' => $dados['id_inicial'] ?? null,
                'id_final' => $dados['id_final'] ?? null,
            ],
        ]);
    }

    public function temDocumentosDisponiveis(array $filtros, string $numeroProcesso): bool
    {
        return $this->queryDocumentos($filtros, $numeroProcesso)->exists();
    }

    public function consultarDocumentos(ProcessoExportacao $exportacao): Collection
    {
        return $this->queryDocumentos($exportacao->filtros ?? [], $exportacao->numero_processo)
            ->orderBy('id_documento', 'asc')
            ->get();
    }

    /**
     * Marca a exportação como falhou e dispara o callback de notificação ao chamador.
     * É chamado tanto pelo controle de erro dos jobs quanto pelo "no documents" path.
     */
    public function marcarComoFalhou(ProcessoExportacao $exportacao, string $erroResumo): void
    {
        $exportacao->update([
            'status' => ProcessoExportacao::STATUS_FALHOU,
            'erro_resumo' => $erroResumo,
        ]);

        EnviarWebhookDownloadJob::dispatch($exportacao->id)->onQueue('exportar-processo');
    }

    public function enviarParaS3(ProcessoExportacao $exportacao, string $caminhoLocal): void
    {
        $tamanho = filesize($caminhoLocal);
        $s3Path = "downloads/{$exportacao->user_id}/{$exportacao->uuid_arquivo}.pdf";

        $stream = fopen($caminhoLocal, 'r');
        Storage::disk('s3')->put($s3Path, $stream, ['visibility' => 'private']);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $exportacao->update([
            's3_path' => $s3Path,
            'tamanho_bytes' => $tamanho,
            'status' => ProcessoExportacao::STATUS_CONCLUIDO,
        ]);

        @unlink($caminhoLocal);
    }

    public function gerarPdf(ProcessoExportacao $exportacao, Collection $documentos): string
    {
        $uuid = $exportacao->uuid_arquivo ?: (string) Str::uuid();
        $exportacao->update(['uuid_arquivo' => $uuid]);

        $pastaTemp = storage_path('app/private/exportacoes');
        if (!is_dir($pastaTemp)) {
            mkdir($pastaTemp, 0755, true);
        }

        $caminhoFinal = "{$pastaTemp}/{$uuid}.pdf";
        $processo = Processo::where('numero_processo', $exportacao->numero_processo)->first();

        $pdfCapa = PDF::loadView('processo.download', [
            'documentos' => $documentos,
            'processo' => $processo,
        ]);
        $tempCapaPath = "{$pastaTemp}/_capa_{$uuid}.pdf";
        file_put_contents($tempCapaPath, $pdfCapa->output());

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($tempCapaPath);
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $pdf->AddPage();
            $pdf->useTemplate($templateId);
            if ($pageNo === 1) {
                $pdf->Bookmark('Capa', 0, 0, '', 'B', [0, 0, 0]);
            }
        }

        foreach ($documentos as $documento) {
            $this->baixarESomarDocumento($pdf, $documento);
        }

        $pdf->Output($caminhoFinal, 'F');
        @unlink($tempCapaPath);

        return $caminhoFinal;
    }

    private function baixarESomarDocumento(Fpdi $pdf, $documento): void
    {
        if (Storage::disk('s3')->exists($documento->path)) {
            $content = Storage::disk('s3')->get($documento->path);
            \Illuminate\Support\Facades\Storage::disk('local')->put($documento->path, $content);
        }

        $pathDocumento = \Illuminate\Support\Facades\Storage::disk('local')->path($documento->path);
        if (!file_exists($pathDocumento)) {
            return;
        }

        $this->converterVersaoPdf($pathDocumento);

        try {
            $pageCount = $pdf->setSourceFile($pathDocumento);
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $pdf->AddPage();
                $pdf->useTemplate($templateId);
                if ($pageNo === 1) {
                    $pdf->Bookmark($documento->descricao ?? 'Documento', 0, 0, '', 'B', [0, 0, 0]);
                }
            }
            @unlink($pathDocumento);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Erro ao processar documento {$documento->id_documento}: {$e->getMessage()}");
        }
    }

    private function converterVersaoPdf(string $inputPdf): void
    {
        $tempOut = $inputPdf . '.tmp';
        $command = sprintf('gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -o %s %s 2>/dev/null', escapeshellarg($tempOut), escapeshellarg($inputPdf));
        exec($command, $output, $code);

        if ($code === 0 && file_exists($tempOut)) {
            rename($tempOut, $inputPdf);
            return;
        }

        \Illuminate\Support\Facades\Log::warning('[Exportacao] Ghostscript falhou ao normalizar PDF — usando original', [
            'arquivo' => $inputPdf,
            'exit_code' => $code,
        ]);
    }

    private function queryDocumentos(array $filtros, string $numeroProcesso): Builder
    {
        $query = ProcessoDocumento::whereHas('processo', function ($q) use ($numeroProcesso) {
            $q->where('numero_processo', $numeroProcesso);
        })
        ->where('status', ProcessoDocumento::STATUS_BAIXADO)
        ->whereIn('mimetype', ['application/pdf', 'text/html'])
          ->whereNotNull('path');

        $idsSelecionados = $filtros['ids_selecionados'] ?? null;

        // ids_selecionados tem prioridade absoluta — quando informado, periodo e id-range são ignorados
        if (!empty($idsSelecionados) && is_array($idsSelecionados)) {
            $ids = array_map(fn ($id) => (int) $id, $idsSelecionados);
            return $query->whereIn('id_documento', $ids);
        }

        $periodoInicial = $filtros['periodo_inicial'] ?? null;
        $periodoFinal = $filtros['periodo_final'] ?? null;
        $idInicial = $filtros['id_inicial'] ?? null;
        $idFinal = $filtros['id_final'] ?? null;

        return $query
            ->when(!empty($periodoInicial) && !empty($periodoFinal), function ($q) use ($periodoInicial, $periodoFinal) {
                $q->whereBetween('data_hora', [$periodoInicial . ' 00:00:01', $periodoFinal . ' 23:59:59']);
            })
            ->when(!empty($idInicial) && !empty($idFinal), function ($q) use ($idInicial, $idFinal) {
                $q->whereBetween('id_documento', [$idInicial, $idFinal]);
            });
    }
}
