<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: column_inferred_type. Values are authoritative; do not add without updating the dictionary.
 */
enum ColumnInferredType: string
{
    case NUMERIC = 'NUMERIC';
    case INTEGER = 'INTEGER';
    case DATE = 'DATE';
    case DATETIME = 'DATETIME';
    case BOOLEAN = 'BOOLEAN';
    case CATEGORY = 'CATEGORY';
    case TEXT = 'TEXT';
    case IDENTIFIER = 'IDENTIFIER';
    case UNKNOWN = 'UNKNOWN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
