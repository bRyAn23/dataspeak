<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: analyses (MOD-02). Lifecycle state and dataset metadata; never raw rows.
 */
return new class extends Migration
{
    private const STATUSES = ['CREATED', 'UPLOADED', 'VALIDATED', 'PROFILED', 'DEFINED', 'ANALYZING', 'ANALYZED', 'REPORTING', 'COMPLETED', 'FAILED'];

    private const STAGES = ['UPLOAD', 'VALIDATION', 'PROFILING', 'DEFINITION', 'STATISTICAL_ANALYSIS', 'INSIGHT_DETECTION', 'VISUALIZATION', 'AI_SYNTHESIS', 'CLAIM_VALIDATION', 'REPORTING', 'COMPLETE'];

    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('name', 255);
            $table->string('status', 32)->default('CREATED');
            $table->string('current_stage', 32)->default('UPLOAD');
            $table->string('source_file_name', 255)->nullable();
            $table->unsignedBigInteger('source_file_size_bytes')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->integer('row_count')->nullable();
            $table->integer('column_count')->nullable();
            $table->decimal('completeness_pct', 5, 2)->nullable();
            $table->timestampTz('csv_uploaded_at')->nullable();
            $table->timestampTz('csv_purged_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE analyses ADD CONSTRAINT chk_analyses_status CHECK (status IN ('.$this->list(self::STATUSES).'))');
            DB::statement('ALTER TABLE analyses ADD CONSTRAINT chk_analyses_current_stage CHECK (current_stage IN ('.$this->list(self::STAGES).'))');
            DB::statement('ALTER TABLE analyses ADD CONSTRAINT chk_analyses_completeness_pct CHECK (completeness_pct IS NULL OR completeness_pct BETWEEN 0 AND 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }

    private function list(array $values): string
    {
        return implode(', ', array_map(fn ($v) => "'{$v}'", $values));
    }
};
