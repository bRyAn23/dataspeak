<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: evidence (MOD-06). Deterministic metrics with method metadata.
 * Every number in a report traces to a row here. Aggregates only; never rows.
 */
return new class extends Migration
{
    private const FAMILIES = ['DESCRIPTIVE', 'TREND', 'GROUP_COMPARISON', 'ASSOCIATION', 'STATISTICAL_TEST', 'QUALITY'];

    private const QUALITY = ['ACCEPTABLE', 'WARNING', 'INSUFFICIENT'];

    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('analyses')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('evidence_key', 64);
            $table->string('analysis_family', 32);
            $table->string('metric', 64);
            $table->string('method', 64);
            $table->decimal('value', 24, 8)->nullable();
            $table->string('display_value', 64)->nullable();
            $table->foreignId('metric_column_id')->nullable()->constrained('dataset_columns')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('dimension_column_id')->nullable()->constrained('dataset_columns')->restrictOnDelete()->cascadeOnUpdate();
            $table->jsonb('source_columns')->default('[]');
            $table->jsonb('parameters')->default('{}');
            $table->string('quality_status', 16)->default('ACCEPTABLE');
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['analysis_id', 'evidence_key']);
            $table->index(['analysis_id', 'analysis_family']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE evidence ADD CONSTRAINT chk_evidence_analysis_family CHECK (analysis_family IN ('.$this->list(self::FAMILIES).'))');
            DB::statement('ALTER TABLE evidence ADD CONSTRAINT chk_evidence_quality_status CHECK (quality_status IN ('.$this->list(self::QUALITY).'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }

    private function list(array $values): string
    {
        return implode(', ', array_map(fn ($v) => "'{$v}'", $values));
    }
};
