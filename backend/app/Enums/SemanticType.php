<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: semantic_type. Values are authoritative; do not add without updating the dictionary.
 */
enum SemanticType: string
{
    case NUMBER = 'NUMBER';
    case CURRENCY = 'CURRENCY';
    case PERCENTAGE = 'PERCENTAGE';
    case DATE = 'DATE';
    case CATEGORY = 'CATEGORY';
    case TEXT = 'TEXT';
    case IDENTIFIER = 'IDENTIFIER';
    case BOOLEAN = 'BOOLEAN';
    case IGNORE = 'IGNORE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
