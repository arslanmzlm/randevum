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
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete(); // null: platform-level
            $table->string('type'); // App\Enums\LegalDocumentType (cast + Rule::enum = source of truth)
            $table->string('version', 20);
            $table->string('title');
            $table->longText('content');
            $table->date('effective_date');
            $table->boolean('is_active')->default(true); // aynı clinic+type için tek aktif versiyon
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz(); // soft delete YOK — immutable versiyonlama

            $table->index(['clinic_id', 'type', 'is_active']);
            $table->index(['type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
