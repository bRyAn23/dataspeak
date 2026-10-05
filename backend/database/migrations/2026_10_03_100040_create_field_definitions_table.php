<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: field_definitions (MOD-05). One per dataset column.
 */
return new class extends Migration
{
    private const SEMANTIC_TYPES = ['NUMBER', 'CURRENCY', 'PERCENTAGE', 'DATE', 'CATEGORY', 'TEXT', 'IDENTIFIER', 'BOOLEAN', 'IGNORE'];

    private const AGGREGATIONS = ['SUM', 'AVERAGE', 'COUNT', 'MIN', 'MAX', 'NONE'];

    public function up(): void
    {
        Schema::create('field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_column_id')->unique()->constrained('dataset_columns')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('business_name', 255);
            $table->string('description', 500)->nullable();
            $table->string('semantic_type', 32);
            $table->string('unit', 32)->nullable();
            $table->string('aggregation', 16)->nullable();
            $table->boolean('is_suggested')->default(false);
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE field_definitions ADD CONSTRAINT chk_field_definitions_semantic_type CHECK (semantic_type IN ('.$this->list(self::SEMANTIC_TYPES).'))');
            DB::statement('ALTER TABLE field_definitions ADD CONSTRAINT chk_field_definitions_aggregation CHECK (aggregation IS NULL OR aggregation IN ('.$this->list(self::AGGREGATIONS).'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_definitions');
    }

    private function list(array $values): string
    {
        return implode(', ', array_map(fn ($v) => "'{$v}'", $values));
    }
};
