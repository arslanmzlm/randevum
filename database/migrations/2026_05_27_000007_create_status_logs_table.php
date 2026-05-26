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
        Schema::create('status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained(); // multi-tenant scope
            $table->string('loggable_type');               // morph-map slug: appointment|treatment|case|transaction
            $table->unsignedBigInteger('loggable_id');
            $table->string('from_status')->nullable();      // ilk geçişte null (create)
            $table->string('to_status');
            $table->timestampTz('transitioned_at');
            $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete(); // cron/system: null
            $table->text('reason')->nullable();
            $table->timestampsTz();

            $table->index(['clinic_id', 'loggable_type', 'loggable_id']);
            $table->index(['clinic_id', 'loggable_type', 'transitioned_at']);
            $table->index('transitioned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_logs');
    }
};
