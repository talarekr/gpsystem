<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_fr_attempts', function (Blueprint $table): void {
            $table->uuid('checkout_key')->primary();
            $table->foreignId('order_id')->unique()->constrained('orders');
            $table->foreignId('part_id')->constrained('parts');
            $table->string('session_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('status')->default('creating');
            $table->timestamp('expires_at');
            $table->json('request_payload');
            $table->timestamps();
            $table->index(['part_id', 'status', 'expires_at']);
        });
        Schema::create('stripe_fr_events', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('event_type');
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_fr_events');
        Schema::dropIfExists('stripe_fr_attempts');
    }
};
