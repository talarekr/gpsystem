<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\User;
use App\Services\Marketplace\FailedMarketplaceAvailabilityActionRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketplaceAvailabilityRetryToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_allegro_415_retry_calls_only_allegro_end_offer_for_same_offer_id(): void
    {
        Http::fake(['https://allegro.test/*' => Http::response(['publication' => ['status' => 'ENDED']], 200)]);

        $part = Part::query()->create(['name' => 'Already sold part', 'status' => 'sold', 'quantity' => 0, 'is_visible_storefront' => false]);
        $account = MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $listing = MarketplaceListing::query()->create(['id' => 8706, 'marketplace' => 'allegro', 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'external_offer_id' => '17759363397', 'quantity' => 1, 'status' => 'active', 'sync_status' => 'mapped']);
        $failed = MarketplaceSyncLog::query()->create(['marketplace' => 'allegro', 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'action' => 'allegro_end_offer', 'status' => 'error', 'http_status' => '415', 'message' => 'Unsupported Media Type', 'external_id' => '17759363397', 'payload' => ['event_type' => 'sold'], 'created_at' => now()->subMinute()]);

        $result = app(FailedMarketplaceAvailabilityActionRetryService::class)->retry($failed);

        $this->assertTrue($result['ok']);
        $part->refresh();
        $this->assertSame('sold', $part->status);
        $this->assertSame(0, $part->quantity);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://allegro.test/sale/product-offers/17759363397'
            && $request['publication']['status'] === 'ENDED');
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'allegro', 'marketplace_listing_id' => 8706, 'part_id' => $part->id, 'action' => 'allegro_end_offer', 'status' => 'success', 'external_id' => '17759363397']);
        $retryLog = MarketplaceSyncLog::query()->where('status', 'success')->latest('id')->firstOrFail();
        $this->assertTrue((bool) data_get($retryLog->payload, 'retry'));
        $this->assertSame($failed->id, data_get($retryLog->payload, 'retry_of_log_id'));
    }

    /**
     * @dataProvider allegroEndOfferDryRunRegressionProvider
     */
    public function test_allegro_end_offer_dry_run_is_retryable_for_regression_logs(int $logId, int $listingId, int $partId, string $offerId): void
    {
        $part = Part::query()->create(['id' => $partId, 'name' => 'Sold Allegro part '.$partId, 'status' => 'sold', 'quantity' => 0, 'is_visible_storefront' => false]);
        $account = MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $listing = MarketplaceListing::query()->create(['id' => $listingId, 'marketplace' => 'allegro', 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'external_offer_id' => $offerId, 'quantity' => 1, 'status' => 'active', 'sync_status' => 'mapped']);
        $failed = MarketplaceSyncLog::query()->create(['id' => $logId, 'marketplace' => 'allegro', 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'action' => 'allegro_end_offer', 'status' => 'error', 'http_status' => '415', 'message' => 'Unsupported Media Type', 'external_id' => $offerId, 'payload' => ['event_type' => 'sold'], 'created_at' => now()->subMinute()]);

        $preview = app(FailedMarketplaceAvailabilityActionRetryService::class)->preview($failed);

        $this->assertTrue($preview['retryable']);
        $this->assertNull($preview['blocker']);
        $this->assertSame($logId, $preview['original_log_id']);
        $this->assertSame($listingId, $preview['marketplace_listing_id']);
        $this->assertSame($partId, $preview['part_id']);
        $this->assertSame('allegro', $preview['target_marketplace']);
        $this->assertSame('allegro', $preview['listing_marketplace']);
        $this->assertSame('sold', $preview['event_type']);
        $this->assertSame('allegro_end_offer', $preview['action']);
        $this->assertSame($offerId, $preview['external_id']);
        $this->assertTrue($preview['dry_run']);
        $this->assertFalse($preview['local_part_state_will_change']);
        $this->assertFalse($preview['full_availability_event_will_run']);
    }

    public static function allegroEndOfferDryRunRegressionProvider(): array
    {
        return [
            'log 39095 offer 17759363397' => [39095, 8706, 2727, '17759363397'],
            'log 39099 offer 15959835863' => [39099, 11420, 5442, '15959835863'],
        ];
    }

    public function test_retry_page_is_owner_admin_only(): void
    {
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        Role::findOrCreate(UserRole::Manager->value, 'web');
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secret']);
        $manager = User::query()->create(['name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'secret']);
        $owner->assignRole(UserRole::OwnerAdmin->value);
        $manager->assignRole(UserRole::Manager->value);

        $this->actingAs($manager)->get('/admin/retry-failed-availability-actions')->assertForbidden();
        $this->actingAs($owner)->get('/admin/retry-failed-availability-actions')->assertOk();
    }
}
