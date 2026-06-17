<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained()->cascadeOnDelete(); // null = platform-level (Faz 2 user consent)
            $table->foreignId('legal_document_id')->constrained('legal_documents');
            $table->string('consentable_type'); // morph-map slug, e.g. 'patient'
            $table->unsignedBigInteger('consentable_id');
            $table->timestampTz('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->timestampsTz();

            $table->index(['clinic_id', 'consentable_type', 'consentable_id']);
            $table->index('legal_document_id');
            $table->index('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
