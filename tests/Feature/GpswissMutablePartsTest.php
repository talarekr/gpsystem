<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Services\Marketplace\PartAvailabilityEventService;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\CompactSnapshotProgress;
use App\Services\MigrationExport\GpswissMigrationExport;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Production technical IDs/counters identify fixture scenarios, not a production DB copy. */
class GpswissMutablePartsTest extends TestCase
{
    use RefreshDatabase;

    private const RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba';

    private const ATTEMPT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c';

    private const BASE = '/admin/tools/gpswiss-migration-export/';

    private string $tmp;

    private string $dir;

    private string $file;

    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/parts-reconcile-'.Str::uuid();
        $this->dir = $this->tmp.'/'.self::RUN;
        $this->file = $this->dir.'/logical-snapshot/'.self::ATTEMPT.'/checkpoint.json';
        config(['migration_export.root' => $this->tmp, 'migration_export.browser_snapshot_rows' => 2]);
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Owner', 'email' => 'parts-reconcile@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->owner = $user->id;
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(GpswissMigrationExport::class)->prepareBrowser($user->id, self::RUN);
        File::deleteDirectory($this->dir.'/logical-snapshot');
        $root = $this->root();
        $root['snapshot_attempt_id'] = self::ATTEMPT;
        $root['state'] = $root['snapshot_state'] = 'snapshot_running';
        $root['phase'] = 'snapshot_export';
        $this->root($root);
        foreach ([50, 51] as $id) {
            DB::table('cars')->insert(['id' => $id, 'uuid' => (string) Str::uuid(), 'make' => 'Fixture', 'model' => 'Fixture']);
        }
        foreach (range(8922, 8926) as $id) {
            DB::table('parts')->insert(['id' => $id, 'sku' => '000-'.$id, 'name' => 'Fixture part '.$id, 'car_id' => 50, 'quantity' => 1, 'status' => 'ready', 'needs_listing' => true]);
        }
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function state(?array $state = null): array
    {
        if ($state !== null) {
            file_put_contents($this->file, json_encode($state));
        }

        return json_decode(file_get_contents($this->file), true);
    }

    private function root(?array $state = null): array
    {
        $path = $this->dir.'/state.json';
        if ($state !== null) {
            file_put_contents($path, json_encode($state));
        }

        return json_decode(file_get_contents($path), true);
    }

    private function engine(): BrowserMigrationSnapshot
    {
        return app(BrowserMigrationSnapshot::class);
    }

    private function tick(): array
    {
        return app(GpswissMigrationExport::class)->browserSnapshotTick($this->owner, self::RUN, self::ATTEMPT);
    }

    private function resume()
    {
        return $this->postJson(self::BASE.self::RUN, ['action' => 'resume', 'expected_snapshot_attempt' => self::ATTEMPT]);
    }

    private function ready(bool $legacy = true): array
    {
        $index = $this->engine()->begin($this->dir, self::ATTEMPT);
        while ($index['stage'] === 'boundaries') {
            $index = $this->engine()->tick($this->dir, self::ATTEMPT);
        }
        if ($legacy) {
            $state = $this->state();
            foreach (array_keys($state['datasets']['parts']) as $key) {
                if (str_starts_with($key, 'mutable_') || in_array($key, ['dataset_generation', 'dataset_boundary_id', 'dataset_cutoff_database_time', 'boundary_refreshed_at', 'previous_boundary_id', 'recovery_reason', 'consistency_mode', 'boundary_expected_total'])) {
                    unset($state['datasets']['parts'][$key]);
                }
            }
            unset($state['dataset_consistency_overrides']['parts']);
            $this->state($state);
        }
        for ($n = 0; $n < 500 && $index['stage'] !== 'verify'; $n++) {
            $index = $this->engine()->tick($this->dir, self::ATTEMPT);
            $this->assertFalse($index['failed'], $index['last_error'] ?? '');
        }
        $this->assertSame('verify', $index['stage']);

        return $index;
    }

    private function failedSnapshot(bool $productionCounters = false): array
    {
        $this->ready();
        DB::table('parts')->where('id', 8922)->update(['quantity' => 0, 'status' => 'sold', 'needs_listing' => false, 'updated_at' => '2099-01-01 00:00:00']);
        $failed = $this->engine()->tick($this->dir, self::ATTEMPT);
        $this->assertSame('snapshot_row_updated_after_cutoff', $failed['last_error']);
        $state = $this->state();
        if ($productionCounters) {
            // Counter-only projection for the exact 79957/79957 UI fixture; no fabricated exported source records.
            $sum = array_sum(array_column($state['datasets'], 'processed'));
            $state['datasets']['part_translations']['processed'] = $state['datasets']['part_translations']['total'] = 79957 - $sum;
        }
        $this->state($state);
        $root = $this->root();
        $root['state'] = $root['snapshot_state'] = $root['phase'] = 'snapshot_failed';
        $root['last_error'] = $state['last_error'];
        $this->root($root);
        app(CompactSnapshotProgress::class)->publish($this->dir, self::ATTEMPT, $state);
        @unlink($this->dir.'/operational-progress.json');

        return $state;
    }

