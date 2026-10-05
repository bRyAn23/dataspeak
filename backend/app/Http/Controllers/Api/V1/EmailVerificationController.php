<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FEAT-AUTH-001 email verification.
 *  AUTH-API-005 GET  /auth/verify-email/{id}/{hash}  signed link from the email; redirects to the frontend login
 *  AUTH-API-006 POST /auth/email/resend              authenticated, throttled
 */
class EmailVerificationController extends Controller
{
    /** AUTH-API-005: the browser lands here from the email, so the answer is a redirect, not JSON. */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontend = (string) config('dataspeak.frontend_url');
        $user = User::query()->find($id);

        if ($user === null || ! $request->hasValidSignature() || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            Log::info('auth.verify_rejected', ['user_id' => $id]);

            return redirect()->away($frontend.'/login?verified=invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->away($frontend.'/login?verified=already');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }
        Log::info('auth.verified', ['user_id' => $user->id]);

        return redirect()->away($frontend.'/login?verified=1');
    }

    /** AUTH-API-006 */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['status' => 'already_verified']]);
        }

        $sent = self::send($user);

        return response()->json(['data' => ['status' => $sent ? 'sent' : 'failed', 'email' => $user->email]], $sent ? 202 : 503);
    }

    /** Sends the branded VerifyEmail notification (see AppServiceProvider); returns false if the mail server failed. */
    public static function send(User $user): bool
    {
        try {
            $user->sendEmailVerificationNotification();
            Log::info('auth.verification_sent', ['user_id' => $user->id]);

            return true;
        } catch (Throwable $e) {
            Log::warning('auth.verification_failed', ['user_id' => $user->id, 'error' => $e::class]);

            return false;
        }
    }
}
