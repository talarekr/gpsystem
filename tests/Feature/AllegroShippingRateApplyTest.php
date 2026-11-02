<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Part;
use App\Models\User;
use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateApply;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateAuditService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AllegroShippingRateApplyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): ShippingRateApply
    {
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('Provider calls forbidden during APPLY'));
        Bus::fake();
        DB::table('marketplace_accounts')->insert(['id' => 2, 'marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'MaxHendler', 'status' => 'active', 'api_enabled' => true]);
        DB::table('allegro_shipping_catalog_syncs')->insert(['marketplace_account_id' => 2, 'synced_at' => now()]);
        DB::table('allegro_shipping_rates')->insert(['marketplace_account_id' => 2, 'shipping_rate_id' => 'provider-rate', 'shipping_rate_name' => 'Paczkomat Inpost a b c', 'active' => true, 'last_seen_at' => now(), 'synced_at' => now()]);
        $summary = ['total_parts' => 7318, 'parts_with_one_active_offer' => 5146, 'parts_without_active_offer' => 2012, 'parts_with_multiple_active_offers' => 0,
            'empty_kurier_allegro' => 6614, 'already_correct' => 287, 'proposed_updates' => 4609, 'current_value_conflicts' => 250, 'missing_mapping' => 1, 'unknown_shipping_rate' => 0, 'provider_read_failed' => 159];
        DB::table('allegro_shipping_audit_runs')->insert(['id' => ShippingRateApply::RUN_ID, 'owner_id' => 1, 'state' => 'completed',
            'checkpoint' => json_encode(['scan_complete' => true, 'phase' => 'scan', 'processed_count' => 7318, 'total' => 7318, 'summary' => $summary])]);
        $parts = $results = [];
        for ($id = 1; $id <= 7318; $id++) {
            [$action, $reason] = match (true) {
                $id <= 4609 => ['SET_EMPTY_FIELD', 'exact_mapping'],
                $id <= 4896 => ['NO_CHANGE', 'already_correct'],
                $id <= 5146 => ['BLOCK_CONFLICT', 'current_value_conflict'],
                $id <= 7158 => ['NO_ACTIVE_OFFER', 'no_active_allegro_offer'],
                $id <= 7317 => ['BLOCK_PROVIDER_READ', 'provider_read_failed'],
                default => ['BLOCK_MISSING_MAPPING', 'missing_mapping'],
            };
            $current = $action === 'NO_CHANGE' ? 'Paczkomat Inpost a b c' : ($action === 'BLOCK_CONFLICT' ? 'Historic manual value' : null);
            $parts[] = ['id' => $id, 'name' => 'Part '.$id, 'allegro_shipping_rate_name' => $current, 'updated_at' => '2026-11-02 12:00:00'];
            $r = ['part_id' => $id, 'action' => $action, 'reason_code' => $reason, 'active_offer_count' => 1, 'active_offer_count_complete' => true,
                'marketplace_account_id' => 2, 'allegro_account_code' => 'allegro_main', 'provider_offer_status' => 'ACTIVE', 'allegro_offer_id' => 'offer-'.$id,
                'shipping_rate_id' => 'provider-rate', 'shipping_rate_name' => 'Paczkomat Inpost a b c', 'proposed_allegro_shipping_rate_name' => 'Paczkomat Inpost a b c',
                'current_allegro_shipping_rate_name' => $current, 'provider_read_unavailable' => false, 'catalog_diagnostics' => ['ok' => true, 'http_status' => 200],
                'offers' => [['allegro_offer_id' => 'offer-'.$id, 'marketplace_account_id' => 2, 'allegro_account_code' => 'allegro_main', 'provider_offer_status' => 'ACTIVE', 'read_ok' => true, 'http_status' => 200]]];
            $results[] = ['run_id' => ShippingRateApply::RUN_ID, 'part_id' => $id, 'read_failed' => $action === 'BLOCK_PROVIDER_READ', 'payload' => json_encode($r)];
        }
        foreach (array_chunk($parts, 100) as $chunk) {
            DB::table('parts')->insert($chunk);
        }
        foreach (array_chunk($results, 100) as $chunk) {
            DB::table('allegro_shipping_audit_results')->insert($chunk);
        }
        DB::table('marketplace_listings')->insert(['marketplace' => 'allegro', 'marketplace_account_id' => 2, 'part_id' => 1, 'external_offer_id' => 'offer-1']);

        return app(ShippingRateApply::class);
    }

    private function apply(ShippingRateApply $service, array $plan): array
    {
        return $service->apply(1, ShippingRateApply::RUN_ID, $plan['plan_id'], $plan['plan_digest'], ShippingRateApply::CONFIRM);
    }

    public function test_only_blank_field_is_written_without_observers_provider_or_marketplace_and_replay_is_idempotent(): void
    {
        $s = $this->fixture();
        DB::table('parts')->where('id', 2)->update(['allegro_shipping_rate_name' => '']);
        DB::table('parts')->where('id', 3)->update(['allegro_shipping_rate_name' => " \t "]);
        $plan = $s->prepare(1, ShippingRateApply::RUN_ID);
        $this->assertSame(4609, $plan['eligible_count']);
        $this->assertSame(4609, $plan['expected_count']);
        $this->assertSame(287, $plan['summary']['already_correct']);
        $this->assertSame(250, $plan['summary']['current_value_conflicts']);
        $this->assertSame(2709, $plan['blocked']);
        // Manual changes both before and after preparation are never overwritten, even if equal to the proposal.
        DB::table('parts')->where('id', 5)->update(['allegro_shipping_rate_name' => 'MANUAL']);
        DB::table('parts')->where('id', 6)->update(['allegro_shipping_rate_name' => 'Paczkomat Inpost a b c']);
        $before = [];
        foreach (['marketplace_accounts', 'marketplace_listings', 'marketplace_sync_logs', 'allegro_shipping_audit_runs', 'allegro_shipping_audit_results', 'allegro_shipping_rates', 'allegro_shipping_catalog_syncs'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $otherParts = DB::table('parts')->where('id', '>', 4609)->get()->toJson();
        $attributes = DB::table('parts')->where('id', 1)->first();
        Part::saving(fn () => throw new LogicException('Part observer must never run'));
        $updates = [];
        DB::listen(function ($event) use (&$updates): void {
            if (preg_match('/^update "parts"/i', $event->sql)) {
                $updates[] = $event->sql;
            }
        });
        $result = $this->apply($s, $plan);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['database_writes']);
        $this->assertTrue($result['product_field_writes']);
        foreach (['allegro_writes', 'marketplace_writes', 'provider_calls'] as $flag) {
            $this->assertFalse($result[$flag]);
        }
        $this->assertSame(4607, $result['report']['updated']);
        $this->assertSame(2, $result['report']['skipped_concurrent_change']);
        $this->assertSame([5, 6], $result['report']['examples']['skipped_concurrent_change']);
        $this->assertCount(4607, $updates);
        foreach (array_unique($updates) as $sql) {
            $this->assertMatchesRegularExpression('/^update "parts" set "allegro_shipping_rate_name" = \\? where "id" = \\? and "allegro_shipping_rate_name" (?:is null|= \\?)$/', $sql);
        }
        foreach ([1, 2, 3] as $id) {
            $this->assertSame('Paczkomat Inpost a b c', DB::table('parts')->where('id', $id)->value('allegro_shipping_rate_name'));
        }
        $this->assertSame('MANUAL', DB::table('parts')->where('id', 5)->value('allegro_shipping_rate_name'));
        $after = DB::table('parts')->where('id', 1)->first();
        $attributes->allegro_shipping_rate_name = $after->allegro_shipping_rate_name;
        $this->assertEquals($attributes, $after); // including updated_at, prices, stock and every other column
        $this->assertSame($otherParts, DB::table('parts')->where('id', '>', 4609)->get()->toJson());
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->get()->toJson(), $table);
        }
        // A later manual change (including clearing) must survive any repeat request or Prepare.
        DB::table('parts')->where('id', 1)->update(['allegro_shipping_rate_name' => 'LATER MANUAL']);
        DB::table('parts')->where('id', 2)->update(['allegro_shipping_rate_name' => null]);
        $updates = [];
        $same = $s->prepare(1, ShippingRateApply::RUN_ID);
        $this->assertSame($plan['plan_id'], $same['plan_id']);
        $this->assertSame($plan['plan_digest'], $same['plan_digest']);
        $again = $this->apply($s, $same);
        $this->assertSame(0, $again['report']['updated']);
        $this->assertSame(4607, $again['report']['already_applied']);
        $this->assertSame(2, $again['report']['skipped_concurrent_change']);
        $this->assertSame([], $updates);
        $this->assertSame('LATER MANUAL', DB::table('parts')->where('id', 1)->value('allegro_shipping_rate_name'));
        $this->assertNull(DB::table('parts')->where('id', 2)->value('allegro_shipping_rate_name'));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_actual_dry_run_payload_is_accepted_without_repeating_provider_calls(): void
    {
        $s = $this->fixture();
        DB::table('marketplace_accounts')->where('id', 2)->update(['api_base_url' => 'https://api.allegro.pl',
            'api_credentials' => Crypt::encryptString(json_encode(['access_token' => 'FIXTURE_ONLY', 'access_token_expires_at' => now()->addHour()->toIso8601String()]))]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return str_ends_with($request->url(), '/shipping-rates')
                ? Http::response(['shippingRates' => [['id' => 'provider-rate', 'name' => 'Paczkomat Inpost a b c']]])
                : Http::response(['id' => 'offer-1', 'publication' => ['status' => 'ACTIVE'], 'delivery' => ['shippingRates' => ['id' => 'provider-rate']]]);
        });
        $report = app(ShippingRateAuditService::class)->run(0, 1);
        $this->assertSame('SET_EMPTY_FIELD', $report['rows'][0]['action']);
        DB::table('allegro_shipping_audit_results')->where('part_id', 1)->update(['payload' => json_encode($report['rows'][0])]);
        $requests = Http::recorded()->count();
        $p = $s->prepare(1, ShippingRateApply::RUN_ID);
        $this->assertSame(4609, $p['eligible_count']);
        $this->assertSame($requests, Http::recorded()->count());
    }

    public function test_bad_digest_confirmation_and_plan_tampering_cannot_write(): void
    {
        $s = $this->fixture();
        $p = $s->prepare(1, ShippingRateApply::RUN_ID);
        foreach ([['digest' => str_repeat('0', 64), 'confirm' => ShippingRateApply::CONFIRM, 'code' => 409], ['digest' => $p['plan_digest'], 'confirm' => '', 'code' => 422]] as $case) {
            try {
                $s->apply(1, ShippingRateApply::RUN_ID, $p['plan_id'], $case['digest'], $case['confirm']);
                $this->fail('Expected rejection');
            } catch (HttpException $e) {
                $this->assertSame($case['code'], $e->getStatusCode());
            }
        }
        DB::table('allegro_shipping_apply_plans')->where('id', $p['plan_id'])->update(['entries' => '[]']);
        try {
            $this->apply($s, $p);
            $this->fail('Expected signed plan rejection');
        } catch (HttpException $e) {
            $this->assertSame('plan_digest_mismatch', $e->getMessage());
        }
        $this->assertNull(DB::table('parts')->where('id', 1)->value('allegro_shipping_rate_name'));
        Http::assertNothingSent();
    }

    #[DataProvider('invalidEvidence')]
    public function test_unverified_rows_are_excluded_and_count_difference_blocks_apply(array $change): void
    {
        $s = $this->fixture();
        $record = DB::table('allegro_shipping_audit_results')->where('part_id', 1)->first();
        $r = array_replace_recursive(json_decode($record->payload, true), $change);
        DB::table('allegro_shipping_audit_results')->where('id', $record->id)->update(['payload' => json_encode($r)]);
        $p = $s->prepare(1, ShippingRateApply::RUN_ID);
        $this->assertSame(4608, $p['eligible_count']);
        $this->assertSame(-1, $p['count_difference']);
        $this->assertFalse($p['apply_allowed']);
        $result = $this->apply($s, $p);
        $this->assertFalse($result['ok']);
        $this->assertSame('eligible_count_mismatch', $result['reason_code']);
        $this->assertNull(DB::table('parts')->where('id', 2)->value('allegro_shipping_rate_name'));
        Http::assertNothingSent();
    }

    public static function invalidEvidence(): array
    {
        return [
            'wrong account' => [['marketplace_account_id' => 3]],
            'multiple active offers' => [['active_offer_count' => 2]],
            'provider unconfirmed' => [['offers' => [['read_ok' => false]]]],
            'catalog unconfirmed' => [['catalog_diagnostics' => ['http_status' => 403]]],
            'no shipping ID' => [['shipping_rate_id' => '']],
            'empty proposal' => [['proposed_allegro_shipping_rate_name' => '']],
        ];
    }

    public function test_source_changes_after_plan_block_with_count_difference_and_preserve_ledger(): void
    {
        $s = $this->fixture();
        $p = $s->prepare(1, ShippingRateApply::RUN_ID);
        DB::table('allegro_shipping_audit_results')->where('part_id', 1)->update(['read_failed' => true]);
        foreach ([$s->prepare(1, ShippingRateApply::RUN_ID), $this->apply($s, $p)] as $r) {
            $this->assertFalse($r['ok']);
            $this->assertSame('source_changed', $r['reason_code']);
            $this->assertSame(4608, $r['eligible_count']);
            $this->assertSame(-1, $r['count_difference']);
        }
        $this->assertSame(1, DB::table('allegro_shipping_apply_plans')->count());
        $this->assertNull(DB::table('parts')->where('id', 1)->value('allegro_shipping_rate_name'));
        Http::assertNothingSent();
    }

    public function test_inactive_catalog_entries_exclude_all_proposals_without_touching_parts(): void
    {
        $s = $this->fixture();
        DB::table('allegro_shipping_rates')->update(['active' => false]);
        $p = $s->prepare(1, ShippingRateApply::RUN_ID);
        $this->assertSame(0, $p['eligible_count']);
        $this->assertSame(-4609, $p['count_difference']);
        $this->assertFalse($p['apply_allowed']);
        $this->assertSame('eligible_count_mismatch', $this->apply($s, $p)['reason_code']);
        Http::assertNothingSent();
    }

    public function test_catalog_mutation_after_plan_blocks_even_if_count_is_unchanged(): void
    {
        $s = $this->fixture();
        $p = $s->prepare(1, ShippingRateApply::RUN_ID);
        DB::table('allegro_shipping_catalog_syncs')->update(['rates_count' => 18]);
        $r = $this->apply($s, $p);
        $this->assertSame('source_changed', $r['reason_code']);
        $this->assertSame(0, $r['count_difference']);
        $this->assertNull(DB::table('parts')->where('id', 1)->value('allegro_shipping_rate_name'));
    }

    public function test_transaction_failure_rolls_back_field_updates_and_ledger_without_exception_leak(): void
    {
        $s = $this->fixture();
        $p = $s->prepare(1, ShippingRateApply::RUN_ID);
        DB::connection()->beforeExecuting(function ($sql, $bindings): void {
            if (str_starts_with($sql, 'update "parts"') && ($bindings[1] ?? null) === 3) {
                throw new LogicException('SECRET SQL exception');
            }
        });
        $r = $this->apply($s, $p);
        $this->assertFalse($r['ok']);
        $this->assertSame(4609, $r['report']['failed']);
        $this->assertSame(range(1, 20), $r['report']['examples']['failed']);
        $this->assertSame(0, $r['report']['updated']);
        $this->assertNull(DB::table('parts')->where('id', 1)->value('allegro_shipping_rate_name'));
        $this->assertNull(DB::table('allegro_shipping_apply_plans')->value('report'));
        $this->assertStringNotContainsString('SECRET', json_encode($r));
        Http::assertNothingSent();
    }

    public function test_dry_run_guard_still_blocks_part_writes_and_apply_scope_blocks_other_columns(): void
    {
        $this->fixture();
        foreach ([fn () => ReadOnlyDatabase::run(fn () => DB::table('parts')->where('id', 1)->update(['allegro_shipping_rate_name' => 'bad'])),
            fn () => ReadOnlyDatabase::applyWrites(fn () => DB::table('parts')->where('id', 1)->update(['name' => 'bad'])),
            fn () => ReadOnlyDatabase::applyWrites(fn () => DB::table('parts')->where('id', 1)->update(['allegro_shipping_rate_name' => 'bad', 'name' => 'bad'])),
            fn () => ReadOnlyDatabase::applyWrites(fn () => DB::table('marketplace_listings')->update(['status' => 'bad']))] as $write) {
            try {
                $write();
                $this->fail('Guard should block');
            } catch (LogicException $e) {
                $this->assertStringContainsString('blocked', $e->getMessage());
            }
        }
    }

    public function test_owner_endpoint_requires_plan_digest_token_and_completed_allowed_run(): void
    {
        $s = $this->fixture();
        $this->seed(RoleSeeder::class);
        $user = User::create(['id' => 1, 'name' => 'Owner', 'email' => 'shipping-apply@example.test', 'password' => 'password']);
        DB::table('allegro_shipping_audit_runs')->update(['owner_id' => $user->id]);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $url = '/admin/tools/allegro/shipping-rate-backfill/apply';
        $p = $this->postJson($url, ['action' => 'prepare', 'run_id' => ShippingRateApply::RUN_ID])->assertOk()->assertJsonPath('eligible_count', 4609)->json();
        $this->postJson($url, ['action' => 'apply', 'run_id' => ShippingRateApply::RUN_ID])->assertUnprocessable();
        $this->postJson($url, ['action' => 'apply', 'run_id' => ShippingRateApply::RUN_ID, 'plan_id' => $p['plan_id'], 'plan_digest' => str_repeat('0', 64), 'confirm' => ShippingRateApply::CONFIRM])->assertStatus(409);
        $this->postJson($url, ['action' => 'prepare', 'run_id' => '00000000-0000-4000-8000-000000000000'])->assertUnprocessable();
        $body = ['action' => 'apply', 'run_id' => ShippingRateApply::RUN_ID, 'plan_id' => $p['plan_id'], 'plan_digest' => $p['plan_digest'], 'confirm' => ShippingRateApply::CONFIRM];
        $this->postJson($url, $body)->assertOk()->assertJsonPath('report.updated', 4609)->assertJsonPath('provider_calls', false)->assertJsonPath('marketplace_writes', false);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('report.updated', 0)->assertJsonPath('report.already_applied', 4609);
        DB::table('allegro_shipping_audit_runs')->update(['state' => 'running']);
        $this->postJson($url, ['action' => 'prepare', 'run_id' => ShippingRateApply::RUN_ID])->assertStatus(409);
        DB::table('allegro_shipping_audit_runs')->update(['state' => 'completed', 'owner_id' => 999]);
        $this->postJson($url, ['action' => 'prepare', 'run_id' => ShippingRateApply::RUN_ID])->assertNotFound();
        $user->removeRole(UserRole::OwnerAdmin->value);
        $this->postJson($url, ['action' => 'prepare', 'run_id' => ShippingRateApply::RUN_ID])->assertForbidden();
        Http::assertNothingSent();
    }
}
