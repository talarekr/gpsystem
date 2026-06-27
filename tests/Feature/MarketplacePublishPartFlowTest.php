<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplacePublishPartFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only_and_reports_no_marketplace_write(): void
    {
        $part = $this->completeLocalPart();

        $response = $this->getJson('/tools/marketplace-publish-part-preview?token=gps_images_import_2026&part_id='.$part->id.'&channels=all&dry_run=1&include_payload=1')
            ->assertOk();

        $response->assertJsonPath('dry_run', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('needs_listing_changed', false);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id]);
    }

    public function test_preview_incomplete_part_returns_blockers(): void
    {
        $part = Part::query()->create(['name' => '', 'quantity' => 0, 'needs_listing' => true]);

        $response = $this->getJson('/tools/marketplace-publish-part-preview?token=gps_images_import_2026&part_id='.$part->id.'&channels=allegro&dry_run=1')
            ->assertOk();

        $this->assertFalse($response->json('channels.allegro.success'));
        $this->assertNotEmpty($response->json('channels.allegro.errors'));
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id]);
    }

    public function test_confirm_without_feature_flag_is_blocked_and_does_not_write(): void
    {
        config(['marketplace.publish_enabled' => false]);
        $part = $this->completeLocalPart();

        $response = $this->getJson('/tools/marketplace-publish-part-confirm?token=gps_images_import_2026&part_id='.$part->id.'&channels=all&dry_run=0&confirm=1')
            ->assertOk();

        $response->assertJsonPath('marketplace_publish_enabled', false)
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('marketplace_write', false);
        $this->assertTrue($part->refresh()->needs_listing);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id]);
    }

    public function test_channel_normalization_supports_all_and_explicit_channels(): void
    {
        $service = app(PublishPartToMarketplacesService::class);

        $this->assertSame(['allegro', 'ovoko', 'ebay'], $service->normalizeChannels('all'));
        $this->assertSame(['allegro', 'ebay'], $service->normalizeChannels('allegro,ebay,unknown'));
    }

    private function completeLocalPart(): Part
    {
        $part = Part::query()->create([
            'sku' => 'GPS-PUBLISH-1', 'name' => 'Kompletna część marketplace', 'description' => 'Pełny opis części.',
            'price' => 100, 'ovoko_price' => 120, 'quantity' => 1, 'status' => 'draft',
            'is_visible_storefront' => false, 'needs_listing' => true, 'needs_review' => false,
            'vehicle_snapshot' => ['make' => 'BMW', 'model' => '3'],
        ]);

        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/complete.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $part;
    }
}
