<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureEmailIsVerifiedJson;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating proxy or load balancer (ALB, Caddy, nginx) the forwarded scheme and host must be
        // trusted so signed verification links and redirects use https. TRUSTED_PROXIES: '*' or a CIDR list.
        if (($proxies = trim((string) env('TRUSTED_PROXIES', ''))) !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->api(prepend: [ForceJsonResponse::class, SetLocale::class]);
        $middleware->alias([
            'idempotent' => IdempotencyKey::class,
            'verified.api' => EnsureEmailIsVerifiedJson::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? app(ApiExceptionRenderer::class)->render($e, $request) : null;
        });
    })->create();
