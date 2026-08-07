<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Definitions that drive the dynamic anamnesis form, its validation and the PDF.
        // Vertical-scoped rows (clinic_id null) are seed-managed this wave; clinic_id ships
        // for the Faz 3 per-clinic override but is always null for now.
        Schema::create('anamnesis_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vertical_id')->constrained();
            $table->foreignId('clinic_id')->nullable()->constrained();
            $table->string('key', 64);
            $table->string('label', 191); // lang key for seeded rows, rendered via __()
            $table->string('group', 191); // lang key, same convention as label
            $table->string('type', 20); // App\Enums\AnamnesisFieldType
            $table->jsonb('options')->nullable(); // [{"value": "...", "label": "..."}]
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['vertical_id', 'is_active', 'sort']);
        });

        // Postgres treats NULL as distinct, so a plain composite unique on
        // (vertical_id, clinic_id, key) would not stop duplicate global definitions.
        DB::statement(
            'CREATE UNIQUE INDEX anamnesis_fields_vertical_key_unique
                ON anamnesis_fields (vertical_id, key) WHERE clinic_id IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX anamnesis_fields_clinic_key_unique
                ON anamnesis_fields (clinic_id, key) WHERE clinic_id IS NOT NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anamnesis_fields');
    }
};
