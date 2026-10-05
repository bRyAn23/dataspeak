<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional Idempotency-Key support for POST /run and POST /report (Laravel API doc Section 3).
 * The first successful response for (user, route, key) is replayed for 24 hours.
 */
class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '' || ! $request->isMethod('POST')) {
            return $next($request);
        }

        $cacheKey = sprintf('idempotency:%s:%s:%s', $request->user()?->getAuthIdentifier() ?? 'anon', sha1($request->path()), sha1($key));

        if (($cached = Cache::get($cacheKey)) !== null) {
            return (new JsonResponse($cached['body'], $cached['status']))->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            Cache::put($cacheKey, ['status' => $response->getStatusCode(), 'body' => $response->getData(true)], now()->addHours((int) config('dataspeak.idempotency_ttl_hours', 24)));
        }

        return $response;
    }
}
