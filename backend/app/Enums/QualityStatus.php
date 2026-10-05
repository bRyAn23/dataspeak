<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: quality_status. Values are authoritative; do not add without updating the dictionary.
 */
enum QualityStatus: string
{
    case ACCEPTABLE = 'ACCEPTABLE';
    case WARNING = 'WARNING';
    case INSUFFICIENT = 'INSUFFICIENT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
