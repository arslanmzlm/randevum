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
        // Single clinic-owned anamnesis row per patient. Core clinical columns are shared
        // across every vertical; `extra` carries vertical/clinic-specific answers keyed by
        // anamnesis_fields.key (see create_anamnesis_fields_table).
        Schema::create('anamneses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete()->unique();

            // General
            $table->string('blood_type', 5)->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('smoking')->nullable(); // none / former / occasional / regular
            $table->string('alcohol')->nullable(); // none / occasional / regular

            // Systemic / chronic
            $table->string('diabetes')->nullable(); // none / type1 / type2
            $table->boolean('hypertension')->default(false);
            $table->boolean('cardiovascular')->default(false);
            $table->boolean('respiratory')->default(false);
            $table->boolean('kidney_liver')->default(false);
            $table->boolean('thyroid')->default(false);
            $table->boolean('epilepsy')->default(false);
            $table->boolean('blood_thinners')->default(false); // medication
            $table->boolean('bleeding_disorder')->default(false); // disease — kept separate from blood_thinners
            $table->boolean('infectious_disease')->default(false);
            $table->string('infectious_disease_note', 500)->nullable();
            $table->text('regular_medications')->nullable();
            $table->text('other_chronic')->nullable();

            // Allergy / history
            $table->text('allergies')->nullable();
            $table->text('surgery_history')->nullable();
            $table->text('family_history')->nullable();

            // Women
            $table->string('pregnancy')->nullable(); // none / pregnant / breastfeeding
            $table->text('menstrual_notes')->nullable();

            // Vertical/clinic-specific answers, keyed by anamnesis_fields.key.
            $table->jsonb('extra')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anamneses');
    }
};
