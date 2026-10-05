<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** JSON variant of Laravel's "verified" middleware: 403 EMAIL_NOT_VERIFIED instead of a redirect. */
class EnsureEmailIsVerifiedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('dataspeak.require_verified_email') && $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            throw new ApiException('EMAIL_NOT_VERIFIED', 'Please confirm your email address first. Check your inbox for the link from Data Speak.', 403);
        }

        return $next($request);
    }
}
