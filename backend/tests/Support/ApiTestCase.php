<?php

namespace Tests\Support;

use App\Models\Analysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    public const SENTINEL = 'SENTINEL-CELL-ค่า-တန်ဖိုး-9471';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('transient');
        config(['services.analytics.base_url' => 'http://analytics.test', 'services.analytics.token' => 'test-token', 'dataspeak.auto_report' => true, 'dataspeak.ai.pretranslate' => false]); // pipeline tests predate chart review and pre-translation
    }

    protected function actingAsUser(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    protected function csvFile(string $name = 'sales.csv', ?string $content = null): UploadedFile
    {
        $content ??= "order_date,region,ยอดขาย,ရောင်းအား,revenue_thb\n2025-01-01,North,10,5,120.5\n2025-01-02,South,".self::SENTINEL.",6,89.0\n";

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** Puts a fake transient file on disk for an analysis built with withTransientFile(). */
    protected function placeTransientFile(Analysis $analysis, ?string $content = null): void
    {
        Storage::disk('transient')->put('analyses/'.$analysis->public_id.'/dataset.csv', $content ?? "a,b\n1,2\n");
    }

    protected function urlFor(Analysis $analysis, string $suffix = ''): string
    {
        return '/api/v1/analyses/'.$analysis->public_id.$suffix;
    }

    /** @return array<string, mixed> */
    protected function sampleEvidence(string $key = 'ev_aaaaaaaaaaaa'): array
    {
        return [
            'evidence_id' => $key,
            'analysis_family' => 'descriptive',
            'metric' => 'mean',
            'method' => 'arithmetic_mean',
            'value' => 104.75,
            'display_value' => '104.75',
            'source_columns' => ['revenue_thb'],
            'metric_column' => 'revenue_thb',
            'parameters' => [],
            'quality' => ['status' => 'acceptable'],
            'payload' => null,
        ];
    }
}
