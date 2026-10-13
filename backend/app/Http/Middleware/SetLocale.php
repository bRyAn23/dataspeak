<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The frontend sends its interface language; resources localise labels, chart titles and sentences with it. */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->header('X-Dataspeak-Language') ?? $request->query('lang');
        // Default is a constant: app()->setLocale() rewrites config('app.locale'), so it must not be the fallback.
        app()->setLocale(Locale::normalize(is_string($requested) ? $requested : null, 'en'));

        $response = $next($request);
        // The same URL answers differently per language: tell browsers and proxies never to reuse a cached copy.
        $response->headers->set('Vary', trim(($response->headers->get('Vary') ? $response->headers->get('Vary').', ' : '').'X-Dataspeak-Language', ', '));
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
