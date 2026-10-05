<?php

namespace App\Services\Validation;

use App\Enums\ClaimStatus;

final class ValidationResult
{
    /**
     * @param  list<string>  $reasons  rule codes, e.g. DIGITS_OUTSIDE_PLACEHOLDER
     * @param  array<string, mixed>|null  $claim  the normalised claim (citations completed, kind corrected); text is never changed
     */
    public function __construct(public readonly ClaimStatus $status, public readonly array $reasons, public readonly string $method, public readonly ?array $claim = null) {}

    public function accepted(): bool
    {
        return $this->status === ClaimStatus::SUPPORTED;
    }
}
