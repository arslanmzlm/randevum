<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // Signed: negative = out, positive = in; never 0 (zero-delta writes are skipped).
            $table->integer('quantity');
            // current_stock snapshot right after this movement; may be negative.
            $table->integer('balance_after');
            $table->string('reason', 30);
            $table->foreignId('treatment_id')->nullable()->constrained('treatments')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['clinic_id', 'product_id', 'created_at']);
            $table->index(['clinic_id', 'created_at']);
            $table->index('treatment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
