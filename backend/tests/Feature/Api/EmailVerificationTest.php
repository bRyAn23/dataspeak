<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\ApiTestCase;

/** FEAT-AUTH-001 email verification: AUTH-API-005/006 and the verified.api middleware. */
class EmailVerificationTest extends ApiTestCase
{
    public function test_register_sends_a_branded_verification_email_and_marks_the_user_unverified(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Nu Nu', 'email' => 'nunu@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertCreated()
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.verification.required', true)
            ->assertJsonPath('data.verification.email_sent', true);

        $user = User::query()->where('email', 'nunu@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user) {
            $mail = $n->toMail($user);
            $this->assertSame('Confirm your email for Data Speak', $mail->subject);
            $this->assertStringContainsString('/api/v1/auth/verify-email/'.$user->id.'/', $mail->actionUrl);
            $this->assertStringContainsString('signature=', $mail->actionUrl);

            return true;
        });
    }

    public function test_unverified_users_get_403_on_analysis_routes_but_can_read_me_and_resend(): void
    {
        Notification::fake();
        $user = $this->actingAsUser(User::factory()->unverified()->create());

        $this->postJson('/api/v1/analyses', ['name' => 'X'])->assertStatus(403)->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
        $this->getJson('/api/v1/analyses')->assertStatus(403);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email_verified', false);

        $this->postJson('/api/v1/auth/email/resend')->assertStatus(202)->assertJsonPath('data.status', 'sent');
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_verified_users_are_not_asked_again(): void
    {
        Notification::fake();
        $this->actingAsUser();

        $this->postJson('/api/v1/auth/email/resend')->assertOk()->assertJsonPath('data.status', 'already_verified');
        $this->postJson('/api/v1/analyses', ['name' => 'X'])->assertCreated();
        Notification::assertNothingSent();
    }

    public function test_signed_link_verifies_and_redirects_to_the_frontend(): void
    {
        config(['dataspeak.frontend_url' => 'http://app.test']);
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->get($url)->assertRedirect('http://app.test/login?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->get($url)->assertRedirect('http://app.test/login?verified=already');
    }

    public function test_tampered_or_expired_links_are_rejected_without_verifying(): void
    {
        config(['dataspeak.frontend_url' => 'http://app.test']);
        $user = User::factory()->unverified()->create();

        $wrongHash = URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1('other@example.com')]);
        $this->get($wrongHash)->assertRedirect('http://app.test/login?verified=invalid');

        $good = URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get(str_replace('signature=', 'signature=0', $good))->assertRedirect('http://app.test/login?verified=invalid');

        $expired = URL::temporarySignedRoute('api.v1.auth.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($expired)->assertRedirect('http://app.test/login?verified=invalid');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verification_can_be_switched_off_for_closed_environments(): void
    {
        config(['dataspeak.require_verified_email' => false]);
        Notification::fake();

        // Registration sends no mail and tells the client it can skip the confirmation page
        $response = $this->postJson('/api/v1/auth/register', ['name' => 'No Mail', 'email' => 'nomail@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1']);
        $response->assertCreated()->assertJsonPath('data.verification.required', false)->assertJsonPath('data.verification.email_sent', false)
            ->assertJsonPath('data.user.verification_required', false)->assertJsonPath('data.user.email_verified', false);
        Notification::assertNothingSent();

        $this->actingAsUser(User::factory()->unverified()->create());
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.verification_required', false);
        $this->postJson('/api/v1/analyses', ['name' => 'X'])->assertCreated();
    }
}
