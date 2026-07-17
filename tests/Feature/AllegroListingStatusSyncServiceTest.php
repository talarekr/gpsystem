<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\AllegroListingStatusSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroListingStatusSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_remote_active_is_consistent_noop(): void
    {
        $listing = $this->listing(['status' => 'active', 'last_api_status' => 'ACTIVE']);
        $this->fake($listing, 'ACTIVE', 1);

        $result = app(AllegroListingStatusSyncService::class)->dryRun(['listing_id' => $listing->id]);

        $this->assertSame('consistent', $result['classification']);
        $this->assertSame([], $result['changed_fields']);
        $this->assertFalse($result['would_modify_database']);
    }

    public function test_active_remote_ended_dry_run_proposes_ended_and_resolver_inactive_after_live(): void
    {
        $listing = $this->listing(['status' => 'active', 'sync_status' => 'mapped', 'last_api_status' => 'ACTIVE']);
        $this->fake($listing, 'ENDED', 1);

        $dry = app(AllegroListingStatusSyncService::class)->dryRun(['part_id' => $listing->part_id]);
        $this->assertSame('ended', $dry['proposed']['status']);
        $this->assertSame('ended', $dry['proposed']['sync_status']);
        $this->assertFalse($dry['proposed']['active_indicator']);
        $this->assertSame('remote_ended_local_active', $dry['classification']);
        $listing->refresh();
        $this->assertSame('active', $listing->status);

        $this->fake($listing, 'ENDED', 1);
        app(AllegroListingStatusSyncService::class)->sync(['listing_id' => $listing->id, 'mode' => 'live', 'confirm' => AllegroListingStatusSyncService::CONFIRM]);
        $row = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($listing->part->fresh('marketplaceListings')))->firstWhere('key', 'allegro');
        $this->assertFalse($row['is_active']);
    }

    public function test_inactive_and_activating_map_to_non_final_inactive_local_states(): void
    {
        $inactive = $this->listing(['external_offer_id' => 'offer-inactive', 'external_listing_id' => 'offer-inactive', 'status' => 'active']);
        $this->fake($inactive, 'INACTIVE', 1);
        $result = app(AllegroListingStatusSyncService::class)->dryRun(['listing_id' => $inactive->id]);
        $this->assertSame('inactive', $result['proposed']['status']);
        $this->assertFalse($result['proposed']['active_indicator']);

        $activating = $this->listing(['external_offer_id' => 'offer-activating', 'external_listing_id' => 'offer-activating', 'status' => 'inactive']);
        $this->fake($activating, 'ACTIVATING', 1);
        $result = app(AllegroListingStatusSyncService::class)->dryRun(['listing_id' => $activating->id]);
        $this->assertSame('publication_pending', $result['proposed']['status']);
        $this->assertFalse($result['proposed']['active_indicator']);
    }

    public function test_dry_run_does_not_mutate_preserved_fields_or_call_write_api(): void
    {
        $listing = $this->listing(['status' => 'active', 'quantity' => 7, 'raw_payload' => ['kept' => true], 'last_synced_at' => '2026-06-21 00:23:56']);
        $partStatus = $listing->part->status;
        $this->fake($listing, 'ENDED', 1);

        app(AllegroListingStatusSyncService::class)->dryRun(['listing_id' => $listing->id]);
        $listing->refresh();

        $this->assertSame('active', $listing->status);
        $this->assertSame('2026-06-21T00:23:56.000000Z', $listing->last_synced_at->toISOString());
        $this->assertSame(['kept' => true], $listing->raw_payload);
        $this->assertSame('offer-1', $listing->external_offer_id);
        $this->assertSame('https://allegro.pl/oferta/offer-1', $listing->url);
        $this->assertSame(7, $listing->quantity);
        $this->assertSame($partStatus, $listing->part->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'PATCH'], true));
    }

    public function test_live_requires_confirm_and_confirmed_live_only_updates_allowed_fields_and_is_idempotent(): void
    {
        $listing = $this->listing(['status' => 'active', 'last_api_status' => 'ACTIVE']);
        $this->fake($listing, 'ENDED', 1);
        $blocked = app(AllegroListingStatusSyncService::class)->sync(['listing_id' => $listing->id, 'mode' => 'live']);
        $this->assertContains('live_requires_confirm_SYNC_LOCAL_STATUS', $blocked['blockers']);
        $this->assertSame('active', $listing->fresh()->status);

        $this->fake($listing, 'ENDED', 1);
        $live = app(AllegroListingStatusSyncService::class)->sync(['listing_id' => $listing->id, 'mode' => 'live', 'confirm' => AllegroListingStatusSyncService::CONFIRM]);
        $this->assertTrue($live['writes']['database']);
        $listing->refresh();
        $this->assertSame('ended', $listing->status);
        $this->assertSame('offer-1', $listing->external_offer_id);
        $this->assertSame('https://allegro.pl/oferta/offer-1', $listing->url);

        $this->fake($listing, 'ENDED', 1);
        $again = app(AllegroListingStatusSyncService::class)->sync(['listing_id' => $listing->id, 'mode' => 'live', 'confirm' => AllegroListingStatusSyncService::CONFIRM]);
        $this->assertSame([], $again['changed_fields']);
        $this->assertFalse($again['writes']['database']);
    }

    public function test_error_statuses_and_404_do_not_mark_ended_or_clear_mapping(): void
    {
        foreach ([401, 403, 429, 500, 404] as $code) {
            $listing = $this->listing(['external_offer_id' => 'offer-'.$code, 'external_listing_id' => 'offer-'.$code, 'status' => 'active']);
            Http::fake(['https://api.allegro.test/sale/product-offers/'.$listing->external_offer_id => Http::response(['message' => 'failed'], $code)]);
            $result = app(AllegroListingStatusSyncService::class)->sync(['listing_id' => $listing->id, 'mode' => 'live', 'confirm' => AllegroListingStatusSyncService::CONFIRM]);
            $this->assertNull($result['proposed']);
            $this->assertFalse($result['writes']['database']);
            $listing->refresh();
            $this->assertSame('active', $listing->status);
            $this->assertNotNull($listing->external_offer_id);
        }
    }

    public function test_missing_offer_id_other_marketplace_and_ambiguous_part_are_blocked(): void
    {
        $missing = $this->listing(['external_offer_id' => null, 'external_listing_id' => null]);
        $result = app(AllegroListingStatusSyncService::class)->dryRun(['listing_id' => $missing->id]);
        $this->assertContains('missing_offer_id', $result['blockers']);
        Http::assertNothingSent();

        $other = $this->listing(['marketplace' => 'ovoko', 'external_offer_id' => 'ovoko-1']);
        $result = app(AllegroListingStatusSyncService::class)->dryRun(['listing_id' => $other->id]);
        $this->assertContains('listing_is_not_allegro', $result['blockers']);

        $part = Part::query()->create(['name' => 'Ambiguous', 'quantity' => 1, 'status' => 'ready']);
        $this->listing(['part_id' => $part->id, 'external_offer_id' => 'a']);
        $this->listing(['part_id' => $part->id, 'external_offer_id' => 'b']);
        $result = app(AllegroListingStatusSyncService::class)->dryRun(['part_id' => $part->id]);
        $this->assertContains('ambiguous_allegro_listing_for_part', $result['blockers']);
    }

    public function test_regression_shape_for_active_local_and_ended_remote_without_special_ids(): void
    {
        $listing = $this->listing(['status' => 'active', 'sync_status' => 'mapped', 'last_api_status' => 'ACTIVE']);
        $this->fake($listing, 'ENDED', 1);

        $result = app(AllegroListingStatusSyncService::class)->dryRun(['part_id' => $listing->part_id]);

        $this->assertSame('remote_ended_local_active', $result['classification']);
        $this->assertSame('ENDED', $result['remote']['publication_status']);
        $this->assertSame('ended', $result['proposed']['status']);
        $this->assertFalse($result['writes']['database']);
        $this->assertFalse($result['writes']['allegro']);
    }

    private function account(): MarketplaceAccount
    {
        return MarketplaceAccount::query()->first() ?: MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.test', 'api_credentials' => ['access_token' => 'token']]);
    }

    private function listing(array $attrs = []): MarketplaceListing
    {
        $part = isset($attrs['part_id']) ? Part::query()->find($attrs['part_id']) : Part::query()->create(['name' => 'Generic Allegro part', 'sku' => 'ALG-'.uniqid(), 'quantity' => 1, 'status' => 'ready']);
        return MarketplaceListing::query()->create(array_merge(['marketplace_account_id' => $this->account()->id, 'part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => 'offer-1', 'external_listing_id' => 'offer-1', 'url' => 'https://allegro.pl/oferta/offer-1', 'status' => 'active', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'quantity' => 1], $attrs));
    }

    private function fake(MarketplaceListing $listing, string $status, int $stock): void
    {
        Http::fake(['https://api.allegro.test/sale/product-offers/'.$listing->external_offer_id => Http::response(['publication' => ['status' => $status, 'republish' => false], 'stock' => ['available' => $stock]], 200)]);
    }
}
