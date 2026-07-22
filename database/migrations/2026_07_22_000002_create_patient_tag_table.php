<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pure join table (no historical snapshot value, not a catalog soft-reference) —
        // cascadeOnDelete on both sides is safe, unlike treatment line pivots.
        Schema::create('patient_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['patient_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_tag');
    }
};
