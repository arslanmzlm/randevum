<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            // Denormalized for filtering per vertical — soft reference (vertical never deleted).
            $table->foreignId('vertical_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('color', 7); // '#RRGGBB'
            $table->unsignedSmallInteger('default_duration_minutes')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['clinic_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_types');
    }
};
