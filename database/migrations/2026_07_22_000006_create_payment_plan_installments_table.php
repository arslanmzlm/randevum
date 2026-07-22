<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            // Tz-less DATE compared against the clinic-local calendar date (cases.follow_up_date pattern).
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending');
            $table->timestampTz('paid_at')->nullable();
            $table->boolean('reminder_7d_sent')->default(false);
            $table->boolean('reminder_1d_sent')->default(false);
            $table->timestampsTz();

            $table->unique(['payment_plan_id', 'sequence']);
            $table->index(['clinic_id', 'status', 'due_date']);
        });

        // transactions.payment_plan_installment_id is added (nullable, no FK yet) by the
        // transactions create migration itself (edited in place); the FK constraint is
        // attached here, after this table exists, to keep global migration ordering intact.
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('payment_plan_installment_id')
                ->references('id')->on('payment_plan_installments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['payment_plan_installment_id']);
        });

        Schema::dropIfExists('payment_plan_installments');
    }
};
