<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: user_role. Values are authoritative; do not add without updating the dictionary.
 */
enum UserRole: string
{
    case USER = 'USER';
    case ADMIN = 'ADMIN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
