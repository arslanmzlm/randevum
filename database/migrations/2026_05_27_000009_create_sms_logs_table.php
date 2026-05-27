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
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            // Nullable: OTP login SMS is platform-level (no clinic). Clinic-scoped sends set it.
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');                          // snapshot of the dialled number
            $table->string('type');                           // SmsType: reminder_24h|otp|appointment_created|...
            $table->string('loggable_type')->nullable();      // morph-map slug: appointment|transaction|case|null (OTP)
            $table->unsignedBigInteger('loggable_id')->nullable();
            $table->text('body');                             // snapshot of the sent text
            $table->string('status');                         // SmsStatus: queued|sent|failed|skipped
            $table->string('provider_ref')->nullable();       // provider message id (delivery report)
            $table->timestampTz('scheduled_at')->nullable();  // queue dispatch time
            $table->timestampTz('sent_at')->nullable();
            $table->text('error')->nullable();                // failed/skipped reason
            $table->timestampsTz();

            $table->index(['clinic_id', 'type', 'created_at']);
            $table->index(['loggable_type', 'loggable_id']);
            $table->index(['patient_id', 'created_at']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
