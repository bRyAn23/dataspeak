<?php

namespace App\Services\Ai;

use RuntimeException;

/** The LLM endpoint could not be reached, timed out, or returned an HTTP error. Never user-facing. */
class LlmUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $code_, string $message)
    {
        parent::__construct($message);
    }

    public static function unreachable(string $detail = ''): self
    {
        return new self('AI_UNAVAILABLE', 'The AI gateway is unreachable. '.$detail);
    }

    public static function timeout(): self
    {
        return new self('AI_TIMEOUT', 'The AI gateway did not answer within the configured timeout.');
    }

    public static function httpError(int $status): self
    {
        return new self('AI_HTTP_ERROR', "The AI gateway returned HTTP {$status}.");
    }

    /** DEF-09: the bounded wait for the shared local model ran out; nothing was sent. */
    public static function busy(string $class, int $waitedSeconds): self
    {
        return new self('AI_BUSY', "The AI model was busy with other work; the {$class} request waited {$waitedSeconds}s and was not started.");
    }
}
