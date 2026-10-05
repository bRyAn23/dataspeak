<?php

namespace App\Services\Reporting;

use App\Services\Ai\PlaceholderResolver;
use App\Services\Ai\Schemas\ClaimOutputSchema;

/**
 * Substitutes {evidence_id.field} placeholders with values from stored evidence. The stored claim keeps
 * the placeholder form; rendering is deterministic and repeatable. Unresolvable placeholders render as
 * "[unavailable]" (such claims are never SUPPORTED, so they do not reach the report).
 */
class NarrativeRenderer
{
    public const UNAVAILABLE = '[unavailable]';

    public function render(string $template, PlaceholderResolver $resolver): string
    {
        $rendered = preg_replace_callback(
            ClaimOutputSchema::PLACEHOLDER_REGEX,
            fn (array $m) => $resolver->resolve($m[1], $m[2]) ?? self::UNAVAILABLE,
            $template,
        ) ?? $template;

        return trim(preg_replace('/\s{2,}/u', ' ', $rendered) ?? $rendered);
    }
}
