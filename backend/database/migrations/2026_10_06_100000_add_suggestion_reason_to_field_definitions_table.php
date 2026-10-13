<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FEAT-DEF-004: why a column got its suggested meaning and aggregation (shown at Profile and Define). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_definitions', function (Blueprint $table): void {
            $table->string('suggestion_reason', 300)->nullable()->after('is_suggested');
        });
    }

    public function down(): void
    {
        Schema::table('field_definitions', function (Blueprint $table): void {
            $table->dropColumn('suggestion_reason');
        });
    }
};
