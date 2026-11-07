<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Força `Accept: application/json` na requisição. Quem chama sem esse
 * header (o workflow do n8n) receberia o 302 de volta para a página
 * anterior numa falha de validação; com ele, o 422 sai em JSON
 * `{message, errors}`, como o contrato documenta.
 */
class ForcarRespostaJson
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
