<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            // Forward-compat: cases table ships in 1.11; FK added by editing this migration inline then.
            $table->unsignedBigInteger('case_id')->nullable();
            $table->foreignId('appointment_type_id')->nullable()->constrained('appointment_types')->nullOnDelete();
            // What the patient is coming in for (visit intent) — customer requirement. Nullable +
            // soft reference: a soft-deleted service must not break the appointment. Distinct from
            // treatment_services (what was actually performed + priced, 1.10).
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status')->default('confirmed');
            $table->boolean('is_walk_in')->default(false);
            // Idempotency guards for the reminder SMS scheduler (1.16).
            $table->boolean('reminder_24h_sent')->default(false);
            $table->boolean('reminder_1h_sent')->default(false);
            // Nullable + nullOnDelete: deleting a user keeps the appointment row intact.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['clinic_id', 'doctor_id', 'starts_at']);
            $table->index(['clinic_id', 'patient_id', 'starts_at']);
            $table->index(['clinic_id', 'status', 'starts_at']);
            $table->index(['clinic_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
