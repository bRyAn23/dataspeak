<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEAT-I18N-002: English display labels for non-Latin headers and category values, built after the analysis run
 * (presentation only; evidence, insights and visualizations keep the original values).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table): void {
            $table->jsonb('label_translations')->nullable()->after('completeness_pct');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table): void {
            $table->dropColumn('label_translations');
        });
    }
};
