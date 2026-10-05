<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: claim_status. Values are authoritative; do not add without updating the dictionary.
 */
enum ClaimStatus: string
{
    case PENDING = 'PENDING';
    case SUPPORTED = 'SUPPORTED';
    case UNSUPPORTED = 'UNSUPPORTED';
    case REJECTED = 'REJECTED';
    case NEEDS_REVIEW = 'NEEDS_REVIEW';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
