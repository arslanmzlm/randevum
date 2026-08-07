<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dated BEFORE create_appointments_table so the appointments FK resolves.
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('vertical_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('status')->default('open');
            $table->text('notes')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampsTz();

            $table->index(['clinic_id', 'patient_id']);
            $table->index(['clinic_id', 'doctor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};
