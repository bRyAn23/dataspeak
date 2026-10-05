<?php

namespace App\Services\Analytics;

use App\Exceptions\AnalyticsServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only client of the FastAPI analytics service (docs/contracts/analytics-contract.md).
 * Sends the internal token, applies timeouts, retries connection failures only, and maps
 * ANALYTICS_* errors into the application error contract. Never logs payloads.
 */
class AnalyticsServiceClient
{
    public const TOKEN_HEADER = 'X-Internal-Token';

    /** PY-API-003 */
    public function validate(string $filePath, string $analysisId): array
    {
        return $this->postFile('/v1/validate', $filePath, ['analysis_id' => $analysisId], 'validate');
    }

    /** PY-API-002 */
    public function profile(string $filePath, string $analysisId, array $definitions = []): array
    {
        return $this->postFile('/v1/profile', $filePath, ['analysis_id' => $analysisId, 'definitions' => $definitions], 'profile');
    }

    /** PY-API-004 */
    public function analyze(string $filePath, string $analysisId, array $definitions, array $options = []): array
    {
        return $this->postFile('/v1/analyze', $filePath, ['analysis_id' => $analysisId, 'definitions' => $definitions, 'options' => (object) $options], 'analyze');
    }

    /** PY-API-005 */
    public function insights(string $analysisId, array $evidence, array $definitions, array $options = []): array
    {
        return $this->postJson('/v1/insights', ['analysis_id' => $analysisId, 'evidence' => $evidence, 'definitions' => $definitions, 'options' => (object) $options], 'insights');
    }

    /** PY-API-006 */
    public function recommendVisualizations(string $analysisId, array $evidence, array $insights, array $definitions): array
    {
        return $this->postJson('/v1/visualizations/recommend', ['analysis_id' => $analysisId, 'evidence' => $evidence, 'insights' => $insights, 'definitions' => $definitions], 'visualizations');
    }

    private function request(): PendingRequest
    {
        $token = (string) config('services.analytics.token');

        return Http::baseUrl(rtrim((string) config('services.analytics.base_url'), '/'))
            ->withHeaders([self::TOKEN_HEADER => $token])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('services.analytics.timeout', 30))
            ->retry(2, 250, fn ($exception) => $exception instanceof ConnectionException, throw: false);
    }

    private function postFile(string $uri, string $filePath, array $metadata, string $op): array
    {
        if (! is_readable($filePath)) {
            throw AnalyticsServiceException::unavailable('The transient dataset is no longer available; upload it again.');
        }

        // Contents are attached as a string so no file handle outlives the request; the transient
        // file itself stays on disk until the lifecycle deletes it. Size is bounded by UPLOAD_MAX_MB.
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw AnalyticsServiceException::unavailable('The transient dataset could not be read; upload it again.');
        }

        try {
            $started = microtime(true);
            $response = $this->request()
                ->attach('file', $contents, 'dataset.csv', ['Content-Type' => 'text/csv'])
                ->post($uri, ['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        } catch (ConnectionException $e) {
            throw $this->connectionFailure($op, $e);
        } finally {
            unset($contents);
        }

        return $this->handle($response, $op, $metadata['analysis_id'], $started);
    }

    private function postJson(string $uri, array $payload, string $op): array
    {
        try {
            $started = microtime(true);
            $response = $this->request()->post($uri, $payload);
        } catch (ConnectionException $e) {
            throw $this->connectionFailure($op, $e);
        }

        return $this->handle($response, $op, (string) ($payload['analysis_id'] ?? ''), $started);
    }

    private function connectionFailure(string $op, ConnectionException $e): AnalyticsServiceException
    {
        Log::warning('analytics.unreachable', ['method' => $op, 'code' => 'ANALYTICS_UNAVAILABLE']);

        return AnalyticsServiceException::unavailable();
    }

    private function handle(Response $response, string $op, string $analysisId, float $started): array
    {
        $duration = (int) ((microtime(true) - $started) * 1000);

        if ($response->successful()) {
            $json = $response->json();
            if (! is_array($json)) {
                throw AnalyticsServiceException::badResponse();
            }
            Log::info('analytics.call', ['method' => $op, 'analysis_id' => $analysisId, 'duration_ms' => $duration, 'status_code' => $response->status()]);

            return $json;
        }

        $error = $response->json('error');
        $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'The analytics service rejected the request.';
        $details = is_array($error) && is_array($error['details'] ?? null) ? $error['details'] : [];

        Log::warning('analytics.error', ['method' => $op, 'analysis_id' => $analysisId, 'duration_ms' => $duration, 'status_code' => $response->status(), 'code' => $code ?? 'none']);

        if ($response->status() >= 500 && $code === null) {
            throw AnalyticsServiceException::unavailable();
        }
        if ($code === null) {
            throw AnalyticsServiceException::badResponse();
        }

        throw AnalyticsServiceException::fromUpstream($code, $message, $details);
    }
}
