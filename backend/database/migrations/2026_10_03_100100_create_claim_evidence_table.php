<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: claim_evidence (MOD-12). Bridge: a claim is supported by one or more evidence records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_evidence', function (Blueprint $table) {
            $table->foreignId('claim_id')->constrained('claims')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('evidence_id')->constrained('evidence')->restrictOnDelete()->cascadeOnUpdate();
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('created_at')->nullable();

            $table->primary(['claim_id', 'evidence_id']);
            $table->index('evidence_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_evidence');
    }
};
