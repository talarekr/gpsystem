<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jarek_gearboxes', function (Blueprint $table): void {
            $table->id();
            $table->string('source_account')->default('jarek')->index();
            $table->string('allegro_account')->default('jarek')->index();
            $table->string('allegro_offer_id')->unique();
            $table->string('allegro_offer_url')->nullable();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->longText('plain_description')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->default('PLN');
            $table->unsignedInteger('quantity')->default(0);
            $table->string('allegro_status')->nullable()->index();
            $table->text('main_image_url')->nullable();
            $table->json('images')->nullable();
            $table->string('category_id')->nullable()->index();
            $table->string('category_name')->nullable();
            $table->json('parameters')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('import_status')->default('imported')->index();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('updated_from_allegro_at')->nullable();
            $table->string('ebay_status')->default('not_ready')->index();
            $table->string('ebay_listing_id')->nullable();
            $table->string('ebay_offer_id')->nullable();
            $table->string('ebay_inventory_sku')->nullable();
            $table->json('ebay_payload_snapshot')->nullable();
            $table->timestamp('ebay_published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jarek_gearboxes');
    }
};
