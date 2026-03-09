<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('transactions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_amount');
            $table->unsignedInteger('line_total');
            $table->timestamps();

            $table->unique(['transaction_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_products');
    }
};
