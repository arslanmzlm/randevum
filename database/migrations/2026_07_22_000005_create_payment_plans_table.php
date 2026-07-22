<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            // Nullable: a plan may track a patient's general balance rather than one treatment.
            $table->foreignId('treatment_id')->nullable()->constrained('treatments')->nullOnDelete();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('down_payment', 12, 2)->nullable();
            $table->unsignedSmallInteger('installment_count');
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['clinic_id', 'patient_id']);
            $table->index(['clinic_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
