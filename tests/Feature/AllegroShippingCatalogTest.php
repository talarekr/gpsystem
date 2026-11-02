<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Services\Marketplace\AllegroSalesSettingsResolver;
use App\Services\Marketplace\AllegroShippingCatalog;
use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateAuditService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class AllegroShippingCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function account(): int
    {
        return DB::table('marketplace_accounts')->insertGetId(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Maxhendler', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => Crypt::encryptString(json_encode(['access_token' => 'SECRET']))]);
    }

    private function fake(array $rates): void
    {
        Http::fake(function ($q) use ($rates) {
            $this->assertSame('GET', $q->method());
            if (str_ends_with($q->url(), '/shipping-rates')) {
                return Http::response(['shippingRates' => $rates]);
            }
            if (str_contains($q->url(), '/product-offers/')) {
                return Http::response(['id' => 'offer', 'publication' => ['status' => 'ACTIVE'], 'delivery' => ['shippingRates' => ['id' => $rates[0]['id']]]]);
            }

            return Http::response([]);
        });
    }

    public function test_sync_supports_entire_provider_list_without_business_writes(): void
    {
        $account = $this->account();
        $rates = [];
        for ($i = 1; $i <= 18; $i++) {
            $rates[] = ['id' => 'rate-'.$i, 'name' => 'Provider name '.$i];
        }
        $this->fake($rates);
        $before = [];
        foreach (['parts', 'marketplace_listings', 'marketplace_accounts', 'marketplace_sync_logs'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $r = app(AllegroShippingCatalog::class)->synchronize();
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['database_writes']);
        $this->assertSame(18, $r['rates_count']);
        $this->assertCount(18, app(AllegroShippingCatalog::class)->options());
        $this->assertSame('Provider name 18', app(AllegroShippingCatalog::class)->options()['Provider name 18']);
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->get()->toJson(), $table);
        }
        foreach (['allegro_writes', 'product_writes', 'marketplace_writes', 'oauth_refresh'] as $flag) {
            $this->assertFalse($r[$flag]);
        }
        $this->assertDatabaseHas('allegro_shipping_rates', ['marketplace_account_id' => $account, 'shipping_rate_id' => 'rate-18', 'active' => true]);
        Http::assertSentCount(1);
    }

    public function test_resync_renames_deactivates_and_preserves_historic_part_value(): void
    {
        $this->account();
        $catalog = app(AllegroShippingCatalog::class);
        $this->fake([['id' => 'a', 'name' => 'LISTY'], ['id' => 'b', 'name' => 'Removed']]);
        $catalog->synchronize();
        $old = DB::table('allegro_shipping_rates')->where('shipping_rate_id', 'b')->first()->last_seen_at;
        Http::swap(new Factory);
        $this->travel(1)->minute();
        $this->fake([['id' => 'a', 'name' => 'New name']]);
        $catalog->synchronize();
        $this->assertDatabaseHas('allegro_shipping_rates', ['shipping_rate_id' => 'a', 'shipping_rate_name' => 'New name', 'active' => true]);
        $this->assertDatabaseHas('allegro_shipping_rates', ['shipping_rate_id' => 'b', 'active' => false, 'last_seen_at' => $old]);
        $this->assertSame(['New name' => 'New name'], $catalog->options());
        $this->assertArrayHasKey('LISTY', $catalog->options('LISTY'));
        $this->assertArrayNotHasKey('Removed', $catalog->options());
    }

    public function test_empty_success_marks_snapshot_and_deactivates_every_rate(): void
    {
        $id = $this->account();
        $this->fake([['id' => 'old', 'name' => 'LISTY']]);
        $c = app(AllegroShippingCatalog::class);
        $c->synchronize();
        Http::swap(new Factory);
        $this->fake([]);
        $r = $c->synchronize();
        $this->assertDatabaseHas('allegro_shipping_rates', ['shipping_rate_id' => 'old', 'active' => false]);
        $this->assertTrue($r['ok']);
        $this->assertTrue($c->synced($id));
        $this->assertSame([], $c->options());
        $this->assertArrayHasKey('LISTY', $c->options('LISTY'));
    }

    public function test_failed_or_malformed_sync_never_changes_last_good_catalog(): void
    {
        $this->account();
        $this->fake([['id' => 'a', 'name' => 'LISTY']]);
        $c = app(AllegroShippingCatalog::class);
        $c->synchronize();
        $before = DB::table('allegro_shipping_rates')->get()->toJson();
        $state = DB::table('allegro_shipping_catalog_syncs')->get()->toJson();
        foreach ([Http::response([], 403), Http::response(['shippingRates' => [['id' => 'a', 'name' => 'x'], ['id' => 'a', 'name' => 'y']]]), Http::response(['wrong' => 'body'])] as $response) {
            Http::swap(new Factory);
            Http::fake(fn () => $response);
            $r = $c->synchronize();
            $this->assertFalse($r['ok']);
            $this->assertFalse($r['database_writes']);
            $this->assertSame($before, DB::table('allegro_shipping_rates')->get()->toJson());
            $this->assertSame($state, DB::table('allegro_shipping_catalog_syncs')->get()->toJson());
        }
    }

    public function test_dry_run_maps_new_provider_name_only_after_catalog_sync(): void
    {
        $a = $this->account();
        $p = DB::table('parts')->insertGetId(['name' => 'Part']);
        DB::table('marketplace_listings')->insert(['marketplace' => 'allegro', 'marketplace_account_id' => $a, 'part_id' => $p, 'external_offer_id' => 'offer']);
        $this->fake([['id' => 'new-rate', 'name' => 'GABARYTY CZ SK HU']]);
        $audit = app(ShippingRateAuditService::class);
        $r = $audit->run();
        $this->assertSame('missing_mapping', $r['rows'][0]['reason_code']);
        $this->assertSame('local_catalog_not_synchronized', $r['rows'][0]['mapping_detail']);
        app(AllegroShippingCatalog::class)->synchronize();
        $r = $audit->run();
        $this->assertSame('SET_EMPTY_FIELD', $r['rows'][0]['action']);
        $this->assertSame('exact_mapping', $r['rows'][0]['reason_code']);
        $this->assertSame('GABARYTY CZ SK HU', $r['rows'][0]['proposed_allegro_shipping_rate_name']);
        $this->assertNull(DB::table('parts')->where('id', $p)->value('allegro_shipping_rate_name'));
        $this->assertSame('GABARYTY CZ SK HU', $r['local_options'][0]);
        foreach (ShippingRateAuditService::WRITES as $flag => $value) {
            $this->assertFalse($r[$flag]);
        }
    }

    public function test_provider_rename_without_resync_blocks_dry_run(): void
    {
        $a = $this->account();
        $p = DB::table('parts')->insertGetId(['name' => 'Part']);
        DB::table('marketplace_listings')->insert(['marketplace' => 'allegro', 'marketplace_account_id' => $a, 'part_id' => $p, 'external_offer_id' => 'offer']);
        $this->fake([['id' => 'rate', 'name' => 'Old']]);
        app(AllegroShippingCatalog::class)->synchronize();
        Http::swap(new Factory);
        $this->fake([['id' => 'rate', 'name' => 'New']]);
        $r = app(ShippingRateAuditService::class)->run();
        $this->assertSame('missing_mapping', $r['rows'][0]['reason_code']);
        $this->assertSame('New', $r['rows'][0]['shipping_rate_name']);
        $this->assertSame('local_catalog_missing_or_stale', $r['rows'][0]['mapping_detail']);
    }

    public function test_legacy_seven_remain_available_before_first_sync_and_guard_blocks_business_writes(): void
    {
        $this->assertSame(AllegroSalesSettingsResolver::SHIPPING_RATE_OPTIONS, app(AllegroShippingCatalog::class)->options());
        $this->account();
        try {
            ReadOnlyDatabase::catalogWrites(fn () => DB::table('marketplace_accounts')->delete());
            $this->fail('Business writes allowed');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, DB::table('marketplace_accounts')->count());
    }

    public function test_new_catalog_name_is_accepted_by_existing_sales_settings_resolver(): void
    {
        $id = $this->account();
        $this->fake([['id' => 'new-id', 'name' => 'One Fulfillment International']]);
        app(AllegroShippingCatalog::class)->synchronize();
        $account = MarketplaceAccount::findOrFail($id);
        $r = app(AllegroSalesSettingsResolver::class)->resolve($account, 'One Fulfillment International');
        $this->assertSame('new-id', $r['shippingRates']['id']);
        $this->assertSame([], $r['blockers']);
        $this->assertNotContains('allegro_shipping_rate_not_active:One Fulfillment International', $r['blockers']);
    }

    public function test_duplicate_active_names_block_name_based_publishing_but_not_id_based_audit(): void
    {
        $id = $this->account();
        $this->fake([['id' => 'a', 'name' => 'Same'], ['id' => 'b', 'name' => 'Same']]);
        app(AllegroShippingCatalog::class)->synchronize();
        Http::swap(new Factory);
        Http::fake();
        $r = app(AllegroSalesSettingsResolver::class)->resolve(MarketplaceAccount::findOrFail($id), 'Same');
        $this->assertContains('ambiguous_allegro_shipping_rate_name', $r['blockers']);
        Http::assertNothingSent();
    }

    public function test_sync_is_idempotent_and_does_not_touch_another_accounts_catalog(): void
    {
        $id = $this->account();
        $other = DB::table('marketplace_accounts')->insertGetId(['marketplace' => 'allegro', 'code' => 'other', 'name' => 'Other']);
        DB::table('allegro_shipping_rates')->insert(['marketplace_account_id' => $other, 'shipping_rate_id' => 'a', 'shipping_rate_name' => 'Other account rate', 'active' => true, 'last_seen_at' => now(), 'synced_at' => now()]);
        $this->fake([['id' => 'a', 'name' => 'LISTY']]);
        $c = app(AllegroShippingCatalog::class);
        $c->synchronize();
        $c->synchronize();
        $this->assertSame(1, DB::table('allegro_shipping_rates')->where('marketplace_account_id', $id)->count());
        $this->assertDatabaseHas('allegro_shipping_rates', ['marketplace_account_id' => $other, 'shipping_rate_id' => 'a', 'shipping_rate_name' => 'Other account rate', 'active' => true]);
    }

    public function test_sync_action_is_admin_only_csrf_protected_and_cannot_apply_products(): void
    {
        $this->account();
        $this->fake([['id' => 'new', 'name' => 'Dynamic name']]);
        $url = '/admin/tools/allegro/shipping-rate-backfill/sync-catalog';
        $this->postJson($url)->assertUnauthorized();
        $this->seed(RoleSeeder::class);
        $admin = User::create(['name' => 'Owner', 'email' => 'catalog@example.test', 'password' => 'password']);
        $admin->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->app['env'] = 'production';
        try {
            $this->postJson($url)->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame(0, DB::table('allegro_shipping_rates')->count());
        $r = $this->postJson($url)->assertOk()->assertJsonPath('ok', true)->assertJsonPath('catalog_writes', true)->assertJsonPath('product_writes', false)->assertJsonPath('allegro_writes', false);
        $this->assertStringNotContainsString('SECRET', $r->getContent());
        Http::assertSentCount(1);
    }

    public function test_historical_manual_shipping_ids_still_work_before_first_catalog_sync(): void
    {
        $id = $this->account();
        Http::fake(fn () => Http::response([], 403));
        $r = app(AllegroSalesSettingsResolver::class)->resolve(MarketplaceAccount::findOrFail($id), 'LISTY');
        $this->assertSame(AllegroSalesSettingsResolver::MANUAL_SHIPPING_RATE_IDS['LISTY'], $r['shippingRates']['id']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_older_started_sync_cannot_overwrite_a_newer_successful_snapshot(): void
    {
        $this->account();
        $catalog = app(AllegroShippingCatalog::class);
        $calls = 0;
        Http::fake(function () use (&$calls, $catalog) {
            if (++$calls === 1) {
                $this->travel(1)->second();
                $catalog->synchronize();

                return Http::response(['shippingRates' => [['id' => 'old', 'name' => 'Old response']]]);
            }

            return Http::response(['shippingRates' => [['id' => 'new', 'name' => 'New response']]]);
        });
        $r = $catalog->synchronize();
        $this->assertFalse($r['ok']);
        $this->assertSame('sync_superseded', $r['reason_code']);
        $this->assertSame(['New response' => 'New response'], $catalog->options());
        $this->assertDatabaseMissing('allegro_shipping_rates', ['shipping_rate_id' => 'old']);
    }
}
