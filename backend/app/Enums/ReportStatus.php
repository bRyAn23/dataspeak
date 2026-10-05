<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: report_status. Values are authoritative; do not add without updating the dictionary.
 */
enum ReportStatus: string
{
    case DRAFT = 'DRAFT';
    case GENERATING = 'GENERATING';
    case READY = 'READY';
    case FAILED = 'FAILED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
