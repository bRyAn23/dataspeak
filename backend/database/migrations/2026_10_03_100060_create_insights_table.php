<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: insights (MOD-07). Ranked findings linked to evidence.
 */
return new class extends Migration
{
    private const TYPES = ['TREND_CHANGE', 'GROUP_DIFFERENCE', 'CONCENTRATION', 'ASSOCIATION', 'DISTRIBUTION', 'DATA_QUALITY'];

    public function up(): void
    {
        Schema::create('insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('analyses')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('evidence_id')->constrained('evidence')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('insight_type', 32);
            $table->string('headline', 255);
            $table->string('description', 1000)->nullable();
            $table->decimal('score', 6, 4);
            $table->smallInteger('rank');
            $table->boolean('is_selected')->default(true);
            $table->jsonb('supporting_evidence_keys')->default('[]');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['analysis_id', 'rank']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($v) => "'{$v}'", self::TYPES));
            DB::statement("ALTER TABLE insights ADD CONSTRAINT chk_insights_insight_type CHECK (insight_type IN ({$list}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('insights');
    }
};
