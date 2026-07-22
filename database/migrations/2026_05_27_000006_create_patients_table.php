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
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained();                      // klinik sahibi
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // MVP'de hep null
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('phone', 20)->nullable(); // telefon zorunlu değil (legacy/eksik kayıt)
            $table->string('contact_phone', 20)->nullable(); // yakın (acil)
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable(); // App\Enums\Gender (cast + Rule::enum = source of truth)
            $table->boolean('notification_enabled')->default(true);
            $table->boolean('is_legacy')->default(false); // sistem öncesi / migrate edilmiş kayıt
            $table->text('notes')->nullable(); // klinik-seviyesi gözlem
            // Anamnesis morph target (per-vertical detail, mirrors treatments.details_type/_id).
            // Standalone — no FK; reached only through the patient.
            $table->string('anamnesis_type')->nullable();
            $table->unsignedBigInteger('anamnesis_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['clinic_id', 'phone']); // aynı kliniğe aynı numara iki kez kayıt olamaz
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
