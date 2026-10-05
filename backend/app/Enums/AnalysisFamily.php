<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: analysis_family. Values are authoritative; do not add without updating the dictionary.
 */
enum AnalysisFamily: string
{
    case DESCRIPTIVE = 'DESCRIPTIVE';
    case TREND = 'TREND';
    case GROUP_COMPARISON = 'GROUP_COMPARISON';
    case ASSOCIATION = 'ASSOCIATION';
    case STATISTICAL_TEST = 'STATISTICAL_TEST';
    case QUALITY = 'QUALITY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
