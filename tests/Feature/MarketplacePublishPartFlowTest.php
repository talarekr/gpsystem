<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
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

    public function test_ebay_create_offer_existing_offer_id_is_attached_and_published_without_offer_url(): void
    {
        config(['marketplace.publish_enabled' => true]);
        $part = $this->completeEbayPart(['sku' => 'GPS-7700-GJ322C405AF']);
        \App\Models\MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de', 'code' => 'ebay_de', 'name' => 'eBay DE', 'status' => 'active', 'api_enabled' => true,
            'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token'],
            'api_settings' => ['marketplace_id' => 'EBAY_DE', 'merchant_location_key' => 'default', 'fulfillment_policy_id' => 'fulfillment', 'payment_policy_id' => 'payment', 'return_policy_id' => 'return'],
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'api.ebay.test/sell/inventory/v1/inventory_item/*' => \Illuminate\Support\Facades\Http::response(null, 204),
            'api.ebay.test/sell/inventory/v1/offer' => \Illuminate\Support\Facades\Http::response(['errors' => [[
                'errorId' => 25002,
                'message' => 'Preisangebot-Entität existiert bereits.',
                'parameters' => [['name' => 'offerId', 'value' => '199289364011']],
            ]]], 400),
            'api.ebay.test/sell/inventory/v1/offer/199289364011/publish' => \Illuminate\Support\Facades\Http::response(['listingId' => '800113252568'], 200),
        ]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ebay'], dryRun: false, confirm: true);

        $this->assertTrue($result['channels']['ebay']['success']);
        $this->assertSame('Oferta eBay już istniała. Została opublikowana i podpięta.', $result['channels']['ebay']['channels']['ebay_de']['message'] ?? null);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_offer_id' => '199289364011', 'external_listing_id' => '800113252568', 'url' => 'https://www.ebay.de/itm/800113252568']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de', 'url' => 'https://www.ebay.de/itm/199289364011']);
    }


    public function test_ebay_existing_offer_draft_does_not_build_public_url_from_offer_id(): void
    {
        config(['marketplace.publish_enabled' => true]);
        $part = $this->completeEbayPart(['sku' => 'GPS-7700-GJ322C405AF']);
        \App\Models\MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de', 'code' => 'ebay_de', 'name' => 'eBay DE', 'status' => 'active', 'api_enabled' => true,
            'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token'],
            'api_settings' => ['marketplace_id' => 'EBAY_DE', 'merchant_location_key' => 'default', 'fulfillment_policy_id' => 'fulfillment', 'payment_policy_id' => 'payment', 'return_policy_id' => 'return'],
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'api.ebay.test/sell/inventory/v1/inventory_item/*' => \Illuminate\Support\Facades\Http::response(null, 204),
            'api.ebay.test/sell/inventory/v1/offer' => \Illuminate\Support\Facades\Http::response(['errors' => [['errorId' => 25002, 'parameters' => [['name' => 'offerId', 'value' => '199289364011']]]]], 400),
            'api.ebay.test/sell/inventory/v1/offer/199289364011/publish' => \Illuminate\Support\Facades\Http::response(['warnings' => [['message' => 'Still draft']]], 409),
        ]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ebay'], dryRun: false, confirm: true);

        $this->assertTrue($result['channels']['ebay']['success']);
        $this->assertSame('Oferta eBay już istnieje jako szkic. Została podpięta lokalnie, ale nie ma jeszcze publicznego linku.', $result['channels']['ebay']['channels']['ebay_de']['message'] ?? null);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_offer_id' => '199289364011', 'external_listing_id' => null, 'url' => null, 'status' => 'draft']);
    }

    public function test_ebay_existing_offer_conflict_is_controlled(): void
    {
        config(['marketplace.publish_enabled' => true]);
        $part = $this->completeEbayPart(['sku' => 'GPS-7700-GJ322C405AF']);
        $other = Part::query()->create(['name' => 'Other part', 'price' => 10, 'quantity' => 1]);
        \App\Models\MarketplaceListing::query()->create(['part_id' => $other->id, 'marketplace' => 'ebay_de', 'external_offer_id' => '199289364011']);
        \App\Models\MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de', 'code' => 'ebay_de', 'name' => 'eBay DE', 'status' => 'active', 'api_enabled' => true,
            'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token'],
            'api_settings' => ['marketplace_id' => 'EBAY_DE', 'merchant_location_key' => 'default', 'fulfillment_policy_id' => 'fulfillment', 'payment_policy_id' => 'payment', 'return_policy_id' => 'return'],
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'api.ebay.test/sell/inventory/v1/inventory_item/*' => \Illuminate\Support\Facades\Http::response(null, 204),
            'api.ebay.test/sell/inventory/v1/offer' => \Illuminate\Support\Facades\Http::response(['errors' => [['errorId' => 25002, 'parameters' => [['name' => 'offerId', 'value' => '199289364011']]]]], 400),
            'api.ebay.test/sell/inventory/v1/offer/199289364011/publish' => \Illuminate\Support\Facades\Http::response(['listingId' => '800113252568'], 200),
        ]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ebay'], dryRun: false, confirm: true);

        $this->assertFalse($result['channels']['ebay']['success']);
        $this->assertSame('Ta oferta eBay jest już przypisana do innej części. Sprawdź istniejące mapowanie.', $result['channels']['ebay']['channels']['ebay_de']['errors'][0] ?? null);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_offer_id' => '199289364011']);
    }


    public function test_ebay_readiness_accepts_existing_translation_fallback_without_prepared_status(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'api.nbp.pl/*' => \Illuminate\Support\Facades\Http::response(['rates' => [['mid' => 4.3, 'effectiveDate' => '2026-07-06', 'no' => '001/A/NBP/2026']]], 200),
        ]);
        \App\Models\MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de', 'code' => 'ebay_de', 'name' => 'eBay DE', 'status' => 'active', 'api_enabled' => true,
            'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token'],
            'api_settings' => ['marketplace_id' => 'EBAY_DE', 'merchant_location_key' => 'default', 'fulfillment_policy_id' => 'fulfillment', 'payment_policy_id' => 'payment', 'return_policy_id' => 'return'],
        ]);
        $category = PartCategory::query()->create(['name' => 'eBay category']);
        $part = $this->completeLocalPart([
            'category_id' => $category->id,
            'review_metadata' => ['marketplace_translations' => ['ebay_de' => ['title' => 'Fallback DE title', 'description' => 'Fallback DE description.']]],
        ]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => '177697']);

        $readiness = app(\App\Services\Marketplace\MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'ebay_de');
        $card = app(\App\Services\Marketplace\PartMarketplaceReadinessService::class)->check($part)['ebay'];

        $this->assertNotContains('prepared_translations', $readiness['missing_fields']);
        $this->assertNotContains('Brak przygotowanego tłumaczenia eBay DE', $readiness['blockers']);
        $this->assertTrue($readiness['can_publish_later']);
        $this->assertSame('Fallback DE title', $readiness['prepared_payload_preview_safe']['title']);
        $this->assertTrue($card['ready']);
        $this->assertNotContains('Brak przygotowanego tłumaczenia eBay DE', $card['missing']);
    }

    private function completeEbayPart(array $overrides = []): Part
    {
        $category = PartCategory::query()->create(['name' => 'eBay category']);
        $part = $this->completeLocalPart(array_merge(['category_id' => $category->id, 'review_metadata' => ['marketplace_prepared_translations' => ['ebay_de' => ['status' => 'prepared', 'language' => 'de', 'fields' => ['title' => 'Deutscher Titel', 'description' => 'Deutsche Beschreibung.']]]]], $overrides));
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => '177697']);
        return $part;
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
