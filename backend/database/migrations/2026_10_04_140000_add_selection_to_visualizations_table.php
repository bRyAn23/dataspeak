<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** FEAT-VIS-003: chart review at the Analyze step. Recommended charts can be deselected; users can add charts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visualizations', function (Blueprint $table): void {
            $table->string('source', 16)->default('RECOMMENDED')->after('chart_type');
            $table->boolean('is_selected')->default(true)->after('position');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE visualizations ADD CONSTRAINT chk_visualizations_source CHECK (source IN ('RECOMMENDED', 'USER'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE visualizations DROP CONSTRAINT IF EXISTS chk_visualizations_source');
        }
        Schema::table('visualizations', fn (Blueprint $table) => $table->dropColumn(['source', 'is_selected']));
    }
};
