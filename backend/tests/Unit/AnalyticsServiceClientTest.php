<?php

namespace Tests\Unit;

use App\Exceptions\AnalyticsServiceException;
use App\Services\Analytics\AnalyticsServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnalyticsServiceClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.analytics.base_url' => 'http://analytics.test', 'services.analytics.token' => 'tok']);
    }

    /** @return array<string, array{string, int}> */
    public static function upstreamCodes(): array
    {
        return [
            'invalid dataset' => ['ANALYTICS_INVALID_DATASET', 422],
            'insufficient data' => ['ANALYTICS_INSUFFICIENT_DATA', 422],
            'unsupported type' => ['ANALYTICS_UNSUPPORTED_TYPE', 422],
            'method failed' => ['ANALYTICS_METHOD_FAILED', 502],
            'internal error' => ['ANALYTICS_INTERNAL_ERROR', 502],
            'unauthorized (misconfiguration)' => ['ANALYTICS_UNAUTHORIZED', 502],
        ];
    }

    #[DataProvider('upstreamCodes')]
    public function test_upstream_error_codes_are_mapped(string $code, int $status): void
    {
        Http::fake(['analytics.test/*' => Http::response(['error' => ['code' => $code, 'message' => 'm']], $status >= 500 ? 500 : 422)]);

        try {
            (new AnalyticsServiceClient)->insights('a', [], []);
            $this->fail('expected exception');
        } catch (AnalyticsServiceException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->status);
        }
    }

    public function test_connection_failure_and_blank_5xx_become_503(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        try {
            (new AnalyticsServiceClient)->insights('a', [], []);
            $this->fail();
        } catch (AnalyticsServiceException $e) {
            $this->assertSame(503, $e->status);
            $this->assertTrue($e->isTransient());
        }

        Http::fake(['analytics.test/*' => Http::response('gateway timeout', 504)]);
        try {
            (new AnalyticsServiceClient)->insights('a', [], []);
            $this->fail();
        } catch (AnalyticsServiceException $e) {
            $this->assertSame('ANALYTICS_UNAVAILABLE', $e->errorCode);
        }
    }

    public function test_success_returns_json_and_sends_token(): void
    {
        Http::fake(['analytics.test/v1/insights' => Http::response(['insights' => []])]);
        $result = (new AnalyticsServiceClient)->insights('a', [], []);
        $this->assertSame(['insights' => []], $result);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Internal-Token', 'tok') && $r->hasHeader('Accept', 'application/json'));
    }

    public function test_missing_file_is_reported_as_unavailable_without_a_call(): void
    {
        Http::fake();
        $this->expectException(AnalyticsServiceException::class);
        (new AnalyticsServiceClient)->validate('/nonexistent/dataset.csv', 'a');
    }
}
