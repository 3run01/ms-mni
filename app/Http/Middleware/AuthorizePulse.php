<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthorizePulse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Verificar se o usuário está autenticado
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        // Verificar se o usuário tem permissão para acessar ferramentas de monitoramento
        // Por padrão, todos os usuários autenticados podem acessar
        // Para restringir apenas a administradores, descomente a linha abaixo:
        // if (!Auth::user()->is_admin) {
        //     abort(403, 'Acesso negado. Apenas administradores podem acessar esta ferramenta.');
        // }

        // Log de acesso para auditoria
        Log::info('Acesso às ferramentas de monitoramento', [
            'user_id' => Auth::id(),
            'user_email' => Auth::user()->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
        ]);

        return $next($request);
    }
}
