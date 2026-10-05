<?php

namespace Tests\Feature\Live;

use App\Enums\AiGenerationStatus;
use App\Services\Ai\AiGateway;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Validation\Contracts\ClaimValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AiScenario;
use Tests\TestCase;

/** Runs against a real Ollama. Excluded by default; run with: php artisan test --group=ollama */
#[Group('ollama')]
class OllamaLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_model_returns_schema_valid_claims_that_mostly_validate(): void
    {
        $base = (string) config('services.ollama.base_url');
        try {
            Http::timeout(3)->get($base.'/api/version')->throw();
        } catch (\Throwable) {
            $this->markTestSkipped("Ollama not reachable at {$base}");
        }

        $analysis = AiScenario::build();
        $resolver = PlaceholderResolver::for($analysis);
        $generation = app(AiGateway::class)->interpret($analysis, $resolver, persist: false);

        $this->assertSame(AiGenerationStatus::SUCCEEDED, $generation->status, (string) $generation->error_code);
        $items = array_merge($generation->response_payload['claims'], $generation->response_payload['limitations']);
        $this->assertNotEmpty($items);
        $validator = app(ClaimValidator::class);
        $supported = collect($items)->filter(fn ($c) => $validator->validate($c, $resolver)->accepted())->count();
        fwrite(STDERR, sprintf("\n[ollama] model=%s claims=%d supported=%d latency=%dms tokens=%d/%d\n", $generation->model, count($items), $supported, $generation->duration_ms, $generation->input_tokens, $generation->output_tokens));
        $this->assertGreaterThan(0, $supported, 'expected at least one claim to pass validation');
    }
}
