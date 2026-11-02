<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Marketplace\ShippingRateAudit\AllegroShippingReadClient;
use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use App\Services\Marketplace\ShippingRateAudit\ShippingAuditRuns;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AllegroShippingAuditResumeTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $count = 3): array
    {
        $account = DB::table('marketplace_accounts')->insertGetId(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Maxhendler', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => Crypt::encryptString(json_encode(['access_token' => 'SECRET', 'access_token_expires_at' => now()->addHour()->toIso8601String()]))]);
        DB::table('allegro_shipping_catalog_syncs')->insert(['marketplace_account_id' => $account, 'synced_at' => now()]);
        DB::table('allegro_shipping_rates')->insert(['marketplace_account_id' => $account, 'shipping_rate_id' => 'rate', 'shipping_rate_name' => 'LISTY', 'active' => true, 'last_seen_at' => now(), 'synced_at' => now()]);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $part = DB::table('parts')->insertGetId(['name' => 'Audit', 'sku' => 'SKU-'.$i, 'allegro_shipping_rate_name' => null]);
            DB::table('marketplace_listings')->insert(['marketplace' => 'allegro', 'part_id' => $part, 'marketplace_account_id' => $account, 'external_offer_id' => 'offer-'.$part, 'status' => 'active']);
            $ids[] = $part;
        }

        return $ids;
    }

    private function fake(?\Closure $offer = null): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($offer) {
            $this->assertSame('GET', $request->method());
            if (str_ends_with($request->url(), '/shipping-rates')) {
                return Http::response(['shippingRates' => [['id' => 'rate', 'name' => 'LISTY']]]);
            }
            if ($offer && ($response = $offer($request))) {
                return $response;
            }

            return Http::response(['id' => basename($request->url()), 'publication' => ['status' => 'ACTIVE'], 'delivery' => ['shippingRates' => ['id' => 'rate']]]);
        });
    }

    private function drain(ShippingAuditRuns $service, string $id): array
    {
        for ($i = 0; $i < 20; $i++) {
            $r = $service->batch(1, $id);
            if ($r['run']['state'] !== 'running') {
                return $r;
            }
        }
        $this->fail('Run did not finish');
    }

    public function test_reload_and_lost_batch_response_resume_after_checkpoint_without_requerying_resolved(): void
    {
        $ids = $this->fixture();
        $this->fake();
        $service = app(ShippingAuditRuns::class);
        $id = $service->start(1)['run']['run_id'];
        $service->control(1, $id, 'resume');
        $service->batch(1, $id); // response intentionally lost after commit
        $service = app(ShippingAuditRuns::class); // fresh request/service after reload
        $checkpoint = $service->status(1)['run'];
        $this->assertSame($id, $checkpoint['run_id']);
        $this->assertSame($ids[0], $checkpoint['cursor']);
        $this->assertSame(1, $checkpoint['processed_count']);
        $r = $this->drain($service, $id);
        $this->assertSame(3, $r['run']['processed_count']);
        $this->assertSame(3, $r['run']['completed_count']);
        $this->assertTrue($r['run']['scan_complete']);
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/offer-'.$ids[0]))->count());
        $this->assertSame(3, DB::table('allegro_shipping_audit_results')->where('run_id', $id)->count());
    }

    public function test_pause_stop_and_resume_preserve_results_and_snapshot_boundary(): void
    {
        $ids = $this->fixture(2);
        $this->fake();
        $s = app(ShippingAuditRuns::class);
        $id = $s->start(1)['run']['run_id'];
        $s->control(1, $id, 'resume');
        $s->batch(1, $id);
        $s->control(1, $id, 'pause');
        $r = $s->batch(1, $id);
        $this->assertSame(1, $r['run']['processed_count']);
        $s->control(1, $id, 'stop');
        DB::table('parts')->insert(['name' => 'New after snapshot']);
        $s->control(1, $id, 'resume');
        $r = $this->drain($s, $id);
        $this->assertSame(2, $r['run']['processed_count']);
        $this->assertSame($ids[1], $r['run']['max_part_id']);
    }

    public function test_failed_only_retry_replaces_result_and_subtracts_previous_counters(): void
    {
        $ids = $this->fixture();
        $fail = true;
        $this->fake(function ($request) use (&$fail, $ids) {
            return $fail && str_ends_with($request->url(), '/offer-'.$ids[0]) ? Http::response([], 401) : null;
        });
        $s = app(ShippingAuditRuns::class);
        $id = $s->start(1)['run']['run_id'];
        $s->control(1, $id, 'resume');
        $r = $this->drain($s, $id);
        $this->assertSame(1, $r['run']['provider_read_failed']);
        $this->assertSame(2, $r['run']['resolved']);
        $this->assertSame(0, $r['run']['summary']['parts_without_active_offer']);
        $this->assertSame(2, $r['run']['summary']['proposed_updates']);
        $this->assertSame($ids[0], $r['run']['failed_ids'][0]);
        $fail = false;
        $s->control(1, $id, 'retry');
        $r = $this->drain($s, $id);
        $this->assertSame(0, $r['run']['provider_read_failed']);
        $this->assertSame(0, $r['run']['summary']['provider_read_failed']);
        $this->assertSame(3, $r['run']['resolved']);
        $this->assertSame(3, $r['run']['summary']['proposed_updates']);
        $this->assertSame(3, $r['run']['summary']['total_parts']);
        $this->assertSame(3, $r['run']['summary']['empty_kurier_allegro']);
        $this->assertSame(3, array_values($r['run']['breakdown'])[0]['products_count']);
        foreach (array_slice($ids, 1) as $part) {
            $this->assertSame(1, Http::recorded(fn ($q) => str_ends_with($q->url(), '/offer-'.$part))->count());
        }
    }

    public function test_technical_writes_never_change_business_tables_or_sync_logs(): void
    {
        $this->fixture(2);
        $this->fake();
        $before = [];
        foreach (['parts', 'marketplace_listings', 'marketplace_accounts', 'marketplace_sync_logs'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $s = app(ShippingAuditRuns::class);
        $r = $s->start(1);
        $this->assertTrue($r['database_writes']);
        $this->assertTrue($r['technical_report_writes']);
        $this->assertFalse($r['business_writes']);
        $id = $r['run']['run_id'];
        $s->control(1, $id, 'resume');
        $this->drain($s, $id);
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->get()->toJson(), $table);
        }
        foreach (['parts', 'marketplace_listings', 'marketplace_sync_logs'] as $table) {
            try {
                ReadOnlyDatabase::run(fn () => DB::table($table)->delete(), true);
                $this->fail('Business write was allowed');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['allegro_writes', 'product_writes', 'marketplace_writes'] as $flag) {
            $this->assertFalse($r[$flag]);
        }
    }

    public function test_unexpired_lease_blocks_second_tab_and_expired_lease_recovers(): void
    {
        $this->fixture(1);
        $this->fake();
        $s = app(ShippingAuditRuns::class);
        $id = $s->start(1)['run']['run_id'];
        $s->control(1, $id, 'resume');
        DB::table('allegro_shipping_audit_runs')->where('id', $id)->update(['lease_token' => 'old', 'lease_until' => now()->addMinutes(3)]);
        $r = $s->batch(1, $id);
        $this->assertTrue($r['run']['busy']);
        $this->assertSame(0, $r['run']['processed_count']);
        Http::assertNothingSent();
        $this->travel(4)->minutes();
        $r = $this->drain($s, $id);
        $this->assertSame(1, $r['run']['processed_count']);
    }

    public function test_long_retry_after_is_persisted_and_blocks_resume_until_due(): void
    {
        $this->fixture(1);
        $limited = true;
        $this->fake(fn ($q) => $limited ? Http::response([], 429, ['Retry-After' => '120']) : null);
        $s = app(ShippingAuditRuns::class);
        $id = $s->start(1)['run']['run_id'];
        $s->control(1, $id, 'resume');
        $r = $s->batch(1, $id);
        $this->assertSame(1, $r['run']['provider_read_failed']);
        $this->assertGreaterThan(0, $r['run']['wait_seconds']);
        $count = Http::recorded()->count();
        $s->batch(1, $id);
        $this->assertSame($count, Http::recorded()->count());
        $this->travel(121)->seconds();
        $this->drain($s, $id);
        $row = DB::table('allegro_shipping_audit_results')->where('run_id', $id)->first();
        $this->assertNotNull($row->retry_not_before);
    }

    public function test_short_retry_after_and_transient_errors_have_bounded_backoff(): void
    {
        $account = (object) ['api_enabled' => true, 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => Crypt::encryptString(json_encode(['access_token' => 'SECRET']))];
        $client = new class extends AllegroShippingReadClient
        {
            public array $waits = [];

            protected function wait(int $milliseconds): void
            {
                $this->waits[] = $milliseconds;
            }
        };
        Http::fakeSequence()->push([], 429, ['Retry-After' => '1'])->push([], 503)->push(['id' => 'x'], 200);
        $r = $client->get($account, '/sale/product-offers/x');
        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['attempts']);
        $this->assertSame([1000, 500], $client->waits);
    }

    public function test_network_retry_exhaustion_and_nonretryable_401_403_404(): void
    {
        $account = (object) ['api_enabled' => true, 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => Crypt::encryptString(json_encode(['access_token' => 'SECRET']))];
        $client = new class extends AllegroShippingReadClient
        {
            protected function wait(int $milliseconds): void {}
        };
        Http::fake(fn () => throw new ConnectionException('SECRET must not leak'));
        $r = $client->get($account, '/sale/product-offers/x');
        $this->assertSame(3, $r['attempts']);
        $this->assertSame('network_error', $r['reason']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
    }

    public function test_authorization_failures_and_404_are_not_automatically_retried(): void
    {
        $account = (object) ['api_enabled' => true, 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => Crypt::encryptString(json_encode(['access_token' => 'SECRET']))];
        Http::fakeSequence()->push([], 401)->push([], 403)->push([], 404);
        $client = app(AllegroShippingReadClient::class);
        foreach ([401, 403, 404] as $status) {
            $r = $client->get($account, '/sale/product-offers/x');
            $this->assertSame($status, $r['http_status']);
            $this->assertSame(1, $r['attempts']);
        }
        Http::assertSentCount(3);
    }

    public function test_http_date_retry_after_is_persisted_without_long_sleep(): void
    {
        $account = (object) ['api_enabled' => true, 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => Crypt::encryptString(json_encode(['access_token' => 'SECRET']))];
        Http::fake(fn () => Http::response([], 429, ['Retry-After' => now()->addSeconds(120)->toRfc7231String()]));
        $client = new class extends AllegroShippingReadClient
        {
            protected function wait(int $milliseconds): void
            {
                throw new LogicException('Should defer instead of sleeping');
            }
        };
        $r = $client->get($account, '/sale/product-offers/x');
        $this->assertSame(1, $r['attempts']);
        $this->assertGreaterThanOrEqual(119, $r['retry_after_seconds']);
        $this->assertNotNull($r['retry_not_before']);
    }

    public function test_run_routes_are_owned_and_exports_preserve_mapping_details(): void
    {
        $this->fixture(1);
        $this->fake();
        $this->seed(RoleSeeder::class);
        $admin = User::create(['name' => 'Owner', 'email' => 'resume@example.test', 'password' => 'password']);
        $admin->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $url = '/admin/tools/allegro/shipping-rate-backfill/runs';
        $id = $this->postJson($url, ['action' => 'start'])->assertOk()->assertJsonPath('technical_report_writes', true)->json('run.run_id');
        $this->postJson($url.'/'.$id, ['action' => 'resume'])->assertOk();
        $this->postJson($url.'/'.$id, ['action' => 'batch'])->assertOk()->assertJsonPath('run.processed_count', 1);
        $this->postJson($url.'/'.$id, ['action' => 'batch'])->assertOk()->assertJsonPath('run.state', 'completed');
        $this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('database_writes', false)->assertJsonPath('run.processed_count', 1);
        $csv = $this->get($url.'/'.$id.'?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('shipping_rate_id', $csv);
        $this->assertStringContainsString('LISTY', $csv);
        $json = json_decode($this->get($url.'/'.$id.'?format=json')->assertOk()->streamedContent(), true);
        $this->assertSame(1, count($json['rows']));
        $this->assertSame('rate', $json['rows'][0]['shipping_rate_id']);
        $this->expectException(HttpException::class);
        app(ShippingAuditRuns::class)->status(9999, $id);
    }

    public function test_exhausted_batch_budget_is_recorded_as_safe_read_failure_without_http(): void
    {
        Http::fake();
        $client = app(AllegroShippingReadClient::class);
        $r = $client->withinBudget(0, fn () => $client->get(null, '/sale/product-offers/x'));
        $this->assertSame('network_error', $r['reason']);
        $this->assertSame('batch_read_budget_exhausted', $r['cause']);
        $this->assertFalse($r['request_attempted']);
        Http::assertNothingSent();
    }

    public function test_persistent_post_does_not_bypass_csrf(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->postJson('/admin/tools/allegro/shipping-rate-backfill/runs', ['action' => 'start'])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame(0, DB::table('allegro_shipping_audit_runs')->count());
    }
}
