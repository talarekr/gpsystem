<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('shop_events')->cascadeOnDelete();
            $table->string('reason');
            $table->text('message')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('customer_returns'); }
};
