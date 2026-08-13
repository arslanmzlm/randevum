<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            // 1:1 with appointment — one appointment has exactly one treatment
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            // Clinical trio — universal, not vertical-specific: `services` already carries
            // default_complaint / default_diagnosis / default_treatment_process templates.
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment_process')->nullable();
            // Optional polymorphic detail row for a vertical that needs fields of its own.
            $table->string('details_type', 50)->nullable();
            $table->unsignedBigInteger('details_id')->nullable();
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('status')->default('draft');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['clinic_id', 'patient_id', 'created_at']);
            $table->index(['clinic_id', 'doctor_id', 'created_at']);
            $table->index(['clinic_id', 'case_id']);
            $table->index(['clinic_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatments');
    }
};
