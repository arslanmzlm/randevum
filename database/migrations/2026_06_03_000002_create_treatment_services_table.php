<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            // Only cascade allowed: line rows are meaningless without their treatment
            $table->foreignId('treatment_id')->constrained('treatments')->cascadeOnDelete();
            // Soft reference: catalog soft-deletes; snapshot survives via unit_price
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['treatment_id', 'sort_order']);
            $table->index(['clinic_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_services');
    }
};
