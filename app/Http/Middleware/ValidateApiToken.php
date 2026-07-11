<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->header('X-API-Token');

        $apiToken = $plainToken ? ApiToken::findValid($plainToken) : null;

        if (! $apiToken) {
            return response()->json([
                'message' => 'Token inválido ou não fornecido',
            ], 401);
        }

        $apiToken->registrarUso();

        return $next($request);
    }
}
