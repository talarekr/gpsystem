<?php

namespace Tests\Feature;

use App\Enums\UserRole;
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
