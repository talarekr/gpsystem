<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('marketplace')->index();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->default('active')->index();
            $table->json('config')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->string('marketplace');
            $table->foreignId('marketplace_account_id')->nullable()->constrained('marketplace_accounts')->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->string('external_offer_id')->nullable();
            $table->string('external_listing_id')->nullable();
            $table->string('external_inventory_id')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('title')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('currency', 3)->default('PLN');
            $table->string('status')->nullable();
            $table->string('url')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('sync_status')->default('imported');
            $table->string('match_status')->default('unmatched')->index();
            $table->unsignedTinyInteger('match_confidence')->default(0);
            $table->string('match_reason')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['marketplace', 'external_offer_id']);
            $table->index(['marketplace', 'sync_status']);
            $table->index('part_id');
        });

        Schema::create('marketplace_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('marketplace')->index();
            $table->foreignId('marketplace_listing_id')->nullable()->constrained('marketplace_listings')->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->string('action');
            $table->string('status')->default('pending')->index();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_sync_logs');
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplace_accounts');
    }
};
