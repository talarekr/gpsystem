<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Part;
use App\Models\PartImage;
use App\Models\StorageLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonPath('items.0.final_thumbnail_source', 'marketplace_listing_part')
            ->assertJsonPath('items.0.final_display_name_source', 'marketplace_listing_part')
            ->assertJsonPath('items.0.final_storage_location_source', 'marketplace_listing_part')
            ->assertJsonPath('items.0.storage_location_resolution.storage_location', 'HGF5636')
            ->assertJsonPath('items.0.image_resolution.resolved_thumbnail_url_present', true)
            ->assertJsonPath('items.0.ovoko_mapping_diagnostics.matched_local_part.part_id', $part->id);
    }
}
