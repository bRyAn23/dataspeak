<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: ai_generations (MOD-09, planned). Requests carry evidence, never rows.
 */
return new class extends Migration
{
    private const TYPES = ['INSIGHT_SYNTHESIS', 'CLAIM_GENERATION', 'NARRATIVE'];

    private const STATUSES = ['PENDING', 'RUNNING', 'SUCCEEDED', 'FAILED', 'SKIPPED'];

    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('analyses')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('generation_type', 32);
            $table->string('status', 16)->default('PENDING');
            $table->smallInteger('attempt_no')->default(1);
            $table->string('model', 128)->nullable();
            $table->string('prompt_version', 32)->nullable();
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampsTz();

            $table->index(['analysis_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_generations ADD CONSTRAINT chk_ai_generations_generation_type CHECK (generation_type IN ('.$this->list(self::TYPES).'))');
            DB::statement('ALTER TABLE ai_generations ADD CONSTRAINT chk_ai_generations_status CHECK (status IN ('.$this->list(self::STATUSES).'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }

    private function list(array $values): string
    {
        return implode(', ', array_map(fn ($v) => "'{$v}'", $values));
    }
};
