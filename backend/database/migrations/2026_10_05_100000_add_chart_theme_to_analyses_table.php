<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FEAT-VIS-004: colour theme chosen at chart review, applied to the web report and the PDF. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table): void {
            $table->string('chart_theme', 32)->default('emerald')->after('report_language');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table): void {
            $table->dropColumn('chart_theme');
        });
    }
};
