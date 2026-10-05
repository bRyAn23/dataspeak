<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: analysis_stages (FEAT-OPS-001/002). One row per stage attempt.
 */
return new class extends Migration
{
    private const STAGES = ['UPLOAD', 'VALIDATION', 'PROFILING', 'DEFINITION', 'STATISTICAL_ANALYSIS', 'INSIGHT_DETECTION', 'VISUALIZATION', 'AI_SYNTHESIS', 'CLAIM_VALIDATION', 'REPORTING', 'COMPLETE'];

    private const STATUSES = ['PENDING', 'RUNNING', 'SUCCEEDED', 'FAILED', 'SKIPPED'];

    public function up(): void
    {
        Schema::create('analysis_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('analyses')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('stage', 32);
            $table->string('status', 32)->default('PENDING');
            $table->smallInteger('attempt_no')->default(1);
            $table->smallInteger('progress')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->jsonb('result_summary')->nullable();
            $table->timestampsTz();

            $table->index(['analysis_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE analysis_stages ADD CONSTRAINT chk_analysis_stages_stage CHECK (stage IN ('.$this->list(self::STAGES).'))');
            DB::statement('ALTER TABLE analysis_stages ADD CONSTRAINT chk_analysis_stages_status CHECK (status IN ('.$this->list(self::STATUSES).'))');
            DB::statement('ALTER TABLE analysis_stages ADD CONSTRAINT chk_analysis_stages_progress CHECK (progress BETWEEN 0 AND 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_stages');
    }

    private function list(array $values): string
    {
        return implode(', ', array_map(fn ($v) => "'{$v}'", $values));
    }
};
