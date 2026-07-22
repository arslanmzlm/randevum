<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 7); // '#RRGGBB'
            $table->timestampsTz();

            $table->index('clinic_id');
        });

        // Case-insensitive uniqueness per clinic — prevents "VIP" / "vip" drift, the
        // whole point of a clinic-curated tag list (can't be expressed via a Blueprint unique()).
        DB::statement('CREATE UNIQUE INDEX tags_clinic_lower_name_unique ON tags (clinic_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
