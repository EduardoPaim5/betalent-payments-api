<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('transactions')->cascadeOnDelete();
            $table->foreignId('gateway_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_order');
            $table->boolean('success');
            $table->string('error_type')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('message')->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['transaction_id', 'attempt_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_attempts');
    }
};
