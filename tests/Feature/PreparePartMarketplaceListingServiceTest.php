<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Services\Marketplace\PreparePartMarketplaceListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PreparePartMarketplaceListingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_needs_listing_part_is_locally_published_without_marketplace_write(): void
    {
        $part = Part::query()->create([
            'sku' => 'GPS-LOCAL-1',
            'name' => 'Kompletna część',
            'description' => 'Pełny opis części.',
            'price' => 100,
            'quantity' => 1,
            'status' => 'draft',
            'is_visible_storefront' => false,
            'needs_listing' => true,
            'needs_review' => false,
        ]);

        DB::table('part_images')->insert([
            'part_id' => $part->id,
            'path' => 'parts/photos/complete.jpg',
            'sort_order' => 1,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(PreparePartMarketplaceListingService::class);

        $this->assertSame([], $service->localPublishBlockers($part));
        $preview = $service->preview($part);
        $service->markLocallyListed($part);

        $part->refresh();

        $this->assertFalse($part->needs_listing);
        $this->assertTrue($part->is_visible_storefront);
        $this->assertSame('ready', $part->status);
        $this->assertTrue($preview['dry_run']);
        $this->assertFalse($preview['will_make_marketplace_request']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id]);
    }

    public function test_missing_required_storefront_data_keeps_part_in_needs_listing_queue(): void
    {
        $part = Part::query()->create([
            'name' => 'Niekompletna część',
            'price' => null,
            'quantity' => 1,
            'status' => 'draft',
            'is_visible_storefront' => false,
            'needs_listing' => true,
            'needs_review' => false,
        ]);

        $service = app(PreparePartMarketplaceListingService::class);

        $this->assertNotSame([], $service->localPublishBlockers($part));

        $part->refresh();

        $this->assertTrue($part->needs_listing);
        $this->assertFalse($part->is_visible_storefront);
        $this->assertSame('draft', $part->status);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id]);
    }
}
