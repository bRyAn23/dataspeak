<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: claim_kind. Values are authoritative; do not add without updating the dictionary.
 */
enum ClaimKind: string
{
    case EXECUTIVE_SUMMARY = 'EXECUTIVE_SUMMARY';
    case FINDING = 'FINDING';
    case METRIC = 'METRIC';
    case CAVEAT = 'CAVEAT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
