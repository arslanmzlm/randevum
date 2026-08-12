<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // Case-insensitive uniqueness per clinic — tags precedent, adapted here (no soft
        // deletes on this table, so no partial WHERE is needed).
        DB::statement('CREATE UNIQUE INDEX patient_segments_clinic_lower_name_unique ON patient_segments (clinic_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_segments');
    }
};
