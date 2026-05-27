<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Vertical-specific treatment detail (morph target of treatments.details).
        // Standalone: treatments references it via details_type='podiatry'/details_id (no FK).
        Schema::create('podiatry_treatment_details', function (Blueprint $table) {
            $table->id();
            $table->text('complaint')->nullable();          // şikayet — what the patient reports
            $table->text('diagnosis')->nullable();           // tanı — the doctor's finding
            $table->text('treatment_process')->nullable();   // tedavi süreci — what was done
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('podiatry_treatment_details');
    }
};
