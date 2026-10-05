<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: analysis_stage. Values are authoritative; do not add without updating the dictionary.
 */
enum AnalysisStage: string
{
    case UPLOAD = 'UPLOAD';
    case VALIDATION = 'VALIDATION';
    case PROFILING = 'PROFILING';
    case DEFINITION = 'DEFINITION';
    case STATISTICAL_ANALYSIS = 'STATISTICAL_ANALYSIS';
    case INSIGHT_DETECTION = 'INSIGHT_DETECTION';
    case VISUALIZATION = 'VISUALIZATION';
    case AI_SYNTHESIS = 'AI_SYNTHESIS';
    case CLAIM_VALIDATION = 'CLAIM_VALIDATION';
    case REPORTING = 'REPORTING';
    case COMPLETE = 'COMPLETE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
