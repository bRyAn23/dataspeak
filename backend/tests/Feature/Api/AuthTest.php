<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Tests\Support\ApiTestCase;

/** AUTH-API-001..004 */
class AuthTest extends ApiTestCase
{
    public function test_register_creates_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nu Nu', 'email' => 'nunu@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]])
            ->assertJsonPath('data.user.role', 'USER');
        $this->assertDatabaseHas('users', ['email' => 'nunu@example.com', 'role' => 'USER']);
    }

    public function test_register_validation_uses_error_envelope(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonFragment(['field' => 'name']);
    }

    public function test_login_returns_token_and_wrong_password_is_401(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'correct-horse-1']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'correct-horse-1'])
            ->assertOk()->assertJsonPath('data.user.id', (string) $user->id)->assertJsonStructure(['data' => ['token']]);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $user = $this->actingAsUser();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data.revoked', true);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app->make('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'bad']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'bad'])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
