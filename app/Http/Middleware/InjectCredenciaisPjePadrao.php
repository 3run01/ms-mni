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
        $temPadrao = filled($login) && filled($senha);

        // par incompleto na requisição é descartado inteiro: sem par padrão,
        // as duas credenciais viram null e o fallback do tribunal decide.
        $request->merge([
            'login_pje' => $temPadrao ? $login : null,
            'senha_pje' => $temPadrao ? $senha : null,
        ]);

        return $next($request);
    }
}
