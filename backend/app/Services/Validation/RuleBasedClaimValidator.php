<?php

namespace App\Services\Validation;

use App\Enums\AnalysisFamily;
use App\Enums\ClaimKind;
use App\Enums\ClaimStatus;
use App\Enums\QualityStatus;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Ai\Schemas\ClaimOutputSchema;
use App\Services\Reports\DisplayLabels;
use App\Services\Validation\Contracts\ClaimValidator;
use App\Support\Locale;

/**
 * FEAT-VAL-001 rules (prompt Section 8). Schema (rule 1) is enforced by the gateway before claims
 * reach this class. REJECTED beats NEEDS_REVIEW; otherwise SUPPORTED. Every reason code is kept.
 */
class RuleBasedClaimValidator implements ClaimValidator
{
    public const METHOD = 'rule_based_v2';

    /** Share and gap metrics are meaningless without the group they refer to. */
    public const GROUP_METRICS = ['top_group_share', 'top_category_share', 'top_bottom_mean_difference'];

    /** Metrics that describe the dataset's mechanics rather than the business; never summary material. */
    public const MECHANICS_METRICS = ['row_count', 'count', 'completeness', 'duplicate_rows', 'null_rate', 'outlier_count', 'constant_column', 'type_parse_failures', 'distinct_count'];

    public function method(): string
    {
        return self::METHOD;
    }

    public function validate(array $claim, PlaceholderResolver $resolver, ?string $locale = null): ValidationResult
    {
        $locale = Locale::normalize($locale ?? $resolver->locale());

        return $this->run($claim, $resolver, $locale);
    }

