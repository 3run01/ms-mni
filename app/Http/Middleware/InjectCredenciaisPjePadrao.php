<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Preenche login_pje/senha_pje com o par padrão do .env quando a requisição
 * não traz as duas credenciais. O par é atômico: uma requisição com apenas
 * uma das credenciais recebe o par padrão inteiro, nunca uma combinação
 * de login do cliente com senha do servidor.
 *
 * Sem par padrão configurado, o request segue como veio e o fallback das
 * credenciais do tribunal (camada de service) decide.
 */
class InjectCredenciaisPjePadrao
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->filled('login_pje') && $request->filled('senha_pje')) {
            return $next($request);
        }

        $login = config('pje.credenciais_padrao.login');
        $senha = config('pje.credenciais_padrao.senha');

        if (filled($login) && filled($senha)) {
            $request->merge([
                'login_pje' => $login,
                'senha_pje' => $senha,
            ]);
        }

        return $next($request);
    }
}
