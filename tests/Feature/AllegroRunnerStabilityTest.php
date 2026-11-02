<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Marketplace\ShippingRateAudit\AllegroShippingReadClient;
use App\Services\Marketplace\ShippingRateAudit\ShippingAuditRuns;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateAuditService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllegroRunnerStabilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $parts = 1, int $offers = 1): array
    {
        $a = DB::table('marketplace_accounts')->insertGetId(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Maxhendler', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => Crypt::encryptString(json_encode(['access_token' => 'TOKEN_SECRET']))]);
        DB::table('allegro_shipping_catalog_syncs')->insert(['marketplace_account_id' => $a, 'synced_at' => now()]);
        DB::table('allegro_shipping_rates')->insert(['marketplace_account_id' => $a, 'shipping_rate_id' => 'rate', 'shipping_rate_name' => 'LISTY', 'active' => true, 'last_seen_at' => now(), 'synced_at' => now()]);
        for ($start = 1; $start <= $parts; $start += 200) {
            $rows = [];
            for ($id = $start; $id <= min($parts, $start + 199); $id++) {
                $rows[] = ['id' => $id, 'name' => 'Part '.$id];
            }DB::table('parts')->insert($rows);
        }
        for ($i = 1; $i <= $offers; $i++) {
            DB::table('marketplace_listings')->insert(['marketplace' => 'allegro', 'marketplace_account_id' => $a, 'part_id' => 1, 'external_offer_id' => 'offer-'.$i]);
        }
        $s = app(ShippingAuditRuns::class);
        $id = $s->start(1)['run']['run_id'];
        $s->control(1, $id, 'resume');

        return [$s, $id, $a];
    }

    private function fake(?\Closure $offer = null): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($offer) {
            $this->assertSame('GET', $request->method());
            $this->assertLessThanOrEqual(3, $options['timeout']);
            if (str_ends_with($request->url(), '/shipping-rates')) {
                return Http::response(['shippingRates' => [['id' => 'rate', 'name' => 'LISTY']]]);
            }

            return $offer ? $offer($request) : $this->active(basename($request->url()));
        });
    }

    private function active(string $id): mixed
    {
        return Http::response(['id' => $id, 'publication' => ['status' => 'ACTIVE'], 'delivery' => ['shippingRates' => ['id' => 'rate']], 'buyer' => ['secret' => 'BODY_SECRET'], 'description' => 'BODY_SECRET']);
    }

    private function drain(ShippingAuditRuns $s, string $id): array
    {
        for ($i = 0; $i < 30; $i++) {
            $r = $s->batch(1, $id);
            if (($r['run']['wait_seconds'] ?? 0) > 0) {
                $this->travel($r['run']['wait_seconds'] + 1)->seconds();
            }if ($r['run']['state'] !== 'running') {
                return $r;
            }
        }
        $this->fail('Run did not complete');
    }

    public function test_old_run_707_of_7318_resumes_same_id_without_reset_or_new_run(): void
    {
        [$s,$old,$account] = $this->fixture(7318, 0);
        $id = 'e2229ef9-4db3-4c85-ab01-e7931e705e7b';
        $record = DB::table('allegro_shipping_audit_runs')->where('id', $old)->first();
        $c = json_decode($record->checkpoint, true);
        unset($c['provider_read_failed_by_reason'],$c['provider_offer_not_found_count']);
        $c['run_id'] = $id;
        $c['cursor'] = 707;
        $c['processed_count'] = 707;
        $c['completed_count'] = 548;
        $c['summary']['total_parts'] = 707;
        $c['summary']['parts_without_active_offer'] = 548;
        $c['summary']['provider_read_failed'] = 159;
        $c['summary']['empty_kurier_allegro'] = 707;
        DB::table('allegro_shipping_audit_runs')->where('id', $old)->update(['id' => $id, 'checkpoint' => json_encode($c)]);
        $rows = [];
        for ($p = 1; $p <= 707; $p++) {
            $failed = $p <= 159;
            $row = ['part_id' => $p, 'provider_read_unavailable' => $failed, 'active_offer_count' => 0, 'active_offer_count_complete' => ! $failed, 'action' => $failed ? 'BLOCK_PROVIDER_READ' : 'NO_ACTIVE_OFFER', 'reason_code' => $failed ? 'provider_read_failed' : 'no_active_allegro_offer', 'offers' => $failed ? [['allegro_offer_id' => 'old-'.$p, 'marketplace_account_id' => $account, 'http_status' => 404, 'read_ok' => false, 'reason_code' => 'endpoint_error']] : []];
            $rows[] = ['run_id' => $id, 'part_id' => $p, 'read_failed' => $failed, 'payload' => json_encode($row)];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('allegro_shipping_audit_results')->insert($chunk);
        }
        $this->fake();
        $r = $s->control(1, $id, 'resume');
        $this->assertSame(707, $r['run']['processed_count']);
        $this->assertSame(548, $r['run']['resolved']);
        $this->assertSame(159, $r['run']['provider_read_failed']);
        $this->assertSame(159, $r['run']['provider_read_failed_by_reason']['offer_404']);
        $r = $s->batch(1, $id);
        $this->assertSame($id, $r['run']['run_id']);
        $this->assertSame(708, $r['run']['cursor']);
        $this->assertSame(708, $r['run']['processed_count']);
        $this->assertSame(159, $r['run']['provider_read_failed']);
        $this->assertSame(1, DB::table('allegro_shipping_audit_runs')->count());
        $this->assertSame(708, DB::table('allegro_shipping_audit_results')->count());
    }

    public function test_idle_running_7173_of_7318_explicitly_requests_scan_and_finishes_same_sparse_id_run(): void
    {
        [$s, $old] = $this->fixture(7318, 0);
        $id = 'e2229ef9-4db3-4c85-ab01-e7931e705e7b';
        DB::table('parts')->where('id', '>', 7173)->delete();
        $remaining = array_values(array_diff(range(8691, 8840), [8695, 8700, 8710, 8720, 8730]));
        foreach ($remaining as $part) {
            DB::table('parts')->insert(['id' => $part, 'name' => 'Remaining '.$part]);
        }
        $c = json_decode(DB::table('allegro_shipping_audit_runs')->where('id', $old)->value('checkpoint'), true);
        $c = array_replace($c, ['run_id' => $id, 'cursor' => 8690, 'max_part_id' => 8840, 'processed_count' => 7173, 'completed_count' => 7014,
            'provider_read_failed_by_reason' => ['offer_404' => 159], 'provider_offer_not_found_count' => 816]);
        $c['summary']['total_parts'] = 7173;
        $c['summary']['parts_without_active_offer'] = 7014;
        $c['summary']['provider_read_failed'] = 159;
        $c['last_tick'] = ['reason' => 'product_completed', 'duration_ms' => 5, 'requested_items' => 1, 'completed_items' => 1, 'provider_requests' => 0];
        DB::table('allegro_shipping_audit_runs')->where('id', $old)->update(['id' => $id, 'checkpoint' => json_encode($c), 'lease_token' => null, 'lease_until' => null]);
        $rows = [];
        for ($p = 1; $p <= 7173; $p++) {
            $rows[] = ['run_id' => $id, 'part_id' => $p, 'read_failed' => $p <= 159,
                'payload' => json_encode(['part_id' => $p, 'reason_code' => $p <= 159 ? 'provider_read_failed' : 'no_active_allegro_offer', 'offers' => []])];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('allegro_shipping_audit_results')->insert($chunk);
        }
        $original = DB::table('allegro_shipping_audit_results')->orderBy('part_id')->get()->toJson();
        $before = [];
        foreach (['parts', 'marketplace_listings', 'marketplace_accounts', 'marketplace_sync_logs', 'allegro_shipping_rates', 'allegro_shipping_catalog_syncs'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->fake();
        $r = $s->control(1, $id, 'resume');
        $this->assertTrue($r['resume_allowed']);
        $this->assertSame('scan_tick', $r['next_action']);
        $this->assertSame(145, $r['remaining']);
        $this->assertSame(8690, $r['cursor']);
        $this->assertSame('running', $r['state']);
        $this->assertSame('resumed', $r['run']['resume_reason']);
        $this->assertFalse($r['run']['busy']);
        $this->assertFalse($r['run']['lease_token_present']);
        $this->assertSame(0, $r['run']['lease_expires_in']);
        $this->assertNull($r['run']['blocked_reason']);
        foreach ($remaining as $index => $part) {
            $r = $s->batch(1, $id);
            $this->assertSame(7174 + $index, $r['run']['processed_count']);
            $this->assertSame($part, $r['cursor']);
            $this->assertSame($id, $r['run']['run_id']);
            $this->assertSame(159, $r['run']['provider_read_failed']);
            $this->assertSame(159, $r['run']['provider_read_failed_by_reason']['offer_404']);
        }
        $r = $s->batch(1, $id); // finalize after the last sparse ID
        $this->assertSame(7318, $r['run']['processed_count']);
        $this->assertSame(0, $r['remaining']);
        $this->assertSame('completed', $r['state']);
        $this->assertSame('none', $r['next_action']);
        $this->assertFalse($r['resume_allowed']);
        $this->assertSame(816, $r['run']['provider_offer_not_found_count']);
        $this->assertSame(1, DB::table('allegro_shipping_audit_runs')->count());
        $this->assertSame(7318, DB::table('allegro_shipping_audit_results')->count());
        $this->assertSame($original, DB::table('allegro_shipping_audit_results')->where('part_id', '<=', 7173)->orderBy('part_id')->get()->toJson());
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->get()->toJson(), $table.' unchanged');
        }
    }

    public function test_resume_contract_distinguishes_lease_cooldown_and_paused_status(): void
    {
        [$s, $id] = $this->fixture(1, 0);
        DB::table('allegro_shipping_audit_runs')->where('id', $id)->update(['lease_token' => 'worker', 'lease_until' => now()->addSeconds(10)]);
        $this->assertSame('poll_lease', $s->control(1, $id, 'resume')['next_action']);
        $this->travel(11)->seconds();
        $c = json_decode(DB::table('allegro_shipping_audit_runs')->where('id', $id)->value('checkpoint'), true);
        $c['wait_until'] = now()->addSeconds(20)->toDateTimeString();
        DB::table('allegro_shipping_audit_runs')->where('id', $id)->update(['checkpoint' => json_encode($c)]);
        $this->assertSame('poll_cooldown', $s->control(1, $id, 'resume')['next_action']);
        $this->travel(21)->seconds();
        $this->assertSame('scan_tick', $s->status(1, $id)['next_action']);
        $r = $s->control(1, $id, 'pause');
        $this->assertTrue($r['resume_allowed']);
        $this->assertSame('resume', $r['next_action']);
        Http::assertNothingSent();
    }

    public function test_ticks_are_bounded_and_continue_partial_reads_without_duplicate_successful_gets(): void
    {
        [$s,$id] = $this->fixture(1, 4);
        $this->fake();
        $before = DB::table('marketplace_listings')->get()->toJson();
        $r = $s->batch(1, $id);
        $this->assertSame(0, $r['run']['processed_count']);
        $this->assertSame('read_continuation', $r['run']['last_tick']['reason']);
        $this->assertSame(2, $r['run']['last_tick']['provider_requests']);
        $this->assertArrayHasKey('pending_read', $r['run']);
        $r = $s->batch(1, $id);
        $this->assertSame(0, $r['run']['processed_count']);
        $this->assertLessThanOrEqual(2, $r['run']['last_tick']['provider_requests']);
        $r = $s->batch(1, $id);
        $this->assertSame(1, $r['run']['processed_count']);
        $this->assertSame('BLOCK_AMBIGUOUS_OFFER', $r['run']['preview'][0]['action']);
        $this->assertSame(5, $r['run']['tick_totals']['provider_requests']);
        foreach (range(1, 4) as $i) {
            $this->assertSame(1, Http::recorded(fn ($q) => str_ends_with($q->url(), '/offer-'.$i))->count());
        }
        $this->assertSame($before, DB::table('marketplace_listings')->get()->toJson());
        $this->assertNull(DB::table('parts')->value('allegro_shipping_rate_name'));
        $this->assertStringNotContainsString('SECRET', DB::table('allegro_shipping_audit_runs')->value('checkpoint'));
        $this->assertStringNotContainsString('SECRET', DB::table('allegro_shipping_audit_results')->value('payload'));
    }

    #[DataProvider('transientStatuses')]
    public function test_transient_http_is_deferred_across_ticks_then_resolved(int $status): void
    {
        [$s,$id] = $this->fixture();
        $calls = 0;
        $this->fake(function ($q) use (&$calls, $status) {
            return ++$calls < 3 ? Http::response([], $status, $status === 429 ? ['Retry-After' => '12'] : []) : $this->active('offer-1');
        });
        $r = $s->batch(1, $id);
        $this->assertSame(0, $r['run']['processed_count']);
        $this->assertSame(0, $r['run']['provider_read_failed']);
        $this->assertGreaterThan(0, $r['run']['wait_seconds']);
        $requests = Http::recorded()->count();
        $s->batch(1, $id);
        $this->assertSame($requests, Http::recorded()->count());
        $this->travel($r['run']['wait_seconds'] + 1)->seconds();
        $r = $this->drain($s, $id);
        $this->assertSame(1, $r['run']['processed_count']);
        $this->assertSame(0, $r['run']['provider_read_failed']);
        $this->assertSame(2, $r['run']['tick_totals']['retry_count']);
        $this->assertSame(2, $r['run']['tick_totals']['http_status_breakdown'][(string) $status]);
    }

    public static function transientStatuses(): array
    {
        return [[429], [500], [502], [503], [504]];
    }

    #[DataProvider('transportErrors')]
    public function test_exhausted_transport_errors_have_precise_breakdown_without_message_leak(string $message, string $reason): void
    {
        [$s,$id] = $this->fixture();
        $this->fake(fn () => throw new ConnectionException($message.' SECRET'));
        $r = $this->drain($s, $id);
        $this->assertSame(1, $r['run']['provider_read_failed']);
        $this->assertSame(1, $r['run']['provider_read_failed_by_reason'][$reason]);
        $this->assertSame(3, $r['run']['tick_totals']['http_status_breakdown']['network']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
    }

    public static function transportErrors(): array
    {
        return [['cURL error 28: timed out', 'timeout'], ['connection reset', 'connection_error']];
    }

    public function test_offer_404_is_terminal_not_a_provider_failure_or_endless_retry(): void
    {
        [$s,$id] = $this->fixture();
        $this->fake(fn () => Http::response(['SECRET' => 'error'], 404));
        $r = $this->drain($s, $id);
        $this->assertSame(0, $r['run']['provider_read_failed']);
        $this->assertSame(1, $r['run']['provider_offer_not_found_count']);
        $this->assertSame('provider_offer_not_found', $r['run']['preview'][0]['reason_code']);
        $requests = Http::recorded()->count();
        $s->control(1, $id, 'retry');
        $this->drain($s, $id);
        $this->assertSame($requests, Http::recorded()->count());
    }

    public function test_live_lease_wait_and_expired_lease_recovery_preserve_cursor(): void
    {
        [$s,$id] = $this->fixture();
        $this->fake();
        DB::table('allegro_shipping_audit_runs')->where('id', $id)->update(['lease_token' => 'legacy-token', 'lease_until' => now()->addSeconds(18)]);
        $r = $s->control(1, $id, 'resume');
        $this->assertSame('waiting_for_live_lease', $r['run']['resume_reason']);
        $this->assertTrue($r['run']['busy']);
        $this->assertGreaterThan(0, $r['run']['lease_expires_in']);
        $s->batch(1, $id);
        Http::assertNothingSent();
        $this->travel(19)->seconds();
        $r = $s->control(1, $id, 'resume');
        $this->assertFalse($r['run']['busy']);
        $this->assertFalse($r['run']['lease_token_present']);
        $this->assertSame(0, $r['run']['cursor']);
        $r = $s->batch(1, $id);
        $this->assertSame(1, $r['run']['cursor']);
        $this->assertTrue($r['run']['last_tick']['metrics_complete']);
    }

    public function test_backend_exception_class_is_saved_without_raw_error_and_resume_can_continue(): void
    {
        [$s,$id] = $this->fixture();
        $this->fake();
        $fail = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$fail) {
            if ($fail && str_contains($sql, '"allegro_shipping_rate_name"') && str_contains($sql, 'from "parts"')) {
                $fail = false;
                throw new \DomainException('SECRET exception');
            }
        });
        $r = $s->batch(1, $id);
        $this->assertSame('paused', $r['run']['state']);
        $this->assertSame(\DomainException::class, $r['run']['last_backend_error']['class']);
        $this->assertSame('backend_exception', $r['run']['last_tick']['reason']);
        $this->assertSame(0, $r['run']['processed_count']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
        $s->control(1, $id, 'resume');
        $r = $s->batch(1, $id);
        $this->assertSame(1, $r['run']['processed_count']);
    }

    public function test_historical_404_results_are_not_retried_endlessly_and_resume_preserves_old_counters(): void
    {
        [$s,$id] = $this->fixture();
        $this->fake(fn () => Http::response([], 404));
        $this->drain($s, $id);
        $r = DB::table('allegro_shipping_audit_results')->where('run_id', $id)->first();
        $row = json_decode($r->payload, true);
        $row['provider_read_unavailable'] = true;
        $row['active_offer_count_complete'] = false;
        $row['action'] = 'BLOCK_PROVIDER_READ';
        $row['reason_code'] = 'provider_read_failed';
        $row['offers'][0]['read_ok'] = false;
        DB::table('allegro_shipping_audit_results')->where('id', $r->id)->update(['read_failed' => true, 'payload' => json_encode($row)]);
        $record = DB::table('allegro_shipping_audit_runs')->where('id', $id)->first();
        $c = json_decode($record->checkpoint, true);
        $c['completed_count'] = 0;
        $c['summary']['provider_read_failed'] = 1;
        $c['summary']['parts_without_active_offer'] = 0;
        unset($c['provider_read_failed_by_reason'],$c['provider_offer_not_found_count']);
        DB::table('allegro_shipping_audit_runs')->where('id', $id)->update(['checkpoint' => json_encode($c)]);
        $r = $s->control(1, $id, 'resume');
        $this->assertSame(1, $r['run']['provider_read_failed']);
        $count = Http::recorded()->count();
        $s->control(1, $id, 'retry');
        $r = $this->drain($s, $id);
        $this->assertSame(0, $r['run']['provider_read_failed']);
        $this->assertSame(1, $r['run']['processed_count']);
        $this->assertSame($count, Http::recorded()->count());
    }

    public function test_expired_worker_cannot_overwrite_recovered_tick_or_double_count_result(): void
    {
        [$s,$id] = $this->fixture();
        $interrupted = false;
        Http::fake(function ($request) use (&$interrupted, $id) {
            if (! $interrupted) {
                $interrupted = true;
                DB::table('allegro_shipping_audit_runs')->where('id', $id)->update(['lease_until' => now()->subSecond()]);
                $fresh = new ShippingAuditRuns(new ShippingRateAuditService(new AllegroShippingReadClient));
                $fresh->batch(1, $id);
            }

            return str_ends_with($request->url(), '/shipping-rates') ? Http::response(['shippingRates' => [['id' => 'rate', 'name' => 'LISTY']]]) : $this->active('offer-1');
        });
        $r = $s->batch(1, $id);
        $this->assertSame(1, $r['run']['processed_count']);
        $this->assertSame(1, $r['run']['summary']['proposed_updates']);
        $this->assertSame(1, DB::table('allegro_shipping_audit_results')->where('run_id', $id)->count());
        $this->assertSame('product_completed', $r['run']['last_tick']['reason']);
    }

    public function test_safe_client_event_and_response_size_diagnostics_do_not_mutate_business_data(): void
    {
        [$s,$id] = $this->fixture();
        $this->fake();
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Owner', 'email' => 'stability@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        DB::table('allegro_shipping_audit_runs')->where('id', $id)->update(['owner_id' => $user->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $url = '/admin/tools/allegro/shipping-rate-backfill/runs/'.$id;
        $response = $this->getJson($url)->assertOk();
        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('X-Audit-Response-Bytes'));
        $this->postJson($url, ['action' => 'client-event', 'event' => ['reason' => 'fetch_failed', 'stage' => 'batch', 'http_status' => null, 'duration_ms' => 2500, 'response_bytes' => 12345, 'online' => true, 'attempt' => 1]])->assertOk()->assertJsonPath('run.last_client_event.reason', 'fetch_failed')->assertJsonPath('run.cursor', 0);
        $this->postJson($url, ['action' => 'client-event', 'event' => ['reason' => 'fetch_failed', 'stage' => 'batch', 'message' => 'TOKEN_SECRET']])->assertUnprocessable();
        $this->assertStringNotContainsString('TOKEN_SECRET', DB::table('allegro_shipping_audit_runs')->value('checkpoint'));
        $this->assertNull(DB::table('parts')->value('allegro_shipping_rate_name'));
    }
}
