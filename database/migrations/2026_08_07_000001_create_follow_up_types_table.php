<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // Seeded row: never hard/soft deletable, only deactivated (see the type
            // policy/service — delete() rejects is_system rows).
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['clinic_id', 'is_active']);
        });

        // Case-insensitive uniqueness per clinic among non-deleted rows only — a soft-deleted
        // name must be reusable (tags precedent, adapted for soft deletes).
        DB::statement('CREATE UNIQUE INDEX follow_up_types_clinic_lower_name_unique ON follow_up_types (clinic_id, lower(name)) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_types');
    }
};
