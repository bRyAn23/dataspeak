<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/** MOD-01 Authentication: AUTH-API-001..004 (Sanctum bearer tokens). */
class AuthController extends Controller
{
    /** AUTH-API-001 POST /auth/register */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email')->lower(),
            'password' => $request->string('password'),
            'role' => UserRole::USER,
        ]);

        $token = $user->createToken($request->string('device_name', 'api')->value())->plainTextToken;
        Log::info('auth.registered', ['user_id' => $user->id]);

        // Registration never fails because of the mail server; the user can ask for the email again.
        $sent = EmailVerificationController::send($user);

        return response()->json(['data' => [
            'token' => $token,
            'user' => new UserResource($user),
            'verification' => ['required' => (bool) config('dataspeak.require_verified_email'), 'email_sent' => $sent],
        ]], 201);
    }

    /** AUTH-API-002 POST /auth/login */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->lower())->first();

        if ($user === null || ! Hash::check($request->string('password'), $user->password)) {
            throw new ApiException('INVALID_CREDENTIALS', 'The email or password is incorrect.', 401);
        }

        $token = $user->createToken($request->string('device_name', 'api')->value())->plainTextToken;
        Log::info('auth.login', ['user_id' => $user->id]);

        return response()->json(['data' => ['token' => $token, 'user' => new UserResource($user)]]);
    }

    /** AUTH-API-003 POST /auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        Log::info('auth.logout', ['user_id' => $request->user()->id]);

        return response()->json(['data' => ['revoked' => true]]);
    }

    /** AUTH-API-004 GET /me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }
}