    public function test_exact_79957_fixture_adopts_existing_shards_and_patches_only_dirty_part_shard(): void
    {
        $before = $this->failedSnapshot(true);
        $others = array_diff_key($before['datasets'], ['parts' => true]);
        $shards = $before['datasets']['parts']['shards'];
        $mutations = [];
        DB::listen(function ($e) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete)/i', $e->sql)) {
                $mutations[] = $e->sql;
            }
        });
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('processed', 79957)->assertJsonPath('total', 79957)
            ->assertJsonPath('cursor.id', 8926)->assertJsonPath('next_action', 'resume')->assertJsonPath('dataset_recovery_allowed', true);
        $this->resume()->assertOk()->assertJsonPath('state', 'snapshot_running');
        $intent = $this->state()['dataset_recovery'];
        $this->assertSame('adopt_complete_shards_then_reconcile', $intent['strategy']);
        $this->resume()->assertOk();
        $this->assertSame($intent, $this->state()['dataset_recovery']);
        $this->tick(); // Integrity preflight; no dataset re-export.
        $adopted = $this->state();
        $this->assertSame('verify', $adopted['stage']);
        $this->assertSame(5, $adopted['datasets']['parts']['processed']);
        $this->assertSame(['id' => 8926], $adopted['datasets']['parts']['cursor']);
        $this->assertSame($shards, $adopted['datasets']['parts']['shards']);
        $this->assertSame($others, array_diff_key($adopted['datasets'], ['parts' => true]));
        for ($n = 0; $n < 20 && $this->state()['dataset_names'][$this->state()['dataset_index']] === 'parts'; $n++) {
            $this->tick();
        }
        $after = $this->state();
        $parts = $after['datasets']['parts'];
        $this->assertSame(1, $parts['mutable_dirty_rows']);
        $this->assertSame(1, $parts['mutable_dirty_shards']);
        $this->assertSame(2, $parts['mutable_reused_shards']);
        $this->assertNotSame($shards[0]['path'], $parts['shards'][0]['path']);
        $this->assertSame($shards[1]['path'], $parts['shards'][1]['path']);
        $this->assertSame($shards[2]['path'], $parts['shards'][2]['path']);
        $this->assertSame($others, array_diff_key($after['datasets'], ['parts' => true]));
        foreach ($shards as $shard) {
            $this->assertSame($shard['sha256'], hash_file('sha256', $this->dir.'/'.$shard['path']));
        }
        $this->assertSame(self::ATTEMPT, $after['attempt_id']);
        $this->assertSame([], $mutations);
        Http::assertNothingSent();
    }

    public function test_real_sale_changes_part_and_listing_then_both_reconcile_and_pipeline_completes(): void
    {
        $account = MarketplaceAccount::create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Fixture', 'api_enabled' => true,
            'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'fixture-access-token']]);
        DB::table('marketplace_listings')->insert(['part_id' => 8922, 'marketplace_account_id' => $account->id, 'marketplace' => 'allegro', 'external_offer_id' => 'fixture-offer', 'quantity' => 1, 'status' => 'active']);
        $index = $this->ready();
        $listingBefore = $index['datasets']['marketplace_listings'];
        $this->travelTo(Carbon::parse($index['cutoff_database_time'])->addMinute());
        Http::fake(['https://allegro.test/sale/product-offers/fixture-offer' => Http::response(['publication' => ['status' => 'ENDED', 'endedBy' => 'USER'], 'stock' => ['available' => 0], 'sellingMode' => ['format' => 'BUY_NOW'], 'archived' => false], 200)]);
        $sale = app(PartAvailabilityEventService::class)->sold(['source_channel' => 'allegro', 'part_id' => 8922, 'offer_id' => 'fixture-offer', 'source_order_id' => 'fixture-sale', 'source_order_item_id' => 'fixture-line']);
        $this->assertTrue($sale['ok']);
        Http::assertSentCount(1);
        $failed = $this->engine()->tick($this->dir, self::ATTEMPT);
        $this->assertSame('snapshot_row_updated_after_cutoff', $failed['last_error']);
        $root = $this->root();
        $root['state'] = $root['snapshot_state'] = $root['phase'] = 'snapshot_failed';
        $root['last_error'] = $failed['last_error'];
        $this->root($root);
        $this->resume()->assertOk();
        $this->tick();
        $this->assertSame($listingBefore, $this->state()['datasets']['marketplace_listings']);
        $export = app(GpswissMigrationExport::class);
        $status = $export->status($this->owner, self::RUN);
        $mutations = [];
        DB::listen(function ($e) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete)/i', $e->sql)) {
                $mutations[] = $e->sql;
            }
        });
        for ($n = 0; $n < 1000 && $status['state'] !== 'completed' && $status['next_action'] !== 'blocked'; $n++) {
            $status = $status['next_action'] === 'snapshot_tick' ? $this->tick() : $export->tick($this->owner, self::RUN);
        }
        $this->assertSame('completed', $status['state'], $status['last_error'] ?? '');
        $manifest = json_decode(file_get_contents($this->dir.'/migration-manifest.json'), true);
        $this->assertSame(self::ATTEMPT, $manifest['snapshot_attempt_id']);
        $this->assertSame('adopt_complete_shards_then_reconcile', $manifest['snapshot']['dataset_boundary_history'][0]['strategy']);
        $this->assertSame('parts', $manifest['snapshot']['dataset_boundary_history'][0]['dataset']);
        $this->assertSame($listingBefore['dataset_boundary_id'], $manifest['snapshot']['datasets']['marketplace_listings']['dataset_boundary_id']);
        $this->assertArrayNotHasKey('status_counts', $manifest['snapshot']['datasets']['marketplace_listings']);
        $this->assertSame(1, $manifest['snapshot']['datasets']['parts']['sold_count']);
        $this->assertSame(4, $manifest['snapshot']['datasets']['parts']['to_list_count']);
        $parts = iterator_to_array($this->engine()->rows($this->dir, 'parts', self::ATTEMPT));
        $listing = iterator_to_array($this->engine()->rows($this->dir, 'marketplace_listings', self::ATTEMPT));
        $this->assertSame('sold', $parts[0]['status']);
        $this->assertSame('ended', $listing[0]['status']);
        $this->assertStringContainsString('8922', file_get_contents($this->dir.'/sold-part-ids.csv'));
        $this->assertStringNotContainsString('8922', file_get_contents($this->dir.'/parts-to-list-ids.csv'));
        $this->assertSame([], $mutations);
        Http::assertSentCount(1);
        $this->travelBack();
    }

    public function test_future_parts_reconcile_deletion_car_change_and_exclude_new_ids(): void
    {
        $index = $this->ready(false);
        DB::table('parts')->where('id', 8922)->update(['car_id' => 51]);
        DB::table('parts')->where('id', 8923)->delete();
        DB::table('parts')->insert(['id' => 99999, 'sku' => 'late', 'name' => 'Not in snapshot']);
        for ($n = 0; $n < 300 && ! $index['done'] && ! $index['failed']; $n++) {
            $index = $this->engine()->tick($this->dir, self::ATTEMPT);
        }
        $this->assertTrue($index['done'], $index['last_error'] ?? '');
        $parts = iterator_to_array($this->engine()->rows($this->dir, 'parts', self::ATTEMPT));
        $relations = iterator_to_array($this->engine()->rows($this->dir, 'part_car_relations', self::ATTEMPT));
        $this->assertNotContains('8923', array_column($parts, 'id'));
        $this->assertNotContains('99999', array_column($parts, 'id'));
        $this->assertNotContains('8923', array_column($relations, 'part_id'));
        $this->assertSame('51', collect($relations)->firstWhere('part_id', '8922')['car_id']);
        $this->assertContains('8923', $index['datasets']['parts']['shards'][0]['deleted_ids']);
        $this->assertSame('derived_only_from_canonical_reconciled_parts; no_live_query', $index['dataset_consistency_overrides']['part_car_relations']['values']);
    }

    public function test_complete_legacy_25_row_shard_is_reconciled_without_resplitting_all_parts(): void
    {
        foreach (range(8902, 8921) as $id) {
            DB::table('parts')->insert(['id' => $id, 'sku' => '000-'.$id, 'name' => 'Legacy fixture '.$id, 'car_id' => 50, 'status' => 'ready']);
        }
        $this->ready();
        $state = $this->state();
        foreach (['parts', 'part_car_relations'] as $name) {
            $definition = &$state['datasets'][$name];
            $rows = [];
            $csv = '';
            foreach ($definition['shards'] as $shard) {
                $rows = [...$rows, ...json_decode(file_get_contents($this->dir.'/'.$shard['path']), true)];
                $body = file_get_contents($this->dir.'/'.$shard['csv_path']);
                $csv .= $csv === '' ? $body : substr($body, strpos($body, "\n") + 1);
            }
            $path = 'logical-snapshot/'.self::ATTEMPT.'/datasets/'.$name.'/legacy25';
            $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            file_put_contents($this->dir.'/'.$path.'.json', $json);
            file_put_contents($this->dir.'/'.$path.'.csv', $csv);
            $definition['shards'] = [['path' => $path.'.json', 'csv_path' => $path.'.csv', 'rows' => 25, 'sha256' => hash('sha256', $json), 'csv_sha256' => hash('sha256', $csv), 'bytes' => strlen($json), 'csv_bytes' => strlen($csv), 'cursor_end' => ['id' => 8926]]];
            $definition['checksum_partial'] = hash('sha256', hash('sha256', $name).hash('sha256', $json));
        }
        unset($definition);
        $this->state($state);
        DB::table('parts')->where('id', 8922)->update(['updated_at' => '2099-01-01 00:00:00', 'quantity' => 0]);
        $failed = $this->engine()->tick($this->dir, self::ATTEMPT);
        $this->assertTrue($failed['failed']);
        $root = $this->root();
        $root['state'] = $root['snapshot_state'] = $root['phase'] = 'snapshot_failed';
        $root['last_error'] = $failed['last_error'];
        $this->root($root);
        $this->resume()->assertOk();
        $this->tick();
        $this->tick();
        $trace = json_decode(file_get_contents($this->dir.'/last-tick.json'), true);
        $this->assertSame(25, $trace['records_attempted']);
        $this->assertSame(25, $trace['records_limit']);
        $index = $this->engine()->index($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 200 && ! $index['done'] && ! $index['failed']; $n++) {
            $index = $this->engine()->tick($this->dir, self::ATTEMPT);
        }
        $this->assertTrue($index['done'], $index['last_error'] ?? '');
        $this->assertSame(1, $index['datasets']['parts']['mutable_dirty_rows']);
        $this->assertCount(1, $index['datasets']['parts']['shards']);
    }

    public function test_repeated_part_mutations_stop_at_three_rounds_without_resetting_export(): void
    {
        $index = $this->ready(false);
        $boundary = $index['datasets']['parts']['dataset_boundary_id'];
        for ($n = 0; $n < 100 && ! $index['failed'] && ! $index['done']; $n++) {
            DB::table('parts')->where('id', 8922)->update(['quantity' => $n + 10]);
            $index = $this->engine()->tick($this->dir, self::ATTEMPT);
        }
        $this->assertSame('snapshot_mutable_dataset_unstable', $index['last_error']);
        $this->assertSame(3, $index['datasets']['parts']['mutable_round']);
        $this->assertSame($boundary, $index['datasets']['parts']['dataset_boundary_id']);
        $this->assertSame(5, $index['datasets']['parts']['processed']);
    }

    public function test_checksum_schema_attempt_source_and_incomplete_export_never_trigger_reset(): void
    {
        $before = $this->failedSnapshot();
        $this->postJson(self::BASE.self::RUN, ['action' => 'resume', 'expected_snapshot_attempt' => (string) Str::uuid()])->assertConflict();
        $root = $this->root();
        $changedRoot = $root;
        $changedRoot['snapshot_source_fingerprint'] = 'wrong-source';
        $this->root($changedRoot);
        $this->resume()->assertConflict()->assertJsonPath('reason_code', 'snapshot_source_context_mismatch');
        $this->root($root);
        $state = $before;
        $state['datasets']['parts']['schema_sha256'] = 'wrong';
        $this->state($state);
        $this->resume()->assertConflict()->assertJsonPath('reason_code', 'snapshot_schema_changed');
        $state = $before;
        $state['datasets']['parts']['processed']--;
        $this->state($state);
        $this->resume()->assertConflict()->assertJsonPath('reason_code', 'parts_reconciliation_requires_complete_export');
        $this->state($before);
        file_put_contents($this->dir.'/'.$before['datasets']['parts']['shards'][0]['path'], 'corrupt');
        $this->resume()->assertOk();
        $failed = $this->tick();
        $this->assertSame('snapshot_shard_checksum_mismatch', $failed['last_error']);
        $this->assertSame($before['datasets']['parts']['shards'], $this->state()['datasets']['parts']['shards']);
        $this->resume()->assertConflict()->assertJsonPath('reason_code', 'dataset_recovery_not_allowed');
    }

    public function test_parts_diagnostic_reports_recorded_evidence_and_legacy_verify_page_without_claiming_cause(): void
    {
        $state = $this->failedSnapshot();
        $this->getJson(self::BASE.self::RUN.'?format=dataset-conflict')->assertOk()->assertJsonPath('conflict.dataset', 'parts')->assertJsonPath('conflict.record_id', '8922');
        unset($state['source_conflict']);
        $this->state($state);
        $this->getJson(self::BASE.self::RUN.'?format=dataset-conflict')->assertOk()->assertJsonPath('record.id', 8922)
            ->assertJsonPath('evidence', 'current_read_of_legacy_failure_page_not_historical_proof')->assertJsonPath('changed_business_fields', null);
    }
}
