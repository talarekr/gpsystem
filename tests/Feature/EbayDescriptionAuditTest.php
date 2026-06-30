<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Marketplace\EbayDescriptionAuditService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EbayDescriptionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_asset_src_needs_description_revise(): void
    {
        Http::fake(['gpswiss.pl/ebay-template/assets/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);
        $part = Part::query()->create(['name' => 'Część eBay', 'description' => 'Opis', 'price' => 100, 'quantity' => 1]);
        $listing = MarketplaceListing::query()->create([
            'marketplace' => 'ebay_de', 'part_id' => $part->id, 'status' => 'active', 'external_listing_id' => '123456789012',
            'raw_payload' => ['description_rendered_html' => '<img src="https://gpsystem.thecamels.pl/storage/icon-shipping.png">'],
        ]);

        $row = app(EbayDescriptionAuditService::class)->auditListing($listing->fresh(['part']), 'ebay_de');

        $this->assertTrue($row['needs_description_revise']);
        $this->assertSame('would_revise_description', $row['action']);
        $this->assertSame('high', $row['confidence']);
        $this->assertContains('https://gpsystem.thecamels.pl/storage/icon-shipping.png', $row['old_asset_urls']);
    }

    public function test_current_asset_src_is_ok(): void
    {
        Http::fake(['gpswiss.pl/ebay-template/assets/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);
        $part = Part::query()->create(['name' => 'Część eBay', 'description' => 'Opis', 'price' => 100, 'quantity' => 1]);
        $html = app(\App\Services\Marketplace\EbayDescriptionTemplateRenderer::class)->render('ebay_de', $part, []);
        $listing = MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => $part->id, 'status' => 'active', 'external_listing_id' => '123456789012', 'raw_payload' => ['description_rendered_html' => $html]]);

        $row = app(EbayDescriptionAuditService::class)->auditListing($listing->fresh(['part']), 'ebay_de');

        $this->assertFalse($row['needs_description_revise']);
        $this->assertSame('ok', $row['action']);
    }

    public function test_ended_listing_is_skipped(): void
    {
        Http::fake(['gpswiss.pl/ebay-template/assets/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);
        $part = Part::query()->create(['name' => 'Część eBay', 'description' => 'Opis', 'price' => 100, 'quantity' => 1]);
        $listing = MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => $part->id, 'status' => 'ended', 'raw_payload' => ['description_rendered_html' => '<img src="/storage/old.png">']]);

        $row = app(EbayDescriptionAuditService::class)->auditListing($listing->fresh(['part']), 'ebay_de');

        $this->assertTrue($row['skip_ended_listing']);
        $this->assertFalse($row['needs_description_revise']);
        $this->assertSame('skip_ended_listing', $row['action']);
    }

    public function test_apply_without_confirm_does_nothing(): void
    {
        $this->actingAsAdminUser();
        Http::fake(['gpswiss.pl/ebay-template/assets/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);
        $part = Part::query()->create(['name' => 'Część eBay', 'description' => 'Opis', 'price' => 100, 'quantity' => 1]);
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => $part->id, 'status' => 'active', 'raw_payload' => ['description_rendered_html' => '<img src="/storage/old.png">']]);

        $this->getJson('/admin/tools/marketplace/ebay-description-audit?channel=ebay_de&apply=1')
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('summary.applied', 0)
            ->assertJsonPath('results.0.apply_executed', false);
    }

    public function test_revise_payload_contains_only_listing_description_not_price_or_stock(): void
    {
        Http::fake(['gpswiss.pl/ebay-template/assets/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);
        $part = Part::query()->create(['name' => 'Część eBay', 'description' => 'Opis', 'price' => 100, 'quantity' => 1]);
        $listing = MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => $part->id, 'status' => 'active', 'raw_payload' => ['description_rendered_html' => '<img src="https://gpsystem.thecamels.pl/storage/old.png">']]);

        $row = app(EbayDescriptionAuditService::class)->auditListing($listing->fresh(['part']), 'ebay_de', true, true);

        $this->assertSame(['listingDescription'], array_keys($row['revise_payload_safe']));
        $this->assertSame([], $row['revise_payload_forbidden_keys_present']);
    }


    public function test_patch_assets_only_replaces_only_template_img_srcs(): void
    {
        $part = Part::query()->create(['name' => 'Część eBay', 'description' => 'Opis', 'price' => 100, 'quantity' => 1]);
        $html = '<section><h1>Keep title</h1><img class="x" style="width:1px" src="https://gpsystem.thecamels.pl/storage/icon-shipping-old.png"><p>Keep text</p><img src="/storage/dhl.png"></section>';
        $listing = MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => $part->id, 'status' => 'active', 'external_listing_id' => '123456789012', 'raw_payload' => ['description_rendered_html' => $html]]);

        $row = app(EbayDescriptionAuditService::class)->auditListing($listing->fresh(['part']), 'ebay_de', false, false, false, true);

        $this->assertSame('would_patch_asset_src_only', $row['action']);
        $this->assertSame(2, $row['replacements_count']);
        $this->assertTrue($row['changed_only_img_src']);
        $this->assertFalse($row['forbidden_changes_detected']);
        $this->assertSame([], $row['revise_payload_forbidden_keys_present']);
        $this->assertStringContainsString('<h1>Keep title</h1>', $row['revise_payload_safe']['listingDescription']);
        $this->assertStringContainsString('src="https://gpswiss.pl/ebay-template/assets/icon-shipping.png"', $row['revise_payload_safe']['listingDescription']);
        $this->assertStringContainsString('src="https://gpswiss.pl/ebay-template/assets/dhl-logo.png"', $row['revise_payload_safe']['listingDescription']);
    }

    public function test_patch_assets_only_blocks_without_existing_description(): void
    {
        $part = Part::query()->create(['name' => 'Część eBay', 'description' => 'Opis', 'price' => 100, 'quantity' => 1]);
        $listing = MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => $part->id, 'status' => 'active', 'external_listing_id' => '123456789012', 'raw_payload' => []]);

        $row = app(EbayDescriptionAuditService::class)->auditListing($listing->fresh(['part']), 'ebay_de', false, false, false, true);

        $this->assertSame('blocked', $row['action']);
        $this->assertSame('cannot_patch_assets_only_without_existing_description', $row['blocker']);
        $this->assertNull($row['revise_payload_safe']);
        $this->assertSame(0, $row['replacements_count']);
    }

    public function test_patch_assets_only_can_fetch_live_description_from_trading_get_item(): void
    {
        Http::fake([
            'api.ebay.com/ws/api.dll' => Http::response('<?xml version="1.0" encoding="utf-8"?><GetItemResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><Item><ItemID>800113252568</ItemID><Description><![CDATA[<section><img src="https://gpsystem.thecamels.pl/storage/icon-shipping-old.png"><p>Keep live copy</p></section>]]></Description></Item></GetItemResponse>', 200, ['Content-Type' => 'text/xml']),
            'api.ebay.com/buy/browse/v1/item/*' => Http::response(['itemId' => 'v1|800113252568|0', 'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200),
        ]);

        $account = MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de',
            'name' => 'eBay DE',
            'code' => 'ebay_de',
            'api_enabled' => true,
            'api_base_url' => 'https://api.ebay.com',
            'api_mode' => 'read_only',
            'api_credentials' => ['access_token' => 'token'],
            'api_settings' => ['marketplace_id' => 'EBAY_DE', 'site_id' => '77'],
        ]);
        $part = Part::query()->create(['name' => 'Część eBay', 'description' => 'Opis', 'price' => 100, 'quantity' => 1]);
        $listing = MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'status' => 'active', 'external_listing_id' => '800113252568', 'raw_payload' => []]);

        $row = app(EbayDescriptionAuditService::class)->auditListing($listing->fresh(['part', 'account']), 'ebay_de', false, false, false, true, true);

        $this->assertSame('ebay_trading_get_item', $row['live_description_source']);
        $this->assertGreaterThan(0, $row['live_description_length']);
        $this->assertTrue($row['live_description_can_confirm_assets']);
        $this->assertSame('would_patch_asset_src_only', $row['action']);
        $this->assertSame(1, $row['replacements_count']);
        $this->assertTrue($row['changed_only_img_src']);
        $this->assertFalse($row['forbidden_changes_detected']);
        $this->assertContains('https://gpsystem.thecamels.pl/storage/icon-shipping-old.png', $row['live_listing_asset_urls']);
        $this->assertContains('https://gpsystem.thecamels.pl/storage/icon-shipping-old.png', $row['stale_asset_urls']);
    }

    public function test_patch_assets_only_blocks_when_live_description_fetch_is_unavailable(): void
    {
        Http::fake([
            'api.ebay.com/ws/api.dll' => Http::response('<?xml version="1.0" encoding="utf-8"?><GetItemResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Failure</Ack><Errors><LongMessage>No description available.</LongMessage></Errors></GetItemResponse>', 200, ['Content-Type' => 'text/xml']),
            'api.ebay.com/buy/browse/v1/item/*' => Http::response(['itemId' => 'v1|800113252568|0', 'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200),
        ]);

        $account = MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de',
            'name' => 'eBay DE',
            'code' => 'ebay_de',
            'api_enabled' => true,
            'api_base_url' => 'https://api.ebay.com',
            'api_mode' => 'read_only',
            'api_credentials' => ['access_token' => 'token'],
            'api_settings' => ['marketplace_id' => 'EBAY_DE', 'site_id' => '77'],
        ]);
        $part = Part::query()->create(['name' => 'Część eBay', 'description' => 'Opis', 'price' => 100, 'quantity' => 1]);
        $listing = MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'status' => 'active', 'external_listing_id' => '800113252568', 'raw_payload' => []]);

        $row = app(EbayDescriptionAuditService::class)->auditListing($listing->fresh(['part', 'account']), 'ebay_de', false, false, false, true, true);

        $this->assertSame('blocked', $row['action']);
        $this->assertSame('cannot_fetch_live_description', $row['blocker']);
        $this->assertSame('not_available', $row['live_description_source']);
        $this->assertFalse($row['live_description_can_confirm_assets']);
        $this->assertNull($row['revise_payload_safe']);
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::query()->create(['name' => 'Owner Admin', 'email' => 'owner'.uniqid().'@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        return $user;
    }
}
