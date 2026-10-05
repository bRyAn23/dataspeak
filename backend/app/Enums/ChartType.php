<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: chart_type. Values are authoritative; do not add without updating the dictionary.
 */
enum ChartType: string
{
    case LINE = 'LINE';
    case BAR = 'BAR';
    case SCATTER = 'SCATTER';
    case HISTOGRAM = 'HISTOGRAM';
    case TABLE = 'TABLE';
    case PIE = 'PIE'; // FEAT-VIS-005: share of a total by category (user-added at chart review)

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
