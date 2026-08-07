<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            // Catalog FK as a soft reference (data-modeling rule): the type row may be
            // deleted while history rows keep referencing it; renders as "—" when null.
            $table->foreignId('follow_up_type_id')->nullable()->constrained('follow_up_types')->nullOnDelete();
            // Tz-less DATE compared against the clinic-local calendar date (cases.follow_up_date /
            // payment_plan_installments.due_date pattern).
            $table->date('due_date');
            $table->text('note')->nullable();
            $table->string('status')->default('open');
            // Forward-compat: always null in MVP, no assignment UI (see the owner brief).
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->text('result_note')->nullable();
            $table->timestampsTz();

            // The "aranacaklar" query: status = open AND due_date <= today, clinic-scoped.
            $table->index(['clinic_id', 'status', 'due_date']);
            $table->index(['clinic_id', 'patient_id']);
            $table->index(['clinic_id', 'case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
