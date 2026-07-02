<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return array<string, callable(Blueprint): void>
     */
    private function expectedColumns(): array
    {
        return [
            'id' => fn (Blueprint $table): mixed => $table->id(),
            'source_account' => fn (Blueprint $table): mixed => $table->string('source_account')->default('jarek')->index(),
            'allegro_account' => fn (Blueprint $table): mixed => $table->string('allegro_account')->default('jarek')->index(),
            'allegro_offer_id' => fn (Blueprint $table): mixed => $table->string('allegro_offer_id')->unique(),
            'allegro_offer_url' => fn (Blueprint $table): mixed => $table->string('allegro_offer_url')->nullable(),
            'title' => fn (Blueprint $table): mixed => $table->string('title'),
            'description' => fn (Blueprint $table): mixed => $table->longText('description')->nullable(),
            'plain_description' => fn (Blueprint $table): mixed => $table->longText('plain_description')->nullable(),
            'price' => fn (Blueprint $table): mixed => $table->decimal('price', 12, 2)->nullable(),
            'currency' => fn (Blueprint $table): mixed => $table->string('currency', 3)->default('PLN'),
            'quantity' => fn (Blueprint $table): mixed => $table->unsignedInteger('quantity')->default(0),
            'allegro_status' => fn (Blueprint $table): mixed => $table->string('allegro_status')->nullable()->index(),
            'main_image_url' => fn (Blueprint $table): mixed => $table->text('main_image_url')->nullable(),
            'images' => fn (Blueprint $table): mixed => $table->json('images')->nullable(),
            'category_id' => fn (Blueprint $table): mixed => $table->string('category_id')->nullable()->index(),
            'category_name' => fn (Blueprint $table): mixed => $table->string('category_name')->nullable(),
            'category_path' => fn (Blueprint $table): mixed => $table->json('category_path')->nullable(),
            'category_payload' => fn (Blueprint $table): mixed => $table->json('category_payload')->nullable(),
            'parameters' => fn (Blueprint $table): mixed => $table->json('parameters')->nullable(),
            'raw_payload' => fn (Blueprint $table): mixed => $table->json('raw_payload')->nullable(),
            'import_status' => fn (Blueprint $table): mixed => $table->string('import_status')->default('imported')->index(),
            'imported_at' => fn (Blueprint $table): mixed => $table->timestamp('imported_at')->nullable(),
            'updated_from_allegro_at' => fn (Blueprint $table): mixed => $table->timestamp('updated_from_allegro_at')->nullable(),
            'ebay_status' => fn (Blueprint $table): mixed => $table->string('ebay_status')->default('not_ready')->index(),
            'ebay_listing_id' => fn (Blueprint $table): mixed => $table->string('ebay_listing_id')->nullable(),
            'ebay_offer_id' => fn (Blueprint $table): mixed => $table->string('ebay_offer_id')->nullable(),
            'ebay_inventory_sku' => fn (Blueprint $table): mixed => $table->string('ebay_inventory_sku')->nullable(),
            'ebay_payload_snapshot' => fn (Blueprint $table): mixed => $table->json('ebay_payload_snapshot')->nullable(),
            'ebay_published_at' => fn (Blueprint $table): mixed => $table->timestamp('ebay_published_at')->nullable(),
            'created_at' => fn (Blueprint $table): mixed => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table): mixed => $table->timestamp('updated_at')->nullable(),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('jarek_gearboxes')) {
            Schema::create('jarek_gearboxes', function (Blueprint $table): void {
                foreach ($this->expectedColumns() as $addColumn) {
                    $addColumn($table);
                }
            });

            return;
        }

        foreach ($this->expectedColumns() as $column => $addColumn) {
            if (! Schema::hasColumn('jarek_gearboxes', $column)) {
                Schema::table('jarek_gearboxes', function (Blueprint $table) use ($addColumn): void {
                    $addColumn($table);
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('jarek_gearboxes')) {
            Schema::dropIfExists('jarek_gearboxes');
        }
    }
};
