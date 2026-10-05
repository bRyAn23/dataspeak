<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: ai_generation_type. Values are authoritative; do not add without updating the dictionary.
 */
enum AiGenerationType: string
{
    case INSIGHT_SYNTHESIS = 'INSIGHT_SYNTHESIS';
    case CLAIM_GENERATION = 'CLAIM_GENERATION';
    case NARRATIVE = 'NARRATIVE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
