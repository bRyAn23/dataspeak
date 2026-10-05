<?php

namespace App\Exceptions;

/** Analytics (FastAPI) failure mapped into the application error contract (502/503/422). */
class AnalyticsServiceException extends ApiException
{
    public static function unavailable(string $reason = 'The analytics service is unavailable.'): self
    {
        return new self('ANALYTICS_UNAVAILABLE', $reason, 503);
    }

    /** @param  list<array<string, mixed>>  $details */
    public static function fromUpstream(string $upstreamCode, string $message, array $details = []): self
    {
        $status = match ($upstreamCode) {
            'ANALYTICS_INVALID_DATASET', 'ANALYTICS_UNSUPPORTED_TYPE', 'ANALYTICS_INSUFFICIENT_DATA' => 422,
            default => 502,
        };

        return new self($upstreamCode, $message, $status, $details);
    }

    public static function badResponse(string $reason = 'The analytics service returned an unexpected response.'): self
    {
        return new self('ANALYTICS_BAD_RESPONSE', $reason, 502);
    }

    /** Transient failures keep the transient upload for a retry; dataset failures do not. */
    public function isTransient(): bool
    {
        return in_array($this->errorCode, ['ANALYTICS_UNAVAILABLE', 'ANALYTICS_BAD_RESPONSE', 'ANALYTICS_METHOD_FAILED', 'ANALYTICS_INTERNAL_ERROR'], true);
    }
}
