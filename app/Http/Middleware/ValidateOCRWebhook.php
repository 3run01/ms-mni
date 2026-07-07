<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateOCRWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) $request->query('secret', '');
        $expected = (string) config('services.sim_ocr.webhook_secret');

        if ($expected === '' || !hash_equals($expected, $secret)) {
            return response()->json([
                'message' => 'Webhook secret inválido'
            ], 401);
        }

        return $next($request);
    }
}
