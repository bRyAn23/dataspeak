<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: job_status. Values are authoritative; do not add without updating the dictionary.
 */
enum JobStatus: string
{
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
