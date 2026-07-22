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
        // Vertical-specific anamnesis detail (morph target of patients.anamnesis).
        // Standalone: patients references it via anamnesis_type='podiatry_anamnesis'/anamnesis_id (no FK).
        Schema::create('podiatry_anamneses', function (Blueprint $table) {
            $table->id();

            // General
            $table->string('blood_type', 5)->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('smoking')->nullable();
            $table->string('alcohol')->nullable();

            // Systemic / chronic
            $table->string('diabetes')->nullable(); // null=yok / type1 / type2
            $table->boolean('hypertension')->default(false);
            $table->boolean('cardiovascular')->default(false);
            $table->boolean('blood_thinners')->default(false);
            $table->text('regular_medications')->nullable();
            $table->text('other_chronic')->nullable();

            // Allergy
            $table->text('allergies')->nullable();

            // Women
            $table->string('pregnancy')->nullable(); // pregnant / breastfeeding

            // Podiatry-specific
            $table->text('foot_surgery_history')->nullable();
            $table->boolean('diabetic_foot_history')->default(false);
            $table->text('current_foot_complaint')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('podiatry_anamneses');
    }
};
