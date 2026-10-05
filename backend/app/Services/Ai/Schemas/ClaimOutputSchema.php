<?php

namespace App\Services\Ai\Schemas;

use App\Enums\ClaimKind;

/**
 * JSON schema for the model output. Shared by the Ollama `format` parameter and the strict parser.
 * Numbers never appear in the output: claim text uses {ev_xxxxxxxxxxxx.field} placeholders only.
 */
final class ClaimOutputSchema
{
    public const PLACEHOLDER_REGEX = '/\{(ev_[a-f0-9]{12})\.([a-z_]+)\}/u';

    public const EVIDENCE_ID_REGEX = '/^ev_[a-f0-9]{12}$/';

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $claim = [
            'type' => 'object',
            'properties' => [
                'claim_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16],
                'claim_kind' => ['type' => 'string', 'enum' => [ClaimKind::EXECUTIVE_SUMMARY->value, ClaimKind::FINDING->value, ClaimKind::CAVEAT->value]],
                'text' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                'evidence_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 6, 'items' => ['type' => 'string', 'pattern' => '^ev_[a-f0-9]{12}$']],
                'confidence_language' => ['type' => 'string', 'enum' => ['descriptive']],
            ],
            'required' => ['claim_key', 'claim_kind', 'text', 'evidence_ids', 'confidence_language'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'claims' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => $claim],
                'limitations' => ['type' => 'array', 'maxItems' => 10, 'items' => $claim],
            ],
            'required' => ['claims', 'limitations'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Strict structural validation of a decoded response against schema(). Returns a list of
     * human-readable problems (empty when valid). Hand-written so the rules are explicit and tested.
     *
     * @return list<string>
     */
    public static function problems(mixed $decoded): array
    {
        $problems = [];
        if (! is_array($decoded) || array_is_list($decoded)) {
            return ['response must be a JSON object'];
        }
        foreach (array_keys($decoded) as $key) {
            if (! in_array($key, ['claims', 'limitations'], true)) {
                $problems[] = "unexpected top-level key '{$key}'";
            }
        }
        foreach (['claims', 'limitations'] as $list) {
            if (! array_key_exists($list, $decoded) || ! is_array($decoded[$list]) || ! array_is_list($decoded[$list])) {
                $problems[] = "'{$list}' must be an array";

                continue;
            }
            if ($list === 'claims' && count($decoded[$list]) === 0) {
                $problems[] = "'claims' must contain at least one claim";
            }
            foreach ($decoded[$list] as $i => $claim) {
                $problems = array_merge($problems, array_map(fn ($p) => "{$list}[{$i}]: {$p}", self::claimProblems($claim)));
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function claimProblems(mixed $claim): array
    {
        if (! is_array($claim) || array_is_list($claim)) {
            return ['claim must be an object'];
        }
        $p = [];
        $allowed = ['claim_key', 'claim_kind', 'text', 'evidence_ids', 'confidence_language'];
        foreach (array_keys($claim) as $key) {
            if (! in_array($key, $allowed, true)) {
                $p[] = "unexpected key '{$key}'";
            }
        }
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $claim)) {
                $p[] = "missing '{$key}'";
            }
        }
        if (isset($claim['claim_key']) && (! is_string($claim['claim_key']) || $claim['claim_key'] === '' || mb_strlen($claim['claim_key']) > 16)) {
            $p[] = 'claim_key must be a short string';
        }
        if (isset($claim['claim_kind']) && ! in_array($claim['claim_kind'], [ClaimKind::EXECUTIVE_SUMMARY->value, ClaimKind::FINDING->value, ClaimKind::CAVEAT->value], true)) {
            $p[] = 'claim_kind must be EXECUTIVE_SUMMARY, FINDING or CAVEAT';
        }
        if (isset($claim['text']) && (! is_string($claim['text']) || mb_strlen($claim['text']) < 10 || mb_strlen($claim['text']) > 600)) {
            $p[] = 'text must be a string of 10 to 600 characters';
        }
        if (isset($claim['evidence_ids'])) {
            if (! is_array($claim['evidence_ids']) || ! array_is_list($claim['evidence_ids']) || count($claim['evidence_ids']) < 1 || count($claim['evidence_ids']) > 6) {
                $p[] = 'evidence_ids must list 1 to 6 ids';
            } else {
                foreach ($claim['evidence_ids'] as $id) {
                    if (! is_string($id) || ! preg_match(self::EVIDENCE_ID_REGEX, $id)) {
                        $p[] = 'evidence_ids entries must look like ev_ followed by 12 hex characters';
                        break;
                    }
                }
            }
        }
        if (isset($claim['confidence_language']) && $claim['confidence_language'] !== 'descriptive') {
            $p[] = "confidence_language must be 'descriptive'";
        }

        return $p;
    }
}
