<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allegro_shipping_apply_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->unsignedBigInteger('owner_id');
            $table->string('source_digest', 64);
            $table->string('plan_digest', 64);
            $table->unsignedInteger('eligible_count');
            $table->longText('entries');
            $table->longText('report')->nullable();
            $table->timestamp('created_at');
            $table->unique(['run_id', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_shipping_apply_plans');
    }
};
