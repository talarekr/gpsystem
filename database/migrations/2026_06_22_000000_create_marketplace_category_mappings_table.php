<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_category_mappings')) {
            Schema::create('marketplace_category_mappings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('local_category_id');
                $table->string('channel');
                $table->string('external_category_id')->nullable();
                $table->string('external_category_name')->nullable();
                $table->text('external_category_path')->nullable();
                $table->string('local_category_name')->nullable();
                $table->text('local_category_path')->nullable();
                $table->string('old_category_id')->nullable();
                $table->string('source')->nullable();
                $table->string('confidence')->nullable();
                $table->boolean('is_blocked')->default(false);
                $table->text('block_reason')->nullable();
                $table->string('shipping_group')->nullable();
                $table->string('fulfillment_policy_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();

                $table->unique(['local_category_id', 'channel']);
                $table->index('channel');
                $table->index('external_category_id');
                $table->index('is_blocked');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_category_mappings');
    }
};
