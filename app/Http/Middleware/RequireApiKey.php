<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedApiKey = (string) config('pdf.api_key');
        $providedApiKey = (string) $request->header('X-API-Key', '');

        if ($expectedApiKey === '' || ! hash_equals($expectedApiKey, $providedApiKey)) {
            return new JsonResponse([
                'message' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
