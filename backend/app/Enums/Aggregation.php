<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: aggregation. Values are authoritative; do not add without updating the dictionary.
 */
enum Aggregation: string
{
    case SUM = 'SUM';
    case AVERAGE = 'AVERAGE';
    case COUNT = 'COUNT';
    case MIN = 'MIN';
    case MAX = 'MAX';
    case NONE = 'NONE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