    private function run(array $claim, PlaceholderResolver $resolver, string $locale): ValidationResult
    {
        $reject = [];
        $review = [];
        $info = [];
        $text = (string) ($claim['text'] ?? '');
        $kind = (string) ($claim['claim_kind'] ?? ClaimKind::FINDING->value);

        // Normalisation 0 (DEF-01): models sometimes wrap a valid placeholder in extra braces ({{ev_x.label}}).
        // The renderer would substitute the inner token and leave literal braces in the report, so the extra
        // braces are collapsed here before any rule runs; the normalised text is what gets stored.
        $collapsed = preg_replace('/\{+(ev_[a-f0-9]{12}\.[a-z_]+)\}+/u', '{$1}', $text) ?? $text;
        if ($collapsed !== $text) {
            $text = $collapsed;
            $info[] = 'BRACES_NORMALISED';
        }
        $ids = array_values(array_unique(array_map('strval', (array) ($claim['evidence_ids'] ?? []))));

        // Normalisation 1: a placeholder is a citation. Known evidence referenced in the text is added to the list.
        preg_match_all(ClaimOutputSchema::PLACEHOLDER_REGEX, $text, $cited, PREG_SET_ORDER);
        foreach ($cited as $m) {
            if ($resolver->has($m[1]) && ! in_array($m[1], $ids, true)) {
                $ids[] = $m[1];
                $info[] = 'CITATION_COMPLETED:'.$m[1];
            }
        }

        // Normalisation 2: statements resting only on quality evidence or non-acceptable evidence belong in
        // limitations. The kind is corrected (never the text) and the claim is validated as a CAVEAT.
        $knownEarly = array_values(array_filter($ids, fn ($id) => $resolver->has($id)));
        if ($kind !== ClaimKind::CAVEAT->value && $knownEarly !== []) {
            $qualityOnly = collect($knownEarly)->every(function ($id) use ($resolver) {
                $e = $resolver->evidence($id);

                return $e !== null && ($e->analysis_family === AnalysisFamily::QUALITY || $e->quality_status !== QualityStatus::ACCEPTABLE);
            });
            $mechanicsOnly = collect($knownEarly)->every(fn ($id) => in_array($resolver->evidence($id)?->metric, self::MECHANICS_METRICS, true));
            if ($qualityOnly || $mechanicsOnly) {
                $kind = ClaimKind::CAVEAT->value;
                $info[] = 'RECLASSIFIED_AS_CAVEAT';
            }
        }

        // Rule 3: no evidence reference.
        if ($ids === []) {
            $reject[] = 'NO_EVIDENCE_REFERENCE';
        }
        // Rule 2: unknown evidence for this analysis.
        $unknown = array_values(array_filter($ids, fn ($id) => ! $resolver->has($id)));
        if ($unknown !== []) {
            $reject[] = 'UNKNOWN_EVIDENCE:'.implode(',', $unknown);
        }

        // Rules 4 and 5: digits outside placeholders; placeholder field whitelist and resolvability.
        preg_match_all(ClaimOutputSchema::PLACEHOLDER_REGEX, $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            [, $evidenceKey, $field] = $m;
            if (! in_array($field, (array) config('dataspeak.ai.placeholder_fields'), true)) {
                $reject[] = "PLACEHOLDER_FIELD_NOT_ALLOWED:{$field}";
            } elseif (! $resolver->has($evidenceKey)) {
                $reject[] = "PLACEHOLDER_UNKNOWN_EVIDENCE:{$evidenceKey}";
            } elseif ($resolver->resolve($evidenceKey, $field) === null) {
                $reject[] = "PLACEHOLDER_UNRESOLVABLE:{$evidenceKey}.{$field}";
            }
        }
        $stripped = preg_replace(ClaimOutputSchema::PLACEHOLDER_REGEX, '', $text) ?? $text;
        if (preg_match('/\p{N}/u', $stripped)) {
            $reject[] = 'DIGITS_OUTSIDE_PLACEHOLDER';
        }
        // Rule 9 (DEF-01): once every valid placeholder is removed, no brace may remain. A leftover brace is
        // a malformed or invented placeholder (for example {Sales Amount}) that would reach the reader verbatim.
        if (str_contains($stripped, '{') || str_contains($stripped, '}')) {
            $reject[] = 'STRAY_BRACES';
        }
        // Rule 4b: the claim must be written in the report language (FEAT-I18N-002/003); labels and groups arrive
        // through placeholders already translated, so only the model's own words are checked.
        if (! Locale::inLanguage($stripped, $locale)) {
            $reject[] = 'WRONG_LANGUAGE';
        }

        // Semantic guards (review, not reject): the same placeholder used twice in one claim usually means
        // a measure name was used where a group name belongs; a share or gap must name its group.
        $placeholderStrings = array_map(fn ($m) => $m[0], $matches);
        $counts = array_count_values($placeholderStrings);
        foreach ($counts as $ph => $n) {
            if ($n > 1 && ! str_ends_with($ph, '.unit}')) {
                $review[] = 'REPEATED_PLACEHOLDER:'.trim($ph, '{}');
                break;
            }
        }
        foreach ($matches as $m) {
            [, $evidenceKey, $field] = $m;
            $e = $resolver->evidence($evidenceKey);
            if ($e !== null && $field === 'display_value' && in_array($e->metric, self::GROUP_METRICS, true)
                && ! str_contains($text, '{'.$evidenceKey.'.group}') && $resolver->resolve($evidenceKey, 'group') !== null) {
                $review[] = 'GROUP_NOT_NAMED:'.$evidenceKey;
                break;
            }
        }

        // Rule 6: causal language -> review.
        $lower = mb_strtolower($text);
        foreach ((array) config('dataspeak.ai.causal_phrases') as $phrase) {
            $boundaries = DisplayLabels::isLatin($phrase); // Thai, Chinese and Burmese have no word spaces
            if ($boundaries ? preg_match('/(?<![\p{L}])'.preg_quote($phrase, '/').'(?![\p{L}])/u', $lower) : str_contains($lower, $phrase)) {
                $review[] = 'CAUSAL_LANGUAGE:'.str_replace(' ', '_', $phrase);
                break;
            }
        }

        // Rule 7: quality of the cited evidence vs the confidence of the claim.
        $known = array_values(array_filter($ids, fn ($id) => $resolver->has($id)));
        if ($known !== [] && $kind !== ClaimKind::CAVEAT->value) {
            $statuses = array_map(fn ($id) => $resolver->evidence($id)->quality_status, $known);
            if (! in_array(QualityStatus::ACCEPTABLE, $statuses, true)) {
                if (in_array(QualityStatus::WARNING, $statuses, true)) {
                    $review[] = 'LOW_QUALITY_EVIDENCE_AS_FINDING';
                } else {
                    $reject[] = 'INSUFFICIENT_EVIDENCE_AS_FINDING';
                }
            }
        }

        // Rule 8: direction contradiction for signed change metrics.
        $words = preg_split('/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hasInc = (bool) array_intersect($words, (array) config('dataspeak.ai.increase_words'));
        $hasDec = (bool) array_intersect($words, (array) config('dataspeak.ai.decrease_words'));
        if ($hasInc xor $hasDec) {
            foreach ($known as $id) {
                $e = $resolver->evidence($id);
                if ($e === null || $e->value === null || ! in_array($e->metric, (array) config('dataspeak.ai.directional_metrics'), true)) {
                    continue;
                }
                $value = (float) $e->value;
                if (($value < 0 && $hasInc) || ($value > 0 && $hasDec)) {
                    $reject[] = "DIRECTION_CONTRADICTION:{$id}";
                    break;
                }
            }
        }

        $reasons = array_values(array_unique(array_merge($reject, $review, $info)));
        $status = $reject !== [] ? ClaimStatus::REJECTED : ($review !== [] ? ClaimStatus::NEEDS_REVIEW : ClaimStatus::SUPPORTED);
        $normalized = array_merge($claim, ['claim_kind' => $kind, 'evidence_ids' => $ids, 'text' => $text]);

        return new ValidationResult($status, $reasons, self::METHOD, $normalized);
    }
}
