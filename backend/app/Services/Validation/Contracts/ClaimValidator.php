<?php

namespace App\Services\Validation\Contracts;

use App\Services\Ai\PlaceholderResolver;
use App\Services\Validation\ValidationResult;

/**
 * MOD-10. Decides a claim's validation state against the evidence of its analysis.
 * Implementations: RuleBasedClaimValidator (this phase). NliClaimValidator is Planned; the config
 * switch AI_CLAIM_VALIDATOR=nli resolves to a not-configured error rather than a fake.
 */
interface ClaimValidator
{
    /** @param  array{claim_kind: string, text: string, evidence_ids: list<string>}  $claim */
    public function validate(array $claim, PlaceholderResolver $resolver, ?string $locale = null): ValidationResult;

    public function method(): string;
}
