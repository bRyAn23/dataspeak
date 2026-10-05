<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: claims (MOD-10/11). Structured claims with validation state.
 */
return new class extends Migration
{
    private const KINDS = ['EXECUTIVE_SUMMARY', 'FINDING', 'METRIC', 'CAVEAT'];

    private const STATUSES = ['PENDING', 'SUPPORTED', 'UNSUPPORTED', 'REJECTED', 'NEEDS_REVIEW'];

    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('analysis_id')->constrained('analyses')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete()->cascadeOnUpdate();
            $table->string('claim_kind', 32);
            $table->text('statement');
            $table->string('claimed_value', 128)->nullable();
            $table->string('computed_value', 128)->nullable();
            $table->string('status', 16)->default('PENDING');
            $table->string('validation_method', 64)->nullable();
            $table->string('validation_notes', 500)->nullable();
            $table->boolean('in_report')->default(false);
            $table->smallInteger('position')->nullable();
            $table->timestampsTz();

            $table->index(['analysis_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE claims ADD CONSTRAINT chk_claims_claim_kind CHECK (claim_kind IN ('.$this->list(self::KINDS).'))');
            DB::statement('ALTER TABLE claims ADD CONSTRAINT chk_claims_status CHECK (status IN ('.$this->list(self::STATUSES).'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }

    private function list(array $values): string
    {
        return implode(', ', array_map(fn ($v) => "'{$v}'", $values));
    }
};
