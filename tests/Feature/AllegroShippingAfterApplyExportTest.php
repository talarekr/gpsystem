<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateAfterApplyExport as Export;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AllegroShippingAfterApplyExportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): Export
    {
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('Export must not call provider'));
        Bus::fake();
        DB::table('allegro_shipping_audit_runs')->insert(['id' => Export::RUN_ID, 'owner_id' => 1, 'state' => 'completed',
            'checkpoint' => json_encode(['scan_complete' => true, 'processed_count' => 7318, 'total' => 7318])]);
        DB::table('allegro_shipping_apply_plans')->insert(['id' => Export::PLAN_ID, 'run_id' => Export::RUN_ID, 'owner_id' => 1,
            'source_digest' => str_repeat('a', 64), 'plan_digest' => str_repeat('b', 64), 'eligible_count' => 4609,
            'entries' => json_encode(array_map(fn ($id) => ['part_id' => $id, 'name' => 'Rate'], range(1, 4609))),
            'report' => json_encode(['planned' => 4609, 'updated' => 4609, 'failed' => 0, 'applied_ids' => range(1, 4609)]), 'created_at' => now()]);
        $parts = $results = [];
        for ($id = 1; $id <= 7318; $id++) {
            [$action, $reason, $current] = match (true) {
                $id <= 4609 => ['SET_EMPTY_FIELD', 'exact_mapping', 'Rate'],
                $id <= 4896 => ['NO_CHANGE', 'already_correct', 'Rate'],
                $id <= 5146 => ['BLOCK_CONFLICT', 'current_value_conflict', 'Manual'],
                $id <= 7158 => ['NO_ACTIVE_OFFER', 'no_active_allegro_offer', null],
                $id <= 7317 => ['BLOCK_PROVIDER_READ', 'provider_read_failed', null],
                default => ['BLOCK_MISSING_MAPPING', 'missing_mapping', null],
            };
            $parts[] = ['id' => $id, 'name' => "Żółty; produkt \"$id\"\nLinia 2", 'sku' => 'SKU-'.$id, 'part_number' => 'PN-'.$id, 'allegro_shipping_rate_name' => $current];
            $results[] = ['run_id' => Export::RUN_ID, 'part_id' => $id, 'read_failed' => $action === 'BLOCK_PROVIDER_READ',
                'payload' => json_encode(['part_id' => $id, 'action' => $action, 'reason_code' => $reason,
                    'allegro_offer_id' => 'offer-'.$id, 'provider_offer_status' => $id <= 5146 ? 'ACTIVE' : null,
                    'shipping_rate_id' => 'provider-rate', 'shipping_rate_name' => 'Rate',
                    'current_allegro_shipping_rate_name' => null, // deliberately stale frozen value
                    'offers' => $action === 'BLOCK_PROVIDER_READ' ? [['read_ok' => false, 'http_status' => 404, 'reason_code' => 'endpoint_error']] : []])];
        }
        foreach (array_chunk($parts, 100) as $chunk) {
            DB::table('parts')->insert($chunk);
        }
        foreach (array_chunk($results, 100) as $chunk) {
            DB::table('allegro_shipping_audit_results')->insert($chunk);
        }

        return app(Export::class);
    }

    public function test_current_fields_and_ledger_drive_full_summary_without_writes_or_calls(): void
    {
        $service = $this->fixture();
        DB::table('parts')->insert(['id' => 9000, 'name' => 'Outside scope']);
        $before = [];
        foreach (['parts', 'marketplace_listings', 'marketplace_sync_logs', 'allegro_shipping_audit_runs', 'allegro_shipping_audit_results', 'allegro_shipping_apply_plans'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        DB::connection()->beforeExecuting(function ($sql): void {
            if (! preg_match('/^select /i', $sql)) {
                throw new LogicException('Export attempted a mutation');
            }
        });
        $snapshot = $service->snapshot(1);
        $this->assertSame(['total' => 7318, 'filled_total' => 4896, 'not_filled_total' => 2422, 'filled_by_apply' => 4609,
            'already_filled' => 287, 'not_filled_no_active_offer' => 2012, 'not_filled_provider_404' => 159,
            'not_filled_conflict' => 250, 'not_filled_missing_mapping' => 1, 'not_filled_other' => 0], $snapshot['summary']);
        $this->assertCount(7318, $snapshot['rows']);
        $this->assertSame('Rate', $snapshot['rows'][0]['current_allegro_shipping_rate_name']);
        $this->assertSame('FILLED_BY_APPLY', $snapshot['rows'][0]['backfill_status']);
        $this->assertSame('Manual', $snapshot['rows'][4896]['current_allegro_shipping_rate_name']);
        $this->assertSame('Rate', $snapshot['rows'][4896]['shipping_rate_name_from_allegro']);
        $this->assertSame('NOT_FILLED_CONFLICT', $snapshot['rows'][4896]['backfill_status']);
        foreach (['database_writes', 'product_writes', 'marketplace_writes', 'allegro_writes', 'provider_calls'] as $flag) {
            $this->assertFalse($snapshot[$flag]);
        }
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->get()->toJson());
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_live_changes_and_deleted_parts_are_visible_and_nonblank_alone_never_proves_apply(): void
    {
        $s = $this->fixture();
        DB::table('parts')->where('id', 1)->delete();
        DB::table('parts')->where('id', 2)->update(['allegro_shipping_rate_name' => 'LATER MANUAL', 'name' => 'New title', 'sku' => 'CURRENT']);
        DB::table('parts')->where('id', 3)->update(['allegro_shipping_rate_name' => '']);
        DB::table('parts')->where('id', 4610)->update(['allegro_shipping_rate_name' => null]);
        DB::table('parts')->where('id', 7318)->update(['allegro_shipping_rate_name' => 'Manual, not applied']);
        $r = $s->snapshot(1);
        $this->assertCount(7318, $r['rows']);
        $this->assertSame('NOT_FILLED_OTHER', $r['rows'][0]['backfill_status']);
        $this->assertSame(1, $r['rows'][0]['applied_by_plan']);
        $this->assertSame(0, $r['rows'][0]['part_exists']);
        $this->assertSame('LATER MANUAL', $r['rows'][1]['current_allegro_shipping_rate_name']);
        $this->assertSame('CURRENT', $r['rows'][1]['sku']);
        $this->assertSame('New title', $r['rows'][1]['title']);
        $this->assertSame(0, $r['rows'][1]['current_value_matches_audit']);
        $this->assertSame('NOT_FILLED_OTHER', $r['rows'][2]['backfill_status']);
        $this->assertSame('NOT_FILLED_OTHER', $r['rows'][4609]['backfill_status']);
        $this->assertSame('NOT_FILLED_MISSING_MAPPING', $r['rows'][7317]['backfill_status']);
        $this->assertSame(0, $r['rows'][7317]['applied_by_plan']);
        $this->assertSame(4607, $r['summary']['filled_by_apply']);
        $this->assertSame(286, $r['summary']['already_filled']);
        $this->assertSame(3, $r['summary']['not_filled_other']);
        Http::assertNothingSent();
    }

    public function test_replayed_apply_report_uses_durable_ids_and_non404_failures_are_other(): void
    {
        $s = $this->fixture();
        DB::table('allegro_shipping_apply_plans')->update(['report' => json_encode(['updated' => 0, 'already_applied' => 4609, 'applied_ids' => range(1, 4609)])]);
        $record = DB::table('allegro_shipping_audit_results')->where('part_id', 7159)->first();
        $row = json_decode($record->payload, true);
        $row['offers'][0]['http_status'] = 401;
        DB::table('allegro_shipping_audit_results')->where('part_id', 7159)->update(['payload' => json_encode($row)]);
        $r = $s->snapshot(1);
        $this->assertSame(4609, $r['summary']['filled_by_apply']);
        $this->assertSame(158, $r['summary']['not_filled_provider_404']);
        $this->assertSame(1, $r['summary']['not_filled_other']);
        $this->assertSame('NOT_FILLED_OTHER', $r['rows'][7158]['backfill_status']);
        Http::assertNothingSent();
    }

    public function test_missing_ledger_or_incomplete_run_blocks_instead_of_guessing(): void
    {
        $s = $this->fixture();
        DB::table('allegro_shipping_apply_plans')->update(['report' => json_encode(['updated' => 4609])]);
        try {
            $s->snapshot(1);
            $this->fail('Missing durable applied IDs must reject');
        } catch (HttpException $e) {
            $this->assertSame('invalid_applied_ledger', $e->getMessage());
        }
        DB::table('allegro_shipping_audit_runs')->update(['state' => 'running']);
        $this->expectException(HttpException::class);
        $s->snapshot(1);
    }

    public function test_http_summary_and_three_csvs_are_complete_disjoint_and_read_only(): void
    {
        $this->fixture();
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Owner', 'email' => 'after-apply@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        DB::table('allegro_shipping_audit_runs')->update(['owner_id' => $user->id]);
        DB::table('allegro_shipping_apply_plans')->update(['owner_id' => $user->id]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $url = '/admin/tools/allegro/shipping-rate-backfill/after-apply';
        $this->getJson($url)->assertOk()->assertJsonPath('summary.filled_by_apply', 4609)->assertJsonMissingPath('rows')->assertJsonPath('provider_calls', false);
        DB::table('parts')->where('id', 1)->update(['sku' => '=HYPERLINK("unsafe")']);
        $sets = [];
        foreach (['all' => 7318, 'filled' => 4896, 'not-filled' => 2422] as $only => $count) {
            $response = $this->get($url.'?format=csv&only='.$only)->assertOk();
            $csv = $response->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, substr($csv, 3));
            rewind($stream);
            $this->assertSame(Export::FIELDS, fgetcsv($stream, null, ';', '"', ''));
            $rows = [];
            while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                $rows[] = array_combine(Export::FIELDS, $row);
            }
            fclose($stream);
            $this->assertCount($count, $rows);
            $sets[$only] = array_column($rows, 'part_id');
            if ($only !== 'not-filled') {
                $this->assertSame("'=HYPERLINK(\"unsafe\")", $rows[0]['sku']);
                $this->assertSame("Żółty; produkt \"1\"\nLinia 2", $rows[0]['title']);
                $this->assertSame('Rate', $rows[0]['current_allegro_shipping_rate_name']);
            }
        }
        $this->assertSame([], array_intersect($sets['filled'], $sets['not-filled']));
        $union = [...$sets['filled'], ...$sets['not-filled']];
        sort($union, SORT_NUMERIC);
        $this->assertSame($sets['all'], $union);
        $this->getJson($url.'?only=invalid')->assertUnprocessable();
        DB::table('allegro_shipping_audit_runs')->update(['owner_id' => 999]);
        $this->getJson($url)->assertNotFound();
        $user->removeRole(UserRole::OwnerAdmin->value);
        $this->getJson($url)->assertForbidden();
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }
}
