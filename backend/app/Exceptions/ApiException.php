<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Structured API error (Laravel API Documentation, Section 11).
 * Rendered as {"error":{"code","message","details"?}}. Messages never contain cell values.
 */
class ApiException extends RuntimeException
{
    /** @param  list<array<string, mixed>>  $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param  list<array<string, mixed>>  $details */
    public static function unprocessable(string $code, string $message, array $details = []): static
    {
        return new static($code, $message, 422, $details);
    }

    public static function notFound(string $message = 'Resource not found.'): static
    {
        return new static('NOT_FOUND', $message, 404);
    }
}
