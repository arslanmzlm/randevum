<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->jsonb('criteria');
            $table->timestampsTz();

            $table->index('clinic_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_segments');
    }
};
