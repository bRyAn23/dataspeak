<?php

namespace Database\Factories;

use App\Enums\ClaimKind;
use App\Enums\ClaimStatus;
use App\Models\Analysis;
use App\Models\Claim;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Claim> */
class ClaimFactory extends Factory
{
    protected $model = Claim::class;

    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'claim_kind' => ClaimKind::FINDING,
            'statement' => 'Average revenue per order is 292.4 THB.',
            'claimed_value' => '292.4',
            'computed_value' => '292.4',
            'status' => ClaimStatus::SUPPORTED,
            'validation_method' => 'numeric_tolerance',
            'in_report' => true,
            'position' => 1,
        ];
    }
}
