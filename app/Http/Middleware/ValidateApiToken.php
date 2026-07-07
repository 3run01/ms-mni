<?php

namespace App\Http\Middleware;

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
        $token = $request->header('X-API-Token');

        if (!$token || $token !== config('services.api.token')) {
            return response()->json([
                'message' => 'Token inválido ou não fornecido'
            ], 401);
        }

        return $next($request);
    }
}
