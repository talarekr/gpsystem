<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_listing_status_scan_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('mode')->default('persistent_full');
            $table->string('status')->default('idle')->index();
            $table->boolean('dry_run')->default(true);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('remaining')->default(0);
            $table->unsignedInteger('active')->default(0);
            $table->unsignedInteger('ended')->default(0);
            $table->unsignedInteger('not_found')->default(0);
            $table->unsignedInteger('invalid')->default(0);
            $table->unsignedInteger('unknown')->default(0);
            $table->unsignedInteger('failed_requests')->default(0);
            $table->unsignedInteger('rate_limit_hits')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('settings')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('ebay_listing_status_scan_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_run_id')->constrained('ebay_listing_status_scan_runs')->cascadeOnDelete();
            $table->foreignId('marketplace_listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->string('ebay_item_id')->nullable();
            $table->string('local_status')->nullable();
            $table->string('normalized_status')->default('unknown');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_type')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('currently_blocks_relisting')->default(false);
            $table->boolean('should_allow_relisting')->default(false);
            $table->timestamp('item_end_date')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->json('diagnostic')->nullable();
            $table->timestamps();

            $table->unique(['scan_run_id', 'marketplace_listing_id'], 'ebay_scan_run_listing_unique');
            $table->index(['scan_run_id', 'normalized_status'], 'ebay_scan_run_status_index');
            $table->index('marketplace_listing_id', 'ebay_scan_listing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_listing_status_scan_results');
        Schema::dropIfExists('ebay_listing_status_scan_runs');
    }
};
