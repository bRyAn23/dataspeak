<?php

use App\Enums\ChartType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** FEAT-VIS-005: PIE joins the chart_type enumeration (data dictionary); the Postgres check constraint follows. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($v) => "'{$v}'", ChartType::values()));
            DB::statement('ALTER TABLE visualizations DROP CONSTRAINT IF EXISTS chk_visualizations_chart_type');
            DB::statement("ALTER TABLE visualizations ADD CONSTRAINT chk_visualizations_chart_type CHECK (chart_type IN ({$list}))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($v) => "'{$v}'", array_values(array_diff(ChartType::values(), ['PIE']))));
            DB::statement('ALTER TABLE visualizations DROP CONSTRAINT IF EXISTS chk_visualizations_chart_type');
            DB::statement("ALTER TABLE visualizations ADD CONSTRAINT chk_visualizations_chart_type CHECK (chart_type IN ({$list}))");
        }
    }
};
