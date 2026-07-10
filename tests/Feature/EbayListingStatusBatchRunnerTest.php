<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EbayListingStatusBatchRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_start_clamps_batch_size_and_delay_and_stop(): void
    {
        $this->actingAsAdminUser();
        $this->listing('111111111111');

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', [
            'batch_size' => 99, 'delay_seconds' => 1, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync',
        ])->assertOk()->assertJsonPath('status', 'running')->assertJsonPath('dry_run', true)->assertJsonPath('batch_size', 20)->assertJsonPath('delay_seconds', 5)->assertJsonPath('total', 1);

        $this->postJson('/admin/tools/ebay/listing-status-sync/stop', ['confirm' => 'stop-ebay-listing-status-sync'])
            ->assertOk()->assertJsonPath('status', 'stopped');
    }

    public function test_run_next_batch_counts_statuses_continues_after_one_error_and_does_not_mutate_listings(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $active = $this->listing('111111111111', ['status' => 'published']);
        $ended = $this->listing('222222222222', ['status' => 'published']);
        $missing = $this->listing('333333333333', ['status' => 'published']);
        $unknown = $this->listing('444444444444', ['status' => 'published']);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            return match (true) {
                str_contains($url, '111111111111') => Http::response(['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200),
                str_contains($url, '222222222222') => Http::response(['itemEndDate' => '2026-05-31T10:00:00.000Z'], 200),
                str_contains($url, '333333333333') => Http::response(['errors' => []], 404),
                default => Http::response(['errors' => []], 500),
            };
        });

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', [
            'batch_size' => 4, 'delay_seconds' => 5, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync',
        ])->assertOk();

        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('processed', 4)->assertJsonPath('active', 1)->assertJsonPath('ended', 1)->assertJsonPath('not_found', 1)->assertJsonPath('unknown', 1)->assertJsonPath('failed', 1);

        foreach ([$active, $ended, $missing, $unknown] as $listing) {
            $fresh = $listing->fresh();
            $this->assertSame('published', $fresh->status);
            $this->assertNull($fresh->last_api_status);
            $this->assertNull($fresh->last_synced_at);
            $this->assertNull($fresh->not_seen_in_active_api_at);
        }
    }

    public function test_run_next_batch_skips_already_processed_records_and_does_not_run_after_completed(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $this->listing('555555555555');
        $this->listing('666666666666');
        $calls = 0;
        Http::fake(function () use (&$calls) { $calls++; return Http::response(['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200); });

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', ['batch_size' => 1, 'delay_seconds' => 5, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync'])->assertOk();
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('remaining', 1);
        $this->travel(5)->seconds();
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('processed', 2);
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])->assertStatus(422)->assertJsonPath('reason', 'not_running');
        $this->assertSame(2, $calls);
    }

    private function listing(string $itemId, array $overrides = []): MarketplaceListing
    {
        $part = Part::query()->create(['name' => 'Part '.$itemId, 'sku' => 'SKU-'.$itemId, 'quantity' => 1, 'status' => 'ready']);
        return MarketplaceListing::query()->create(array_merge(['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_listing_id' => $itemId, 'status' => 'published'], $overrides));
    }

    private function account(): void
    {
        MarketplaceAccount::query()->create(['code' => 'ebay_de', 'marketplace' => 'ebay_de', 'name' => 'eBay DE', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token'], 'api_settings' => ['marketplace_id' => 'EBAY_DE']]);
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::query()->create(['name' => 'Owner Admin', 'email' => uniqid('admin').'@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        return $user;
    }
}
