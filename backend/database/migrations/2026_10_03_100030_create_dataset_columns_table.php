<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: dataset_columns (MOD-04). Derived column metadata, not raw rows.
 */
return new class extends Migration
{
    private const TYPES = ['NUMERIC', 'INTEGER', 'DATE', 'DATETIME', 'BOOLEAN', 'CATEGORY', 'TEXT', 'IDENTIFIER', 'UNKNOWN'];

    public function up(): void
    {
        Schema::create('dataset_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('analyses')->restrictOnDelete()->cascadeOnUpdate();
            $table->smallInteger('position');
            $table->string('source_name', 255);
            $table->string('inferred_type', 32)->default('UNKNOWN');
            $table->integer('null_count')->default(0);
            $table->decimal('null_rate', 7, 6)->default(0);
            $table->integer('distinct_count')->nullable();
            $table->jsonb('profile_stats')->nullable();
            $table->timestampsTz();

            $table->unique(['analysis_id', 'source_name']);
            $table->unique(['analysis_id', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($v) => "'{$v}'", self::TYPES));
            DB::statement("ALTER TABLE dataset_columns ADD CONSTRAINT chk_dataset_columns_inferred_type CHECK (inferred_type IN ({$list}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_columns');
    }
};
