<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            // Nullable: a transaction need not be tied to a specific treatment
            $table->foreignId('treatment_id')->nullable()->constrained('treatments')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method');
            $table->string('status')->default('completed');
            $table->timestampTz('paid_at');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Links a refund counter-entry back to the payment it reverses; null on normal payments.
            $table->foreignId('original_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['clinic_id', 'treatment_id']);
            $table->index(['clinic_id', 'patient_id', 'paid_at']);
            $table->index(['clinic_id', 'original_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
