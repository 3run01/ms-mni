<?php

namespace App\Services\Ocr;

use App\Models\ProcessoDocumento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Corpo do envio de um documento ao workflow "Jurídico OCR (Gemini)"
 * (`POST documents/process`, CT-03; RF-11). Exatamente as 5 chaves de
 * `EnvioDeDocumentoAoOcr` (`workflow-ocr.openapi.yaml`): uma fonte só
 * (`url`), sem `file_base64`, `bucket_origem`/`path_origem` nem
 * `bucket_destino`/`path_destino`, que o Sistema não usa.
 *
 * Nada aqui leva o par do PJe: o documento já está no S3 (RNF-01).
 */
class MontarEnvioOcrService
{
    /**
     * Gerado no momento do envio, e não no despacho: o link temporário do S3
     * vale P a partir daqui (RF-11), e a credencial do `webhook_url` vale M
     * (WebhookOcrUrl), sempre ≥ A.
     *
     * @return array{url: string, filename: string, webhook_url: string, base_conhecimento: string, metadata: array<string, mixed>}
     *
     * @throws InvalidArgumentException documento sem `path` ou com mimetype
     *                                  que o OCR não lê
     */
    public function corpo(ProcessoDocumento $documento): array
    {
        if (blank($documento->path)) {
            throw new InvalidArgumentException("Documento {$documento->id} sem objeto gravado no S3 para o OCR");
        }

        $processo = $documento->processo;

        return [
            // O `path` é o objeto que o download grava: o PDF, para PDF; o PDF
            // renderizado, para HTML; e a própria imagem, para as imagens
            // (`.dat` para webp, tiff, bmp e gif). Nada é convertido aqui.
            'url' => Storage::disk('s3')->temporaryUrl(
                $documento->path,
                now()->addMinutes((int) config('services.n8n_ocr.prazo_sem_progresso_minutos')),
            ),
            // A extensão vem do mimetype, e não do objeto no S3: o workflow
            // escolhe o MIME pela extensão do `filename` (NC-1, D-07).
            'filename' => $documento->id_documento.'.'.FormatosOcr::extensaoDoFilename((string) $documento->mimetype),
            'webhook_url' => WebhookOcrUrl::gerar($documento),
            'base_conhecimento' => (string) config('services.n8n_ocr.base_conhecimento'),
            // Documento dos autos: sem `escritorio_id`, que o nó "Validar
            // pedido" exige ausente ou nulo nesse caso.
            'metadata' => [
                'numero_processo' => (string) $processo->numero_processo,
                'tribunal_id' => (int) $processo->tribunal_id,
                'numero_documento' => (string) $documento->id_documento,
                'tipo_documento' => $documento->getTipoDocumento()?->descricao,
                'descricao' => $documento->descricao,
                'movimento' => $documento->movimento,
                'mimetype' => $documento->mimetype,
                'data_juntada' => $this->dataJuntada($documento->data_juntada),
                // Sempre inteiro (Q-05): o `null` vai como 0.
                'nivel_sigilo' => (int) ($documento->nivel_sigilo ?? 0),
            ],
        ];
    }

    /**
     * ISO-8601 com fuso, como as datas do webhook de monitoramento
     * (`toIso8601String()`); `null` sem data gravada (Q-05, R2-4). A coluna
     * é `timestamp` sem fuso e não tem cast no model: a data foi gravada no
     * fuso da aplicação (`app.timezone`) e é lida nele, com o deslocamento
     * desse fuso na saída.
     */
    private function dataJuntada(mixed $valor): ?string
    {
        if (blank($valor)) {
            return null;
        }

        return Carbon::parse($valor, (string) config('app.timezone'))->toIso8601String();
    }
}
