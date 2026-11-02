<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateAuditService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class AllegroShippingRateBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $code = 'allegro_main'): MarketplaceAccount
    {
        return MarketplaceAccount::create(['marketplace' => 'allegro', 'name' => $code, 'code' => $code,
            'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl',
            'api_credentials' => ['access_token' => 'secret-'.$code]]);
    }

    private function part(?string $current = null): int
    {
        return DB::table('parts')->insertGetId(['name' => 'Audit part', 'sku' => uniqid('sku-'), 'part_number' => 'PN', 'allegro_shipping_rate_name' => $current]);
    }

    private function listing(int $part, ?MarketplaceAccount $account, string $id, string $status = 'active'): void
    {
        DB::table('marketplace_listings')->insert(['part_id' => $part, 'marketplace_account_id' => $account?->id, 'marketplace' => 'allegro', 'external_offer_id' => $id, 'status' => $status, 'last_error' => 'keep', 'raw_payload' => '{"keep":true}']);
    }

    private function fake(array $offers, array $rates = [['id' => 'new-account-specific-uuid', 'name' => 'KURIER DPD']]): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($offers, $rates) {
            $this->assertSame('GET', $request->method());
            if (str_ends_with($request->url(), '/sale/shipping-rates')) {
                return Http::response(['shippingRates' => $rates]);
            }
            $id = rawurldecode(basename($request->url()));
            $body = $offers[$id] ?? null;

            return is_int($body) ? Http::response(['message' => 'secret must not leak'], $body) : Http::response(['id' => $id] + ($body ?? []));
        });
    }

    private function active(?string $rate = 'new-account-specific-uuid'): array
    {
        return ['publication' => ['status' => 'ACTIVE'], 'delivery' => ['shippingRates' => ['id' => $rate]]];
    }

    public function test_exact_current_names_not_historical_uuids_and_no_model_or_database_writes(): void
    {
        $account = $this->account();
        foreach ([null, '', '  ', 'KURIER DPD', 'LISTY'] as $i => $current) {
            $this->listing($this->part($current), $account, 'o'.$i, 'ended');
        }
        $before = [];
        foreach (['parts', 'marketplace_listings', 'marketplace_accounts', 'marketplace_sync_logs'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->fake(array_fill_keys(['o0', 'o1', 'o2', 'o3', 'o4'], $this->active()));
        $dispatcher = Part::getEventDispatcher();
        Part::setEventDispatcher(clone $dispatcher);
        Part::retrieved(fn () => throw new LogicException('Part retrieval observers must not run'));
        MarketplaceListing::retrieved(fn () => throw new LogicException('Listing observers must not run'));
        try {
            $report = app(ShippingRateAuditService::class)->run();
        } finally {
            Part::setEventDispatcher($dispatcher);
        }
        $this->assertSame(['SET_EMPTY_FIELD', 'SET_EMPTY_FIELD', 'SET_EMPTY_FIELD', 'NO_CHANGE', 'BLOCK_CONFLICT'], array_column($report['rows'], 'action'));
        $this->assertSame(3, $report['summary']['proposed_updates']);
        $this->assertSame(5, $report['summary']['parts_with_one_active_offer']);
        $this->assertSame(5, $report['breakdown'][0]['products_count']);
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->get()->toJson(), $table);
        }
        foreach (ShippingRateAuditService::WRITES as $flag => $value) {
            $this->assertFalse($report[$flag]);
        }
        Http::assertSentCount(6);
        $this->assertStringNotContainsString('secret-', json_encode($report));
    }

    public function test_mapping_blockers_and_no_offer_are_distinct(): void
    {
        $a = $this->account();
        $this->part();
        foreach (['missing' => null, 'unknown' => 'unknown', 'other' => 'other', 'ended' => 'new-account-specific-uuid'] as $id => $rate) {
            $this->listing($this->part(), $a, $id);
        }
        $this->fake(['missing' => $this->active(null), 'unknown' => $this->active('unknown'), 'other' => $this->active('other'), 'ended' => ['publication' => ['status' => 'ENDED']]], [['id' => 'other', 'name' => 'NEW PALETA']]);
        $r = app(ShippingRateAuditService::class)->run();
        $this->assertSame(['no_active_allegro_offer', 'missing_shipping_rate', 'unknown_shipping_rate', 'missing_local_option', 'no_active_allegro_offer'], array_column($r['rows'], 'reason_code'));
        $this->assertSame(2, $r['summary']['parts_without_active_offer']);
        $this->assertSame(3, $r['summary']['missing_mapping']);
        $this->assertNull($r['rows'][3]['proposed_allegro_shipping_rate_name']);
    }

    public function test_ambiguous_and_unavailable_accounts_never_propose_updates(): void
    {
        $a = $this->account();
        $b = $this->account('allegro_second');
        $p = $this->part();
        $this->listing($p, $a, 'a');
        $this->listing($p, $b, 'b');
        $q = $this->part();
        $this->listing($q, null, 'orphan');
        $this->fake(['a' => $this->active(), 'b' => $this->active()]);
        $r = app(ShippingRateAuditService::class)->run();
        $this->assertSame('BLOCK_AMBIGUOUS_OFFER', $r['rows'][0]['action']);
        $this->assertCount(2, $r['rows'][0]['offers']);
        $this->assertSame('account_unavailable', $r['rows'][1]['reason_code']);
        $this->assertSame(1, $r['summary']['parts_with_multiple_active_offers']);
        $this->assertSame(0, $r['summary']['proposed_updates']);
    }

    public function test_provider_failure_in_another_listing_blocks_single_successful_active_offer(): void
    {
        $a = $this->account();
        $p = $this->part();
        $this->listing($p, $a, 'active');
        $this->listing($p, $a, 'unauthorized', 'ended');
        $this->fake(['active' => $this->active(), 'unauthorized' => 401]);
        $r = app(ShippingRateAuditService::class)->run();
        $this->assertSame('BLOCK_PROVIDER_READ', $r['rows'][0]['action']);
        $this->assertTrue($r['rows'][0]['provider_read_unavailable']);
        $this->assertSame(0, $r['summary']['parts_without_active_offer']);
        $this->assertSame(0, $r['summary']['parts_with_one_active_offer']);
        Http::assertSentCount(3); // no refresh/retry POST
    }

    public function test_account_catalog_is_authoritative_and_isolated_by_token(): void
    {
        $a = $this->account();
        $b = $this->account('second');
        $this->listing($this->part(), $a, 'a');
        $this->listing($this->part(), $b, 'b');
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());
            if (str_ends_with($request->url(), '/shipping-rates')) {
                return Http::response(['shippingRates' => [['id' => 'same-id', 'name' => $request->hasHeader('Authorization', 'Bearer secret-second') ? 'LISTY' : 'KURIER DPD']]]);
            }

            return Http::response(['id' => basename($request->url())] + $this->active('same-id'));
        });
        $r = app(ShippingRateAuditService::class)->run();
        $this->assertSame(['KURIER DPD', 'LISTY'], array_column($r['rows'], 'proposed_allegro_shipping_rate_name'));
        $this->assertCount(2, $r['breakdown']);
    }

    public function test_catalog_read_failure_is_not_unknown_shipping_rate_and_names_are_exact(): void
    {
        $a = $this->account();
        $this->listing($this->part(), $a, 'a');
        Http::fake(['*/shipping-rates' => Http::response([], 401), '*/product-offers/a' => Http::response(['id' => 'a'] + $this->active())]);
        $r = app(ShippingRateAuditService::class)->run();
        $this->assertSame('BLOCK_PROVIDER_READ', $r['rows'][0]['action']);
        $this->assertSame(0, $r['summary']['unknown_shipping_rate']);
    }

    public function test_provider_name_must_match_local_option_exactly(): void
    {
        $a = $this->account();
        $this->listing($this->part(), $a, 'a');
        $this->fake(['a' => $this->active()], [['id' => 'new-account-specific-uuid', 'name' => ' KURIER DPD']]);
        $r = app(ShippingRateAuditService::class)->run();
        $this->assertSame('missing_local_option', $r['rows'][0]['reason_code']);
    }

    public function test_cursor_covers_every_part_once_including_archived_without_following_new_parts(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->part();
        }
        DB::table('parts')->update(['status' => 'archived']);
        Http::fake();
        $service = app(ShippingRateAuditService::class);
        $r = $service->run();
        $ids = array_column($r['rows'], 'part_id');
        $through = $r['through_id'];
        $new = $this->part();
        while (! $r['complete']) {
            $r = $service->run($r['next_after_id'], $through);
            $ids = [...$ids, ...array_column($r['rows'], 'part_id')];
        }
        $this->assertCount(12, array_unique($ids));
        $this->assertNotContains($new, $ids);
        Http::assertNothingSent();
    }

    public function test_sql_guard_blocks_mutations_before_execution_and_releases_its_scope(): void
    {
        $p = $this->part();
        try {
            ReadOnlyDatabase::run(fn () => DB::table('parts')->where('id', $p)->update(['allegro_shipping_rate_name' => 'LISTY']));
            $this->fail('Mutation was allowed');
        } catch (LogicException $e) {
            $this->assertStringContainsString('non-SELECT', $e->getMessage());
        }
        $this->assertNull(DB::table('parts')->where('id', $p)->value('allegro_shipping_rate_name'));
        DB::table('parts')->where('id', $p)->update(['name' => 'Outside diagnostic scope']);
    }

    public function test_panel_authorization_and_full_request_do_not_write_sessions_or_cache(): void
    {
        $url = '/admin/tools/allegro/shipping-rate-backfill';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::create(['name' => 'Customer', 'email' => 'customer@example.test', 'password' => 'password']));
        $this->getJson($url)->assertForbidden();
        $this->seed(RoleSeeder::class);
        $admin = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password']);
        $admin->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->part();
        Http::fake();
        config(['session.driver' => 'database', 'cache.default' => 'database']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->get($url)->assertOk()->assertSee('Globalny dry-run')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($url.'?mode=batch')->assertOk()->assertJsonPath('summary.total_parts', 1)->assertJsonPath('database_writes', false);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql);
        }
        $this->postJson($url)->assertStatus(405);
        $this->getJson($url.'?mode=apply')->assertUnprocessable();
    }

    public function test_shared_provider_offer_across_marketplace_aliases_is_blocked(): void
    {
        $a = $this->account();
        $p = $this->part();
        $q = $this->part();
        $this->listing($p, $a, 'shared');
        DB::table('marketplace_listings')->insert(['part_id' => $q, 'marketplace_account_id' => $a->id, 'marketplace' => $a->code, 'external_offer_id' => 'shared']);
        $this->fake(['shared' => $this->active()]);
        $r = app(ShippingRateAuditService::class)->run();
        $this->assertSame(['BLOCK_AMBIGUOUS_OFFER', 'BLOCK_AMBIGUOUS_OFFER'], array_column($r['rows'], 'action'));
        $this->assertSame(0, $r['summary']['proposed_updates']);
    }

    public function test_catalog_only_reads_no_offers_and_exposes_other_names_without_mapping_them(): void
    {
        $a = $this->account();
        $this->listing($this->part(), $a, 'a');
        $this->fake([], [['id' => 'other', 'name' => 'PALETA']]);
        $r = app(ShippingRateAuditService::class)->run(catalogOnly: true);
        $this->assertSame([], $r['rows']);
        $this->assertNull($r['catalog'][0]['shipping_rates'][0]['local_option']);
        Http::assertSentCount(1);
    }

    public function test_provider_failure_and_malformed_response_never_mean_no_active_offer(): void
    {
        $a = $this->account();
        foreach (['unauthorized', 'forbidden', 'limited', 'server', 'malformed'] as $id) {
            $this->listing($this->part(), $a, $id);
        }
        $this->fake(['unauthorized' => 401, 'forbidden' => 403, 'limited' => 429, 'server' => 500, 'malformed' => []]);
        $r = app(ShippingRateAuditService::class)->run();
        $this->assertSame(5, $r['summary']['provider_read_failed']);
        $this->assertSame(0, $r['summary']['parts_without_active_offer']);
        $this->assertSame(array_fill(0, 5, 'BLOCK_PROVIDER_READ'), array_column($r['rows'], 'action'));
        $this->assertStringNotContainsString('secret must not leak', json_encode($r));
        Http::assertSentCount(6);
    }

    public function test_guard_prevents_part_listing_and_sync_log_writes_before_sql_executes(): void
    {
        $a = $this->account();
        $p = $this->part();
        $this->listing($p, $a, 'a');
        foreach (['parts', 'marketplace_listings', 'marketplace_sync_logs'] as $table) {
            try {
                ReadOnlyDatabase::run(fn () => DB::table($table)->delete());
                $this->fail('Guard allowed delete from '.$table);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame(1, DB::table('marketplace_listings')->count());
    }

    public function test_new_connections_and_nested_scopes_cannot_bypass_guard(): void
    {
        config(['database.connections.audit_secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        try {
            ReadOnlyDatabase::run(fn () => ReadOnlyDatabase::run(fn () => DB::connection('audit_secondary')->statement('CREATE TABLE forbidden (id integer)')));
            $this->fail('New connection bypassed SQL guard');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::purge('audit_secondary');
        }
        // Guard scope must unwind fully even on exceptions.
        $this->part();
        $this->assertSame(1, DB::table('parts')->count());
    }
}
