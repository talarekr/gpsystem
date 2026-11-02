<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allegro_shipping_audit_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('owner_id')->index();
            $table->string('state');
            $table->longText('checkpoint');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
        });
        Schema::create('allegro_shipping_audit_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->unsignedBigInteger('part_id');
            $table->boolean('read_failed')->default(false);
            $table->unsignedInteger('retry_generation')->default(0);
            $table->timestamp('retry_not_before')->nullable();
            $table->longText('payload');
            $table->unique(['run_id', 'part_id']);
            $table->index(['run_id', 'read_failed', 'retry_generation'], 'allegro_shipping_audit_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_shipping_audit_results');
        Schema::dropIfExists('allegro_shipping_audit_runs');
    }
};
