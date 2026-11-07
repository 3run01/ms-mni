<?php

namespace App\Services\Ocr;

use App\Models\ProcessoDocumento;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * O `webhook_url` de cada envio ao workflow do OCR (RF-11, CT-04, D4): a
 * rota `ocr.webhook` assinada por documento, com validade.
 *
 * A assinatura é relativa (caminho e query) e a URL é prefixada por
 * `services.n8n_ocr.webhook_base_url`, a base que o n8n alcança. Assim, a
 * conferência de `ValidarAssinaturaWebhookOcr` não depende do host por onde
 * o n8n chega (`host.docker.internal` em desenvolvimento, o proxy em
 * produção).
 */
class WebhookOcrUrl
{
    /**
     * A validade é M (`idade_maxima_absoluta_minutos`), e não A. Com idade
     * entre A e M, a consulta do job (D9) pode manter o documento aguardando
     * retorno sem reenvio, porque o job segue em `pending`/`running`; o
     * retorno que chega nessa janela precisa ser aceito, e não receber 401.
     * Como A < M (invariante de configuração), a credencial vale pelo menos
     * A depois do envio, como pede o RF-11.
     */
    public static function gerar(ProcessoDocumento $documento): string
    {
        $base = rtrim((string) config('services.n8n_ocr.webhook_base_url'), '/');

        if ($base === '') {
            throw new RuntimeException('services.n8n_ocr.webhook_base_url não configurada: o workflow do OCR não teria como chamar o webhook');
        }

        $validade = (int) config('services.n8n_ocr.idade_maxima_absoluta_minutos');

        $relativa = URL::temporarySignedRoute(
            'ocr.webhook',
            now()->addMinutes($validade),
            ['documento' => $documento->getKey()],
            absolute: false,
        );

        return $base.$relativa;
    }
}
