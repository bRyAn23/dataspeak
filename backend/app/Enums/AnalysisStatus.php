<?php

namespace App\Enums;

/**
 * Data dictionary enumeration: analysis_status (Specification Section 11 state model).
 * Transition rules live in App\Services\Lifecycle\AnalysisLifecycle, not here.
 */
enum AnalysisStatus: string
{
    case CREATED = 'CREATED';
    case UPLOADED = 'UPLOADED';
    case VALIDATED = 'VALIDATED';
    case PROFILED = 'PROFILED';
    case DEFINED = 'DEFINED';
    case ANALYZING = 'ANALYZING';
    case ANALYZED = 'ANALYZED';
    case REPORTING = 'REPORTING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED], true);
    }

    public function isProcessing(): bool
    {
        return in_array($this, [self::ANALYZING, self::REPORTING], true);
    }

    /** Ordinal position in the happy path; FAILED has none. */
    public function order(): ?int
    {
        return match ($this) {
            self::CREATED => 0, self::UPLOADED => 1, self::VALIDATED => 2, self::PROFILED => 3,
            self::DEFINED => 4, self::ANALYZING => 5, self::ANALYZED => 6, self::REPORTING => 7,
            self::COMPLETED => 8, self::FAILED => null,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->order() !== null && $other->order() !== null && $this->order() >= $other->order();
    }
}
