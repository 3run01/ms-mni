<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Credencial do `webhook_url` que o ms-mni manda ao workflow do OCR (CT-04,
 * D4): a URL assinada de `WebhookOcrUrl::gerar`, por documento e com
 * validade. A assinatura é relativa (só caminho e query), porque o n8n
 * chega por um host diferente do `APP_URL` (`host.docker.internal` em
 * desenvolvimento, o proxy em produção).
 *
 * Sem assinatura válida — ausente, adulterada (inclusive o `documento`) ou
 * vencida —, responde 401 antes de validar o corpo: quem não tem a
 * credencial não descobre nada do formato esperado (RF-12).
 */
class ValidarAssinaturaWebhookOcr
{
    public const MENSAGEM = 'Credencial do webhook inválida';

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            return response()->json(['message' => self::MENSAGEM], 401);
        }

        return $next($request);
    }
}
