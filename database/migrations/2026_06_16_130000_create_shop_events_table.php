<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->nullable()->index();
            $table->string('event_type')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->boolean('is_read')->default(false)->index();
            $table->boolean('requires_action')->default(false)->index();
            $table->string('severity')->default('info')->index();
            $table->string('customer_name')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('url')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_events');
    }
};
