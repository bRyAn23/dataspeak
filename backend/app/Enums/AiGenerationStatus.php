<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: ai_generation_status. Values are authoritative; do not add without updating the dictionary.
 */
enum AiGenerationStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case SKIPPED = 'SKIPPED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
