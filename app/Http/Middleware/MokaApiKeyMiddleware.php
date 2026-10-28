<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MokaApiKeyMiddleware
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $expectedApiKey = (string) config(
            'services.moka.api_key',
            ''
        );

        $providedApiKey = $request->header('X-MOKA-KEY');

        if (
            $expectedApiKey === '' ||
            !is_string($providedApiKey) ||
            $providedApiKey === '' ||
            !hash_equals($expectedApiKey, $providedApiKey)
        ) {
            return response()->json([
                'message' => 'Unauthorized Moka API',
            ], 401);
        }

        return $next($request);
    }
}