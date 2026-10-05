<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_reports_service_and_dependency_checks(): void
    {
        // Redis is not available in the test environment; the probe must degrade, not throw.
        Redis::shouldReceive('connection->ping')->andThrow(new \RuntimeException('no redis'));

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503)
            ->assertJsonPath('data.service', 'dataspeak-backend')
            ->assertJsonPath('data.version', '1.0')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertJsonPath('data.checks.redis', 'down');
    }

    public function test_health_is_ok_when_all_dependencies_respond(): void
    {
        Redis::shouldReceive('connection->ping')->andReturn('PONG');

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');
    }
}
