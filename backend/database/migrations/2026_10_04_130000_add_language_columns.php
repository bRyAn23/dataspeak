<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FEAT-I18N-003: the language a report is written in (en, th, zh, my). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table): void {
            $table->string('report_language', 8)->default('en')->after('label_translations');
        });
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('language', 8)->default('en')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', fn (Blueprint $table) => $table->dropColumn('report_language'));
        Schema::table('reports', fn (Blueprint $table) => $table->dropColumn('language'));
    }
};
