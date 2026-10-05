<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: visualizations (MOD-08). Chart specifications only; the frontend renders.
 */
return new class extends Migration
{
    private const CHART_TYPES = ['LINE', 'BAR', 'SCATTER', 'HISTOGRAM', 'TABLE'];

    public function up(): void
    {
        Schema::create('visualizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('analyses')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('insight_id')->nullable()->constrained('insights')->nullOnDelete()->cascadeOnUpdate();
            $table->string('chart_type', 16);
            $table->string('title', 255);
            $table->jsonb('spec');
            $table->jsonb('evidence_keys')->default('[]');
            $table->smallInteger('position')->default(0);
            $table->timestampTz('created_at')->nullable();

            $table->index(['analysis_id', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($v) => "'{$v}'", self::CHART_TYPES));
            DB::statement("ALTER TABLE visualizations ADD CONSTRAINT chk_visualizations_chart_type CHECK (chart_type IN ({$list}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visualizations');
    }
};
