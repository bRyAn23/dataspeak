<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: insight_type. Values are authoritative; do not add without updating the dictionary.
 */
enum InsightType: string
{
    case TREND_CHANGE = 'TREND_CHANGE';
    case GROUP_DIFFERENCE = 'GROUP_DIFFERENCE';
    case CONCENTRATION = 'CONCENTRATION';
    case ASSOCIATION = 'ASSOCIATION';
    case DISTRIBUTION = 'DISTRIBUTION';
    case DATA_QUALITY = 'DATA_QUALITY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
