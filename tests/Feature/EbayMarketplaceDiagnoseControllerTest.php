<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Admin\PartMarketplaceStatusResolver;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EbayMarketplaceDiagnoseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_get_only_renders_form_without_api_calls_or_bulk_audit(): void
    {
        $this->actingAsAdminUser();
        Http::fake(fn () => throw new \RuntimeException('Unexpected eBay API call'));

        $part = Part::query()->create(['name' => 'eBay part', 'sku' => 'EB-1', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_listing_id' => '123456789012', 'status' => 'active']);

        $this->get('/admin/tools/ebay/marketplace-diagnose')
            ->assertOk()
            ->assertSee('Sprawdź podane part_id')
            ->assertSee('Uruchom audyt masowy')
            ->assertSee('Oznacz zakończone jako historyczne', false)
            ->assertDontSee('EB-1');

        Http::assertNothingSent();
    }

    public function test_empty_json_get_does_not_default_to_bulk_or_check_api(): void
    {
        $this->actingAsAdminUser();
        Http::fake(fn () => throw new \RuntimeException('Unexpected eBay API call'));

        $this->getJson('/admin/tools/ebay/marketplace-diagnose?format=json')
            ->assertOk()
            ->assertJsonPath('input.bulk_mode', false)
            ->assertJsonPath('input.check_api', false)
            ->assertJsonCount(0, 'rows');

        Http::assertNothingSent();
    }

    public function test_past_end_date_is_ended_not_active_and_does_not_block_duplicate_guard(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Ended eBay', 'sku' => 'EB-END', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_de',
            'external_listing_id' => '123456789012',
            'status' => 'active',
            'last_api_status' => 'active',
            'raw_payload' => ['itemEndDate' => '2026-05-31T10:00:00.000Z'],
        ]);

        $resolverRow = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'ebay');
        $this->assertSame('✕', $resolverRow['display_icon']);

        Http::fake(['*' => Http::response([
            'itemId' => 'v1|123456789012|0',
            'itemEndDate' => '2026-05-31T10:00:00.000Z',
            'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']],
        ], 200)]);

        $this->getJson('/admin/tools/ebay/marketplace-diagnose?action=part&part_ids='.$part->id.'&check_api=1&format=json')
            ->assertOk()
            ->assertJsonPath('rows.0.audit_classification', 'ended/stale should_show_x_and_allow_new_publish')
            ->assertJsonPath('rows.0.duplicate_guard_would_block', false)
            ->assertJsonPath('rows.0.marketplace_listings.0.api.api_listing_status', 'ended')
            ->assertJsonPath('rows.0.marketplace_listings.0.api.end_date_is_past', true)
            ->assertJsonPath('rows.0.public_item_end_date_source', 'browse_api');
    }


    public function test_ended_target_ebay_listing_is_not_overridden_by_active_other_ebay_marketplace(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Mixed eBay', 'sku' => 'EB-MIX', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_de',
            'external_listing_id' => '123456789012',
            'status' => 'active',
            'last_api_status' => 'active',
            'raw_payload' => ['itemEndDate' => '2026-05-31T10:00:00.000Z'],
        ]);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_fr',
            'external_listing_id' => '987654321098',
            'status' => 'active',
            'last_api_status' => 'active',
        ]);

        $resolverRow = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'ebay');
        $this->assertSame('✕', $resolverRow['display_icon']);
        $this->assertSame('ebay_end_date_in_past', $resolverRow['reason']);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            return str_contains($url, '123456789012')
                ? Http::response(['itemId' => 'v1|123456789012|0', 'itemEndDate' => '2026-05-31T10:00:00.000Z'], 200)
                : Http::response(['itemId' => 'v1|987654321098|0', 'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200);
        });

        $this->getJson('/admin/tools/ebay/marketplace-diagnose?action=part&part_ids='.$part->id.'&check_api=1&format=json')
            ->assertOk()
            ->assertJsonPath('rows.0.audit_classification', 'ended/stale should_show_x_and_allow_new_publish')
            ->assertJsonPath('rows.0.duplicate_guard_would_block', false)
            ->assertJsonPath('rows.0.resolver_ebay.display_icon', '✕')
            ->assertJsonPath('rows.0.resolver_ebay.reason', 'ebay_end_date_in_past');
    }


    public function test_unavailable_without_past_end_date_needs_review_and_is_not_historical_candidate(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Unclear eBay', 'sku' => 'EB-UNAVAILABLE', 'quantity' => 1, 'status' => 'ready']);
        $listing = MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_de',
            'external_listing_id' => '800116181167',
            'url' => 'https://www.ebay.de/itm/800116181167',
            'status' => 'active',
            'last_api_status' => 'active',
        ]);

        Http::fakeSequence()
            ->push([
                'itemId' => 'v1|800116181167|0',
                'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'UNAVAILABLE']],
                'itemWebUrl' => 'https://www.ebay.de/itm/800116181167',
                'title' => 'Visible listing',
            ], 200)
            ->push([], 404)
            ->push(['offers' => []], 200)
            ->push([], 404);

        $this->postJson('/admin/tools/ebay/marketplace-diagnose?action=apply_inactive&part_ids='.$part->id.'&check_api=1&confirm_apply_inactive=1&format=json')
            ->assertOk()
            ->assertJsonPath('rows.0.ebay_de_status', 'unavailable_not_ended_needs_review')
            ->assertJsonPath('rows.0.audit_classification', 'manual_review_public_status_required needs_review needs_manual_review_public_ended_unknown_api')
            ->assertJsonPath('rows.0.needs_ebay_de_publish', null)
            ->assertJsonPath('rows.0.marketplace_listings.0.listing_exists', true)
            ->assertJsonPath('rows.0.duplicate_guard_would_block', false)
            ->assertJsonPath('rows.0.resolver_ebay.display_icon', '✕')
            ->assertJsonPath('rows.0.resolver_ebay.reason', 'ebay_unavailable_but_not_ended_needs_review')
            ->assertJsonPath('rows.0.marketplace_listings.0.api.end_date_is_past', false)
            ->assertJsonPath('rows.0.marketplace_listings.0.api.availability_status', 'UNAVAILABLE');

        $listing->refresh();
        $this->assertSame('active', $listing->status);
        $this->assertNotSame('historical', $listing->sync_status);
    }

    public function test_seller_side_active_overrides_browse_unavailable(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Seller verified eBay', 'sku' => 'EB-SELLER-ACTIVE', 'quantity' => 1, 'status' => 'ready']);
        $listing = MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_de',
            'external_listing_id' => '800116033033',
            'external_offer_id' => '123456789',
            'external_inventory_id' => 'EB-SELLER-ACTIVE',
            'url' => 'https://www.ebay.de/itm/800116033033',
            'status' => 'active',
            'last_api_status' => 'active',
        ]);

        Http::fakeSequence()
            ->push([
                'itemId' => 'v1|800116033033|0',
                'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'UNAVAILABLE']],
                'itemWebUrl' => 'https://www.ebay.de/itm/800116033033',
                'title' => 'Visible listing',
            ], 200)
            ->push(['availability' => ['shipToLocationAvailability' => ['quantity' => 1]]], 200)
            ->push(['offers' => [['offerId' => '123456789', 'listingId' => '800116033033', 'status' => 'PUBLISHED']]], 200)
            ->push(['offerId' => '123456789', 'listingId' => '800116033033', 'status' => 'PUBLISHED'], 200)
            ->push(['itemWebUrl' => 'https://www.ebay.de/itm/800116033033'], 200);

        $this->postJson('/admin/tools/ebay/marketplace-diagnose?action=apply_inactive&part_ids='.$part->id.'&check_api=1&confirm_apply_inactive=1&format=json')
            ->assertOk()
            ->assertJsonPath('rows.0.ebay_de_status', 'active_seller_verified')
            ->assertJsonPath('rows.0.audit_classification', 'active_seller_verified active OK')
            ->assertJsonPath('rows.0.needs_ebay_de_publish', false)
            ->assertJsonPath('rows.0.duplicate_guard_would_block', true)
            ->assertJsonPath('rows.0.marketplace_listings.0.api.seller_side_verified_active', true)
            ->assertJsonPath('rows.0.marketplace_listings.0.api.seller_side.offer_status', 'PUBLISHED')
            ->assertJsonPath('rows.0.marketplace_listings.0.public_item_id', '800116033033')
            ->assertJsonPath('rows.0.marketplace_listings.0.seller_offer_id', '123456789')
            ->assertJsonPath('rows.0.marketplace_listings.0.seller_listing_id', '800116033033')
            ->assertJsonPath('rows.0.marketplace_listings.0.seller_listing_id_matches_public_item_id', true)
            ->assertJsonPath('rows.0.marketplace_listings.0.requested_listing_id', '800116033033')
            ->assertJsonPath('rows.0.marketplace_listings.0.offer_listing_id', '800116033033')
            ->assertJsonPath('rows.0.marketplace_listings.0.seller_listing_status', 'PUBLICLY_READABLE')
            ->assertJsonPath('rows.0.marketplace_listings.0.seller_offer_status', 'PUBLISHED')
            ->assertJsonPath('rows.0.public_item_id', '800116033033')
            ->assertJsonPath('rows.0.seller_listing_id_matches_public_item_id', true);

        $listing->refresh();
        $this->assertSame('active', $listing->status);
        $this->assertNotSame('historical', $listing->sync_status);
    }

    public function test_seller_side_active_for_different_listing_does_not_override_public_item(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Seller mismatch eBay', 'sku' => 'EB-SELLER-MISMATCH', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_de',
            'external_listing_id' => '389993224459',
            'external_offer_id' => '123456789',
            'external_inventory_id' => 'EB-SELLER-MISMATCH',
            'url' => 'https://www.ebay.de/itm/389993224459',
            'status' => 'active',
            'last_api_status' => 'active',
        ]);

        Http::fakeSequence()
            ->push([
                'itemId' => 'v1|389993224459|0',
                'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'UNAVAILABLE']],
                'itemWebUrl' => 'https://www.ebay.de/itm/389993224459',
                'title' => 'Ended listing',
            ], 200)
            ->push(['availability' => ['shipToLocationAvailability' => ['quantity' => 1]]], 200)
            ->push(['offers' => [['offerId' => '123456789', 'listingId' => '800116033033', 'status' => 'PUBLISHED']]], 200)
            ->push(['offerId' => '123456789', 'listingId' => '800116033033', 'status' => 'PUBLISHED'], 200)
            ->push(['itemWebUrl' => 'https://www.ebay.de/itm/800116033033'], 200);

        $this->postJson('/admin/tools/ebay/marketplace-diagnose?action=apply_inactive&part_ids='.$part->id.'&check_api=1&confirm_apply_inactive=1&format=json')
            ->assertOk()
            ->assertJsonPath('rows.0.ebay_de_status', 'unavailable_not_ended_needs_review')
            ->assertJsonPath('rows.0.audit_classification', 'manual_review_public_status_required needs_review needs_manual_review_public_ended_unknown_api')
            ->assertJsonPath('rows.0.needs_ebay_de_publish', null)
            ->assertJsonPath('rows.0.duplicate_guard_would_block', false)
            ->assertJsonPath('rows.0.resolver_ebay.display_icon', '✕')
            ->assertJsonPath('rows.0.marketplace_listings.0.public_item_id', '389993224459')
            ->assertJsonPath('rows.0.marketplace_listings.0.seller_listing_id', '800116033033')
            ->assertJsonPath('rows.0.marketplace_listings.0.requested_listing_id', '389993224459')
            ->assertJsonPath('rows.0.marketplace_listings.0.offer_listing_id', '800116033033')
            ->assertJsonPath('rows.0.marketplace_listings.0.seller_listing_id_matches_public_item_id', false)
            ->assertJsonPath('rows.0.public_item_id', '389993224459')
            ->assertJsonPath('rows.0.seller_listing_id', '800116033033')
            ->assertJsonPath('rows.0.requested_listing_id', '389993224459')
            ->assertJsonPath('rows.0.offer_listing_id', '800116033033')
            ->assertJsonPath('rows.0.seller_listing_id_matches_public_item_id', false)
            ->assertJsonPath('rows.0.marketplace_listings.0.public_item_end_past', false)
            ->assertJsonPath('rows.0.marketplace_listings.0.public_item_end_date_source', 'unavailable')
            ->assertJsonPath('rows.0.marketplace_listings.0.raw_payload_diagnostics.contains_any_end_date', false)
            ->assertJsonPath('rows.0.marketplace_listings.0.raw_payload_diagnostics.extracted_end_date', null)
            ->assertJsonPath('rows.0.marketplace_listings.0.raw_payload_diagnostics.message', 'raw_payload does not contain itemEndDate/endDate/end_date/listingEndDate')
            ->assertJsonPath('rows.0.marketplace_listings.0.api.seller_side_verified_active', null);
    }


    public function test_local_raw_payload_end_date_fallback_marks_public_item_ended(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Ended public URL', 'sku' => 'EB-PUBLIC-END', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_de',
            'external_listing_id' => '389993224459',
            'url' => 'https://www.ebay.de/itm/389993224459',
            'status' => 'active',
            'last_api_status' => 'active',
            'raw_payload' => ['itemEndDate' => '2026-05-31T21:00:00.000Z'],
        ]);

        Http::fake(['*' => Http::response([
            'itemId' => 'v1|389993224459|0',
            'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'UNAVAILABLE']],
            'itemWebUrl' => 'https://www.ebay.de/itm/389993224459',
            'title' => 'Ended listing without Browse itemEndDate',
        ], 200)]);

        $this->getJson('/admin/tools/ebay/marketplace-diagnose?action=part&part_ids='.$part->id.'&check_api=1&format=json')
            ->assertOk()
            ->assertJsonPath('rows.0.ebay_de_status', 'ended')
            ->assertJsonPath('rows.0.audit_classification', 'ended/stale should_show_x_and_allow_new_publish')
            ->assertJsonPath('rows.0.duplicate_guard_would_block', false)
            ->assertJsonPath('rows.0.public_item_end_date', '2026-05-31T21:00:00.000Z')
            ->assertJsonPath('rows.0.public_item_end_date_source', 'local_raw_payload')
            ->assertJsonPath('rows.0.public_item_end_past', true)
            ->assertJsonPath('rows.0.seller_offer_id', null)
            ->assertJsonPath('rows.0.seller_listing_id', null)
            ->assertJsonPath('rows.0.resolver_ebay.display_icon', '✕');
    }


    public function test_active_ebay_fr_does_not_satisfy_main_ebay_or_de_publish_need(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'French only eBay', 'sku' => 'EB-FR', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_fr',
            'external_listing_id' => '987654321098',
            'url' => 'https://www.ebay.fr/itm/987654321098',
            'status' => 'active',
            'last_api_status' => 'active',
        ]);

        $resolverRow = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'ebay');
        $this->assertSame('✕', $resolverRow['display_icon']);
        $this->assertSame('missing_listing', $resolverRow['reason']);
        $this->assertNull($resolverRow['url']);

        $this->getJson('/admin/tools/ebay/marketplace-diagnose?action=part&part_ids='.$part->id.'&format=json')
            ->assertOk()
            ->assertJsonPath('rows.0.ebay_de_status', 'missing')
            ->assertJsonPath('rows.0.ebay_de_url', null)
            ->assertJsonPath('rows.0.ebay_fr_status', 'active')
            ->assertJsonPath('rows.0.ebay_fr_url', 'https://www.ebay.fr/itm/987654321098')
            ->assertJsonPath('rows.0.needs_ebay_de_publish', true)
            ->assertJsonPath('rows.0.resolver_ebay.display_icon', '✕');
    }

    public function test_apply_inactive_is_not_allowed_by_get(): void
    {
        $this->actingAsAdminUser();

        $this->get('/admin/tools/ebay/marketplace-diagnose?action=apply_inactive&check_api=1')
            ->assertStatus(405);
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create(['name' => 'Owner Admin', 'email' => 'owner-ebay@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
