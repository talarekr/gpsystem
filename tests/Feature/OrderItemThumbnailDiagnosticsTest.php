<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Part;
use App\Models\PartImage;
use App\Models\StorageLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderItemThumbnailDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ovoko_order_item_resolves_listing_by_marketplace_item_id_external_offer_id(): void
    {
        $storageLocation = StorageLocation::query()->create(['name' => 'HGF5636']);
        $part = Part::query()->create([
            'name' => 'AUDI Q5 8R 2.0 TDI QUATTRO OSŁONA SILNIKA SKRZYNI KOMPLET',
            'storage_location_id' => $storageLocation->id,
        ]);
        PartImage::query()->create([
            'part_id' => $part->id,
            'path' => 'parts/photos/imported/15325/30290b1e4486b0ab9b8abb8b9470.jpg',
            'is_primary' => true,
        ]);
        $listing = MarketplaceListing::query()->create([
            'marketplace' => 'ovoko',
            'part_id' => $part->id,
            'external_offer_id' => '5636',
            'title' => 'Snapshot title should not win',
        ]);
        $order = Order::query()->create([
            'order_number' => 'OVOKO-8365433',
            'marketplace' => 'ovoko',
            'marketplace_order_id' => '8365433',
            'subtotal' => 10,
            'total' => 10,
            'customer_name' => 'Test',
            'email' => 'test@example.com',
            'phone' => '123',
            'address_line1' => 'Street',
            'postal_code' => '00-000',
            'city' => 'Warszawa',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'marketplace' => 'ovoko',
            'marketplace_order_id' => '8365433',
            'marketplace_item_id' => '5636',
            'product_name' => 'Osłona dolna silnika',
            'unit_price' => 10,
            'quantity' => 1,
            'line_total' => 10,
            'raw_payload' => ['item_list' => [['id' => '5636', 'car_id' => '421']]],
        ]);

        $response = $this->getJson('/tools/debug-order-item-thumbnail?token=gps_images_import_2026&order_id='.$order->id);

        $response->assertOk()
            ->assertJsonPath('items.0.listing_resolution.listing_found', true)
            ->assertJsonPath('items.0.listing_resolution.listing_id', $listing->id)
            ->assertJsonPath('items.0.part_resolution.part_found', true)
            ->assertJsonPath('items.0.part_resolution.part_id', $part->id)
            ->assertJsonPath('items.0.final_thumbnail_source', 'admin_parts_thumbnail')
            ->assertJsonPath('items.0.final_display_name_source', 'marketplace_listing_part')
            ->assertJsonPath('items.0.final_storage_location_source', 'marketplace_listing_part')
            ->assertJsonPath('items.0.storage_location_resolution.storage_location', 'HGF5636')
            ->assertJsonPath('items.0.image_resolution.resolved_thumbnail_url_present', true)
            ->assertJsonPath('items.0.image_resolution.admin_parts_thumbnail_url_present', true)
            ->assertJsonPath('items.0.ovoko_mapping_diagnostics.matched_local_part.part_id', $part->id);
    }

    public function test_order_thumbnail_uses_same_admin_parts_thumbnail_url_for_local_part(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('parts/photos/imported/part/base.jpg', 'base');
        Storage::disk('public')->put('parts/photos/presentation/listing/part.jpg', 'listing');
        Storage::disk('public')->put('parts/photos/presentation/product/part.jpg', 'product');

        $part = Part::query()->create(['name' => 'Presentation part']);
        $image = PartImage::query()->create([
            'part_id' => $part->id,
            'path' => 'parts/photos/imported/part/base.jpg',
            'is_primary' => true,
        ]);
        $image->forceFill([
            'legacy_payload' => [
                'presentation' => [
                    'listing_path' => 'parts/photos/presentation/listing/part.jpg',
                    'product_path' => 'parts/photos/presentation/product/part.jpg',
                    'metrics' => [
                        'listing' => ['fill_ratio' => ['width_ratio' => 0.50, 'height_ratio' => 0.50, 'dominant_ratio' => 0.70]],
                        'product' => ['fill_ratio' => ['width_ratio' => 0.90, 'height_ratio' => 0.90, 'dominant_ratio' => 0.95]],
                    ],
                ],
            ],
        ])->saveQuietly();

        $order = Order::query()->create([
            'order_number' => 'ORDER-PRESENTATION',
            'subtotal' => 10,
            'total' => 10,
            'customer_name' => 'Test',
            'email' => 'test@example.com',
            'phone' => '123',
            'address_line1' => 'Street',
            'postal_code' => '00-000',
            'city' => 'Warszawa',
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'part_id' => $part->id,
            'product_name' => 'Presentation part',
            'unit_price' => 10,
            'quantity' => 1,
            'line_total' => 10,
            'raw_payload' => ['image_url' => 'https://example.test/snapshot.jpg'],
        ]);

        $debug = \App\Support\OrderItemThumbnailDiagnostics::resolve($order, $item);

        $this->assertSame($part->adminTableImageUrl(), $debug['thumbnail_url']);
        $this->assertStringContainsString('/storage/parts/photos/presentation/product/part.jpg', $debug['thumbnail_url']);
        $this->assertSame('admin_parts_thumbnail', $debug['thumbnail_source']);
        $this->assertTrue($debug['admin_parts_thumbnail_url_present']);
        $this->assertSame($part->id, $debug['thumbnail_part_id']);
    }

}
