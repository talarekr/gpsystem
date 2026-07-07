<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\AllegroListingStatusRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroListingStatusRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_promotes_pending_listing_to_active_when_allegro_api_confirms_active_with_stock(): void
    {
        $account = MarketplaceAccount::query()->create([
            'code' => 'allegro_main',
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.test',
            'api_credentials' => ['access_token' => 'token'],
        ]);
        $part = Part::query()->create(['name' => 'Allegro pending part', 'sku' => 'ALG-PENDING', 'quantity' => 1, 'status' => 'ready']);
        $listing = MarketplaceListing::query()->create([
            'marketplace_account_id' => $account->id,
            'part_id' => $part->id,
            'marketplace' => 'allegro',
            'external_offer_id' => '18741244685',
            'external_listing_id' => '18741244685',
            'url' => 'https://allegro.pl/oferta/18741244685',
            'status' => 'publication_pending',
            'sync_status' => 'published',
            'match_status' => 'matched',
            'last_api_status' => null,
            'last_error' => null,
        ]);

        Http::fake([
            'https://api.allegro.test/sale/product-offers/18741244685' => Http::response([
                'id' => '18741244685',
                'publication' => ['status' => 'ACTIVE'],
                'stock' => ['available' => 1],
                'sellingMode' => ['format' => 'BUY_NOW', 'price' => ['amount' => '100', 'currency' => 'PLN']],
            ], 200),
        ]);

        $before = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'allegro');
        $this->assertFalse($before['is_active']);
        $this->assertSame('allegro_not_active', $before['reason']);

        $result = app(AllegroListingStatusRefreshService::class)->refresh($listing);

        $this->assertTrue($result['ok']);
        $this->assertSame(['before' => 'publication_pending', 'after' => 'active'], $result['changes']['status']);
        $this->assertDatabaseHas('marketplace_listings', [
            'id' => $listing->id,
            'status' => 'active',
            'last_api_status' => 'ACTIVE',
            'last_error' => null,
            'url' => 'https://allegro.pl/oferta/18741244685',
        ]);
        $this->assertDatabaseHas('parts', ['id' => $part->id, 'status' => 'ready', 'quantity' => 1]);

        $after = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'allegro');
        $this->assertTrue($after['is_active']);
        $this->assertTrue($after['has_link']);
        $this->assertSame('check', $after['icon']);
        $this->assertSame('✓', $after['display_icon']);
        $this->assertSame('https://allegro.pl/oferta/18741244685', $after['url']);
    }

    public function test_post_publish_safe_refresh_does_not_end_listing_when_api_reports_ended(): void
    {
        $account = MarketplaceAccount::query()->create([
            'code' => 'allegro_main',
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.test',
            'api_credentials' => ['access_token' => 'token'],
        ]);
        $part = Part::query()->create(['name' => 'Allegro safe refresh part', 'sku' => 'ALG-SAFE', 'quantity' => 1, 'status' => 'ready']);
        $listing = MarketplaceListing::query()->create([
            'marketplace_account_id' => $account->id,
            'part_id' => $part->id,
            'marketplace' => 'allegro',
            'external_offer_id' => '18741244686',
            'external_listing_id' => '18741244686',
            'url' => 'https://allegro.pl/oferta/18741244686',
            'status' => 'publication_pending',
            'sync_status' => 'published',
            'match_status' => 'matched',
        ]);

        Http::fake([
            'https://api.allegro.test/sale/product-offers/18741244686' => Http::response([
                'id' => '18741244686',
                'publication' => ['status' => 'ENDED'],
                'stock' => ['available' => 0],
            ], 200),
        ]);

        $result = app(AllegroListingStatusRefreshService::class)->refresh($listing, null, true);

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('status', $result['changes']);
        $this->assertDatabaseHas('marketplace_listings', [
            'id' => $listing->id,
            'status' => 'publication_pending',
            'last_api_status' => 'ENDED',
            'url' => 'https://allegro.pl/oferta/18741244686',
        ]);
        $this->assertDatabaseHas('parts', ['id' => $part->id, 'status' => 'ready']);
    }

    public function test_pending_allegro_refresh_preview_and_apply_are_scoped_and_safe(): void
    {
        $account = MarketplaceAccount::query()->create([
            'code' => 'allegro_main',
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.test',
            'api_credentials' => ['access_token' => 'token'],
        ]);
        $part = Part::query()->create(['name' => 'Allegro batch part', 'sku' => 'ALG-BATCH', 'quantity' => 1, 'status' => 'ready']);
        $listing = MarketplaceListing::query()->create([
            'marketplace_account_id' => $account->id,
            'part_id' => $part->id,
            'marketplace' => 'allegro',
            'external_offer_id' => '18741244687',
            'url' => 'https://allegro.pl/oferta/18741244687',
            'status' => 'publication_pending',
            'sync_status' => 'published',
            'updated_at' => now()->subMinutes(5),
        ]);
        MarketplaceListing::query()->create([
            'marketplace' => 'ovoko',
            'part_id' => $part->id,
            'external_offer_id' => '999',
            'status' => 'publication_pending',
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->withoutMiddleware()
            ->getJson('/admin/tools/marketplace/allegro-diagnose/refresh-pending?format=json&older_than_minutes=2')
            ->assertOk()
            ->assertJsonPath('mode', 'preview')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('rows.0.listing_id', $listing->id)
            ->assertJsonPath('read_only', true);

        Http::fake([
            'https://api.allegro.test/sale/product-offers/18741244687' => Http::response([
                'id' => '18741244687',
                'publication' => ['status' => 'ACTIVE'],
                'stock' => ['available' => 1],
            ], 200),
        ]);

        $this->withoutMiddleware()
            ->postJson('/admin/tools/marketplace/allegro-diagnose/refresh-pending?format=json', ['apply' => 1, 'older_than_minutes' => 2])
            ->assertOk()
            ->assertJsonPath('mode', 'apply')
            ->assertJsonPath('applied.0.after.status', 'active')
            ->assertJsonPath('publishing_triggered', false)
            ->assertJsonPath('ending_triggered', false)
            ->assertJsonPath('links_deleted', false)
            ->assertJsonPath('part_status_changed', false);

        $this->assertDatabaseHas('marketplace_listings', ['id' => $listing->id, 'status' => 'active', 'last_api_status' => 'ACTIVE', 'url' => 'https://allegro.pl/oferta/18741244687']);
        $this->assertDatabaseHas('parts', ['id' => $part->id, 'status' => 'ready']);
    }

    public function test_diagnostics_distinguish_scheduled_from_executed_refresh(): void
    {
        $account = MarketplaceAccount::query()->create([
            'code' => 'allegro_main',
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.test',
            'api_credentials' => ['access_token' => 'token'],
        ]);
        $part = Part::query()->create(['name' => 'Allegro diagnostic part', 'sku' => 'ALG-DIAG', 'quantity' => 1, 'status' => 'ready']);
        $listing = MarketplaceListing::query()->create([
            'marketplace_account_id' => $account->id,
            'part_id' => $part->id,
            'marketplace' => 'allegro',
            'external_offer_id' => '18741244688',
            'status' => 'publication_pending',
        ]);
        MarketplaceSyncLog::query()->create([
            'marketplace' => 'allegro',
            'marketplace_listing_id' => $listing->id,
            'part_id' => $part->id,
            'external_id' => '18741244688',
            'action' => 'allegro_post_publish_status_refresh_scheduled',
            'status' => 'success',
            'message' => 'scheduled',
            'payload' => ['meta' => ['attempt' => 1]],
            'created_at' => now(),
        ]);

        $this->withoutMiddleware()
            ->getJson('/admin/tools/marketplace/allegro-diagnose?format=json&part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('results.0.post_publish_refresh.last_job_status', 'scheduled')
            ->assertJsonPath('results.0.post_publish_refresh.refresh_state', 'refresh_scheduled')
            ->assertJsonPath('results.0.post_publish_refresh.refresh_scheduled', true)
            ->assertJsonPath('results.0.post_publish_refresh.refresh_executed_success', false);
    }

    public function test_fallback_command_logs_last_run_counts_for_diagnostics(): void
    {
        MarketplaceAccount::query()->create([
            'code' => 'allegro_main',
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.test',
            'api_credentials' => ['access_token' => 'token'],
        ]);
        $part = Part::query()->create(['name' => 'Allegro cron part', 'sku' => 'ALG-CRON', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'allegro',
            'external_offer_id' => '18741244689',
            'status' => 'publication_pending',
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('allegro:refresh-pending-listings', ['--dry-run' => true, '--older-than-minutes' => 2])->assertSuccessful();

        $this->withoutMiddleware()
            ->getJson('/admin/tools/marketplace/allegro-diagnose?format=json&part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('queue_diagnostics.fallback_cron.last_status', 'success')
            ->assertJsonPath('queue_diagnostics.fallback_cron.last_pending_count', 1)
            ->assertJsonPath('queue_diagnostics.fallback_cron.last_refreshed_count', 0);
    }

}
