<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use App\Filament\Resources\PartResource;
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


    public function test_confirm_publishes_ready_channels_and_skips_blocked_channels(): void
    {
        config(['marketplace.publish_enabled' => true]);
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = $this->completeLocalPart(['category_id' => $category->id]);

        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ovoko', 'external_category_id' => '252', 'external_category_name' => 'Alternator', 'external_category_path' => 'Części / Alternator']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => '261054', 'external_category_name' => 'Alternator', 'external_category_path' => 'Motoryzacja / Części / Alternatory']);

        $response = $this->getJson('/tools/marketplace-publish-part-confirm?token=gps_images_import_2026&part_id='.$part->id.'&channels=all&dry_run=0&confirm=1')
            ->assertOk();

        $this->assertContains('allegro', $response->json('ready_channels'));
        $this->assertContains('ovoko', $response->json('ready_channels'));
        $this->assertContains('allegro', $response->json('published_channels'));
        $this->assertContains('ovoko', $response->json('published_channels'));
        $this->assertArrayHasKey('ebay', $response->json('skipped_channels'));
        $this->assertSame('skipped_blocked_readiness', $response->json('channels.ebay.status'));
        $this->assertFalse((bool) $response->json('channels.ebay.write'));
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'allegro']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de']);
        $part->refresh();
        $this->assertFalse($part->needs_listing);
        $this->assertTrue($part->is_visible_storefront);
        $this->assertSame('ready', $part->status);
        $this->assertTrue($response->json('marketplace_publication_state.has_any_marketplace_listing'));
        $this->assertFalse($response->json('marketplace_publication_state.should_be_in_to_publish'));
    }


    public function test_publish_report_includes_per_channel_ready_and_skipped_without_sending_blocked_channel(): void
    {
        config(['marketplace.publish_enabled' => true]);
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = $this->completeLocalPart(['category_id' => $category->id]);

        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => '261054']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ovoko', 'external_category_id' => '252']);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, 'all', dryRun: false, confirm: true);

        $this->assertSame(['allegro', 'ovoko'], $result['ready_channels']);
        $this->assertArrayHasKey('ebay', $result['skipped_channels']);
        $this->assertSame('skipped_blocked_readiness', $result['channels']['ebay']['status']);
        $this->assertFalse($result['channels']['ebay']['write']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de']);
        $this->assertFalse($part->refresh()->needs_listing);
        $this->assertSame(['allegro', 'ovoko'], $result['marketplace_publication_state']['marketplace_success_channels']);
        $this->assertSame(['ebay'], $result['marketplace_publication_state']['marketplace_blocked_channels']);
    }

    public function test_partial_marketplace_listing_moves_part_out_of_to_publish_and_into_parts(): void
    {
        $part = $this->completeLocalPart(['needs_listing' => true, 'needs_review' => false]);

        DB::table('marketplace_listings')->insert([
            ['marketplace' => 'allegro', 'part_id' => $part->id, 'external_offer_id' => 'ALG-1', 'status' => 'published', 'sync_status' => 'published', 'created_at' => now(), 'updated_at' => now()],
            ['marketplace' => 'ebay_de', 'part_id' => $part->id, 'external_listing_id' => 'EBAY-1', 'url' => 'https://www.ebay.de/itm/EBAY-1', 'status' => 'active', 'sync_status' => 'published', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertTrue(PartResource::adminAllPartsQuery()->whereKey($part->id)->exists());
        $this->assertFalse(PartResource::adminPartsToListQuery()->whereKey($part->id)->exists());
    }

    public function test_single_success_marketplace_listing_moves_part_into_parts(): void
    {
        $part = $this->completeLocalPart(['needs_listing' => true, 'needs_review' => false]);

        DB::table('marketplace_listings')->insert(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => 'OV-1', 'url' => 'https://ovoko.pl/czesci/OV-1', 'status' => 'published', 'sync_status' => 'published', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue(PartResource::adminAllPartsQuery()->whereKey($part->id)->exists());
        $this->assertFalse(PartResource::adminPartsToListQuery()->whereKey($part->id)->exists());
    }

    public function test_part_without_success_marketplace_listing_stays_to_publish(): void
    {
        $part = $this->completeLocalPart(['needs_listing' => true, 'needs_review' => false]);

        $this->assertFalse(PartResource::adminAllPartsQuery()->whereKey($part->id)->exists());
        $this->assertTrue(PartResource::adminPartsToListQuery()->whereKey($part->id)->exists());
    }

    public function test_confirm_explicit_single_channel_publishes_only_that_channel(): void
    {
        config(['marketplace.publish_enabled' => true]);
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = $this->completeLocalPart(['category_id' => $category->id]);

        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => '261054']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ovoko', 'external_category_id' => '252']);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['allegro'], dryRun: false, confirm: true);

        $this->assertSame(['allegro'], $result['ready_channels']);
        $this->assertSame(['allegro'], $result['published_channels']);
        $this->assertArrayHasKey('allegro', $result['channels']);
        $this->assertArrayNotHasKey('ovoko', $result['channels']);
        $this->assertArrayNotHasKey('ebay', $result['channels']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'allegro']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de']);
    }


    public function test_channel_normalization_supports_all_and_explicit_channels(): void
    {
        $service = app(PublishPartToMarketplacesService::class);

        $this->assertSame(['allegro', 'ovoko', 'ebay'], $service->normalizeChannels('all'));
        $this->assertSame(['allegro', 'ebay'], $service->normalizeChannels('allegro,ebay,unknown'));
    }

    private function completeLocalPart(array $overrides = []): Part
    {
        $part = Part::query()->create(array_merge([
            'sku' => 'GPS-PUBLISH-1', 'name' => 'Kompletna część marketplace', 'description' => 'Pełny opis części.',
            'price' => 100, 'ovoko_price' => 120, 'quantity' => 1, 'status' => 'draft',
            'is_visible_storefront' => false, 'needs_listing' => true, 'needs_review' => false,
            'vehicle_snapshot' => ['make' => 'BMW', 'model' => '3'],
        ], $overrides));

        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/complete.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $part;
    }
}
