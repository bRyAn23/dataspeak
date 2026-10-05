<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FEAT-I18N-003: verified claims translated per language; placeholders stay identical to the original statement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', fn (Blueprint $table) => $table->jsonb('statement_translations')->nullable()->after('statement'));
    }

    public function down(): void
    {
        Schema::table('claims', fn (Blueprint $table) => $table->dropColumn('statement_translations'));
    }
};
