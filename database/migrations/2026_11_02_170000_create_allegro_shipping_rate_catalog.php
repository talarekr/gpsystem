<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allegro_shipping_catalog_syncs', function (Blueprint $table): void {
            $table->unsignedBigInteger('marketplace_account_id')->primary();
            $table->timestamp('requested_at', 6)->nullable();
            $table->timestamp('synced_at', 6)->nullable();
            $table->unsignedInteger('rates_count')->default(0);
        });
        Schema::create('allegro_shipping_rates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketplace_account_id');
            $table->string('shipping_rate_id');
            $table->string('shipping_rate_name');
            $table->boolean('active');
            $table->timestamp('last_seen_at', 6);
            $table->timestamp('synced_at', 6);
            $table->unique(['marketplace_account_id', 'shipping_rate_id'], 'allegro_shipping_rate_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_shipping_rates');
        Schema::dropIfExists('allegro_shipping_catalog_syncs');
    }
};
