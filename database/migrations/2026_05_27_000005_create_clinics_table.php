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
        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();   // multi-tenant scope (account root)
            $table->foreignId('vertical_id')->constrained(); // vertical-bound, immutable (service layer)

            // identity
            $table->string('name');
            $table->string('slug', 100)->unique(); // public URL
            $table->text('description')->nullable();

            // contact
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // address
            $table->foreignId('country_id')->constrained();
            $table->foreignId('city_id')->nullable()->constrained();
            $table->string('district', 100)->nullable(); // ilçe
            $table->text('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // operational
            $table->json('working_hours');
            $table->unsignedSmallInteger('default_slot_duration_minutes')->default(30);
            $table->string('timezone', 50)->default('Europe/Istanbul');
            $table->string('locale', 10)->default('tr_TR');
            $table->char('currency', 3)->default('TRY');

            // status
            $table->boolean('is_active')->default(true);
            $table->timestampTz('onboarded_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['vertical_id', 'city_id', 'is_active']);
            $table->index(['country_id', 'city_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinics');
    }
};
