<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->nullable()->unique();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('part_number')->nullable()->index();
            $table->string('oem_number')->nullable()->index();
            $table->string('manufacturer_code')->nullable()->index();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->text('condition_notes')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('part_categories')->nullOnDelete();
            $table->foreignId('suggested_category_id')->nullable()->constrained('part_categories')->nullOnDelete();
            $table->decimal('category_confidence', 5, 2)->nullable();
            $table->text('category_suggestion_reason')->nullable();
            $table->boolean('category_needs_review')->default(false)->index();
            $table->foreignId('car_id')->nullable()->constrained('cars')->nullOnDelete();
            $table->json('vehicle_snapshot')->nullable();
            $table->foreignId('storage_location_id')->nullable()->constrained('storage_locations')->nullOnDelete();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->default('PLN');
            $table->decimal('allegro_price', 12, 2)->nullable();
            $table->decimal('ebay_price', 12, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status')->default('draft')->index();
            $table->boolean('is_visible_storefront')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parts');
    }
};
