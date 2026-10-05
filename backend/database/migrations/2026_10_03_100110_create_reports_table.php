<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: reports (MOD-11). One executive report per analysis.
 */
return new class extends Migration
{
    private const STATUSES = ['DRAFT', 'GENERATING', 'READY', 'FAILED'];

    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->unique()->constrained('analyses')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('status', 16)->default('DRAFT');
            $table->string('title', 255);
            $table->timestampTz('generated_at')->nullable();
            $table->integer('claims_total')->default(0);
            $table->integer('claims_supported')->default(0);
            $table->jsonb('summary_metrics')->nullable();
            $table->jsonb('sections')->nullable();
            $table->jsonb('data_notes')->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($v) => "'{$v}'", self::STATUSES));
            DB::statement("ALTER TABLE reports ADD CONSTRAINT chk_reports_status CHECK (status IN ({$list}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
