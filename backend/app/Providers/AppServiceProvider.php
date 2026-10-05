<?php

namespace App\Providers;

use App\Exceptions\ServiceNotConfiguredException;
use App\Models\Analysis;
use App\Models\User;
use App\Policies\AnalysisPolicy;
use App\Services\Ai\Clients\OllamaClient;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Validation\Contracts\ClaimValidator;
use App\Services\Validation\RuleBasedClaimValidator;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // LLM client behind an interface: 'ollama' now; a vLLM/OpenAI-compatible client can be added here.
        $this->app->bind(LlmClient::class, function () {
            return match ((string) config('dataspeak.ai.driver', 'ollama')) {
                'ollama' => new OllamaClient,
                default => throw new ServiceNotConfiguredException('LLM driver '.config('dataspeak.ai.driver')),
            };
        });

        // Claim validator: rule-based now; 'nli' is Planned and must not be faked.
        $this->app->bind(ClaimValidator::class, function () {
            return match ((string) config('dataspeak.ai.validator', 'rule_based')) {
                'rule_based' => new RuleBasedClaimValidator,
                default => throw new ServiceNotConfiguredException('Claim validator '.config('dataspeak.ai.validator')),
            };
        });
    }

    public function boot(): void
    {
        Gate::policy(Analysis::class, AnalysisPolicy::class);

        // Rate limits (Laravel API doc Section 12): auth, upload, run, report.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('upload', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('run', fn (Request $r) => Limit::perMinute(6)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('report', fn (Request $r) => Limit::perMinute(6)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('ai-suggest', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('verification', fn (Request $r) => Limit::perMinute(3)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));

        // FEAT-AUTH-001: the verification link points at the API (signed, time-limited); the API then
        // redirects the browser to the React login page. Wording is for business users.
        VerifyEmail::createUrlUsing(function (User $user): string {
            return URL::temporarySignedRoute(
                'api.v1.auth.verify',
                now()->addMinutes((int) config('dataspeak.verification_link_minutes', 60)),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            );
        });
        VerifyEmail::toMailUsing(function (User $user, string $url): MailMessage {
            return (new MailMessage)
                ->subject('Confirm your email for Data Speak')
                ->greeting('Hello '.$user->name.',')
                ->line('Thanks for creating a Data Speak account. Please confirm this email address so you can start turning your data into clearer decisions.')
                ->action('Confirm my email', $url)
                ->line('The link works for '.(int) config('dataspeak.verification_link_minutes', 60).' minutes. If it has expired, log in and choose "Send the email again".')
                ->line('If you did not create this account, you can ignore this message.')
                ->salutation('The Data Speak team');
        });
    }
}
