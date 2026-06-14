<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_sms_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('sms_type');   // SmsType value, clinic-scoped SMS types only (OTP excluded)
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['clinic_id', 'sms_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_sms_settings');
    }
};
