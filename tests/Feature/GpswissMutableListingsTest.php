<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Services\Marketplace\AllegroListingStatusSyncService;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\CompactSnapshotProgress;
use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\MigrationExportException;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** All data is local fixture data; production IDs identify only the technical run/attempt. */
class GpswissMutableListingsTest extends TestCase
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
        $this->tmp = sys_get_temp_dir().'/mutable-'.Str::uuid();
        $this->dir = $this->tmp.'/'.self::RUN;
        $this->file = $this->dir.'/logical-snapshot/'.self::ATTEMPT.'/checkpoint.json';
        config(['migration_export.root' => $this->tmp, 'migration_export.browser_snapshot_rows' => 5]);
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Owner', 'email' => 'mutable@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->owner = $user->id;
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(GpswissMigrationExport::class)->prepareBrowser($user->id, self::RUN);
        File::deleteDirectory($this->dir.'/logical-snapshot');
        $state = json_decode(file_get_contents($this->dir.'/state.json'), true);
        $state['snapshot_attempt_id'] = self::ATTEMPT;
        $state['state'] = $state['snapshot_state'] = 'snapshot_running';
        $state['phase'] = 'snapshot_export';
        file_put_contents($this->dir.'/state.json', json_encode($state));
        foreach ([1, 2, 3] as $id) {
            DB::table('parts')->insert(['id' => $id, 'sku' => '000-MUTABLE-'.$id, 'name' => 'Fixture '.$id]);
        }
        for ($id = 22576; $id <= 22590; $id++) {
            DB::table('marketplace_listings')->insert(['id' => $id, 'part_id' => 1, 'marketplace' => 'ebay_de',
                'external_offer_id' => '000'.$id, 'status' => 'active', 'quantity' => 1, 'created_at' => '2020-01-01 00:00:00',
                'updated_at' => '2020-01-01 00:00:00', 'raw_payload' => json_encode(['access_token' => 'DO_NOT_EXPORT_SECRET', 'stock' => 1])]);
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

    private function root(array $fields): void
    {
        $file = $this->dir.'/state.json';
        file_put_contents($file, json_encode(array_replace(json_decode(file_get_contents($file), true), $fields)));
    }

    private function atListings(bool $legacy): array
    {
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->begin($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 300 && ! ($index['stage'] === 'export' && $index['current_dataset'] === 'marketplace_listings'); $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
            $this->assertFalse($index['failed'], $index['last_error'] ?? '');
        }
        if ($legacy) {
            $state = $this->state();
            foreach (array_keys($state['datasets']['marketplace_listings']) as $key) {
                if (str_starts_with($key, 'mutable_') || in_array($key, ['dataset_generation', 'dataset_boundary_id', 'dataset_cutoff_database_time', 'boundary_refreshed_at', 'previous_boundary_id', 'recovery_reason', 'consistency_mode', 'boundary_expected_total'])) {
                    unset($state['datasets']['marketplace_listings'][$key]);
                }
            }
            unset($state['dataset_consistency_overrides']);
            $this->state($state);
        }

        return $engine->index($this->dir, self::ATTEMPT);
    }

    private function legacyFailure(bool $productionCounters = false): array
    {
        $this->atListings(true);
        $engine = app(BrowserMigrationSnapshot::class);
        $engine->tick($this->dir, self::ATTEMPT);
        // Normal sale/sync fields, not exporter writes. Timestamp intentionally after saved cutoff.
        DB::table('marketplace_listings')->where('id', 22581)->update(['quantity' => 0, 'status' => 'ended', 'updated_at' => '2099-01-01 12:00:00', 'last_synced_at' => '2099-01-01 12:00:00']);
        $failed = $engine->tick($this->dir, self::ATTEMPT);
        $this->assertSame('snapshot_row_updated_after_cutoff', $failed['last_error']);
        $this->assertSame(['id' => 22580], $failed['datasets']['marketplace_listings']['cursor']);
        $state = $this->state();
        if ($productionCounters) {
            // Counter-only metadata reproduces the production UI; source rows/files remain small real fixtures.
            $sum = array_sum(array_column($state['datasets'], 'processed'));
            $state['datasets']['parts']['processed'] += 57363 - $sum;
            $state['datasets']['parts']['total'] = $state['datasets']['parts']['processed'];
            $total = array_sum(array_column($state['datasets'], 'total'));
            $state['datasets']['part_images']['total'] += 79957 - $total;
        }
        $this->state($state);
        $this->root(['state' => 'snapshot_failed', 'snapshot_state' => 'snapshot_failed', 'phase' => 'snapshot_failed', 'last_error' => $state['last_error']]);
        app(CompactSnapshotProgress::class)->publish($this->dir, self::ATTEMPT, $state);
        @unlink($this->dir.'/operational-progress.json');

        return $state;
    }

    private function tick(): array
    {
        return app(GpswissMigrationExport::class)->browserSnapshotTick($this->owner, self::RUN, self::ATTEMPT);
    }

    private function resume()
    {
        return $this->postJson(self::BASE.self::RUN, ['action' => 'resume', 'expected_snapshot_attempt' => self::ATTEMPT]);
    }

    public function test_exact_failed_run_resumes_only_listing_generation_and_preserves_previous_datasets_and_files(): void
    {
        $before = $this->legacyFailure(true);
        $previous = array_diff_key($before['datasets'], ['marketplace_listings' => true]);
        $oldShard = $before['datasets']['marketplace_listings']['shards'][0];
        $hashes = [];
        foreach ($before['datasets'] as $definition) {
            foreach ($definition['shards'] as $shard) {
                $hashes[$shard['path']] = hash_file('sha256', $this->dir.'/'.$shard['path']);
            }
        }
        $mutations = [];
        DB::listen(function ($e) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete)/i', $e->sql)) {
                $mutations[] = $e->sql;
            }
        });
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('processed', 57363)
            ->assertJsonPath('total', 79957)->assertJsonPath('next_action', 'resume')->assertJsonPath('dataset_recovery_allowed', true);
        $this->resume()->assertOk()->assertJsonPath('state', 'snapshot_running')->assertJsonPath('phase', 'snapshot_recovery_preflight');
        $intent = $this->state()['dataset_recovery']['dataset_boundary_id'];
        $this->resume()->assertOk(); // Lost resume reply / repeated click cannot allocate another generation.
        $this->assertSame($intent, $this->state()['dataset_recovery']['dataset_boundary_id']);
        $this->tick(); // Validate and detach the small legacy generation.
        $state = $this->state();
        $this->assertSame($previous, array_diff_key($state['datasets'], ['marketplace_listings' => true]));
        $definition = $state['datasets']['marketplace_listings'];
        $this->assertSame(1, $definition['dataset_generation']);
        $this->assertSame('legacy', $definition['previous_boundary_id']);
        $this->assertSame(0, $definition['processed']);
        $this->assertNull($definition['cursor']);
        $this->assertSame([], $definition['shards']);
        $this->assertSame($before['datasets']['marketplace_listings']['max_key'], $definition['max_key']);
        $this->assertSame(self::ATTEMPT, $state['attempt_id']);
        $this->tick();
        $next = $this->state()['datasets']['marketplace_listings'];
        $this->assertSame(5, $next['processed']);
        $this->assertNotSame($oldShard['path'], $next['shards'][0]['path']);
        $this->assertStringContainsString('/generation-1-'.$intent.'/', $next['shards'][0]['path']);
        $this->assertContains('migration_binding_policy', $next['csv_columns']);
        $this->assertContains('automatic_activation_allowed', $next['csv_columns']);
        foreach ($hashes as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', $this->dir.'/'.$path));
        }
        $this->assertSame([], $mutations);
        Http::assertNothingSent();
    }

    public function test_existing_operational_file_from_1516_enables_recovery_without_rebuilding_snapshot(): void
    {
        $this->legacyFailure();
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk();
        $path = $this->dir.'/operational-progress.json';
        $old = json_decode(file_get_contents($path), true);
        unset($old['dataset_recovery_allowed'], $old['recovery_reason_code']);
        $old['next_action'] = 'blocked';
        $old['resume_allowed'] = false;
        $old['blocked_reason'] = 'snapshot_row_updated_after_cutoff';
        file_put_contents($path, json_encode($old));
        $before = hash_file('sha256', $this->file);
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('next_action', 'resume')
            ->assertJsonPath('dataset_recovery_allowed', true)->assertJsonPath('snapshot_retry_allowed', false)
            ->assertJsonPath('recovery_reason_code', 'recoverable_dataset_boundary_conflict');
        $this->assertSame($before, hash_file('sha256', $this->file));
    }

    public function test_mutations_sales_sync_deletions_and_late_ids_reconcile_only_dirty_shards(): void
    {
        $this->atListings(false);
        $engine = app(BrowserMigrationSnapshot::class);
        $engine->tick($this->dir, self::ATTEMPT); // A captured.
        DB::table('marketplace_listings')->where('id', 22581)->update(['quantity' => 0, 'status' => 'ended', 'updated_at' => '2099-01-01 12:00:00']); // B before read.
        $engine->tick($this->dir, self::ATTEMPT);
        DB::table('marketplace_listings')->where('id', 22576)->update(['quantity' => 0, 'status' => 'ended', 'updated_at' => '2099-01-01 12:00:00']); // A after first read.
        DB::table('marketplace_listings')->where('id', 22586)->delete(); // Removed before first read.
        DB::table('marketplace_listings')->insert(['id' => 30000, 'part_id' => 1, 'marketplace' => 'ovoko', 'external_offer_id' => 'late', 'status' => 'active']);
        $index = $engine->index($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 300 && ! ($index['stage'] === 'verify' && $index['current_dataset'] === 'marketplace_listings'); $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
            $this->assertFalse($index['failed'], $index['last_error'] ?? '');
        }
        $original = $index['datasets']['marketplace_listings']['shards'];
        DB::table('marketplace_listings')->where('id', 22577)->delete(); // Deleted after capture -> tombstone.
        $engine->tick($this->dir, self::ATTEMPT); // Reconcile first dirty shard.
        DB::table('marketplace_listings')->where('id', 22576)->update(['quantity' => 2, 'status' => 'active', 'updated_at' => '2099-01-02 12:00:00']); // Repeated mutation.
        $reads = [];
        DB::listen(function ($e) use (&$reads) {
            if (str_contains($e->sql, 'snapshot_row_bytes') && str_contains($e->sql, 'marketplace_listings')) {
                $reads[] = $e->sql;
            }
        });
        for ($n = 0; $n < 100 && ! $index['done'] && ! $index['failed']; $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        $this->assertTrue($index['done'], $index['last_error'] ?? '');
        $definition = $index['datasets']['marketplace_listings'];
        $this->assertSame(3, $definition['mutable_round']);
        $this->assertContains('22577', $definition['shards'][0]['deleted_ids']);
        $this->assertSame($original[1]['path'], $definition['shards'][1]['path']);
        $rows = iterator_to_array($engine->rows($this->dir, 'marketplace_listings', self::ATTEMPT));
        $ids = array_column($rows, 'id');
        $this->assertNotContains('22577', $ids);
        $this->assertNotContains('22586', $ids);
        $this->assertNotContains('30000', $ids);
        $this->assertCount(13, array_unique($ids));
        $a = collect($rows)->firstWhere('id', '22576');
        $b = collect($rows)->firstWhere('id', '22581');
        $this->assertSame(2, (int) $a['quantity']);
        $this->assertSame('ended', $b['status']);
        $this->assertLessThanOrEqual(4, count($reads)); // Remaining two shards + only dirty shard twice, not full rescans.
        $this->assertStringNotContainsString('DO_NOT_EXPORT_SECRET', json_encode($rows));
    }

    public function test_real_status_sync_flow_mutates_listing_during_export_but_exporter_never_calls_provider(): void
    {
        $account = MarketplaceAccount::create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Fixture', 'status' => 'active',
            'api_enabled' => true, 'api_base_url' => 'https://api.allegro.test', 'api_credentials' => ['access_token' => 'fixture-only-access-token']]);
        DB::table('marketplace_listings')->where('id', 22576)->update(['marketplace_account_id' => $account->id, 'marketplace' => 'allegro']);
        $index = $this->atListings(false);
        $engine = app(BrowserMigrationSnapshot::class);
        $engine->tick($this->dir, self::ATTEMPT);
        $this->travelTo(Carbon::parse($index['cutoff_database_time'])->addMinute());
        Http::fake(['https://api.allegro.test/sale/product-offers/00022576' => Http::response(['publication' => ['status' => 'ENDED'], 'stock' => ['available' => 0]], 200)]);
        $result = app(AllegroListingStatusSyncService::class)->sync(['listing_id' => 22576,
            'confirm' => AllegroListingStatusSyncService::CONFIRM]);
        $this->assertTrue($result['writes']['database']);
        $this->assertFalse($result['writes']['allegro']);
        Http::assertSentCount(1); // Mocked GET is by the normal sync service only, before export resumes.
        $index = $engine->index($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 300 && ! $index['done'] && ! $index['failed']; $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        $this->assertTrue($index['done'], $index['last_error'] ?? '');
        $rows = iterator_to_array($engine->rows($this->dir, 'marketplace_listings', self::ATTEMPT));
        $this->assertSame('ended', collect($rows)->firstWhere('id', '22576')['status']);
        $this->assertStringNotContainsString('fixture-only-access-token', json_encode($rows));
        Http::assertSentCount(1); // Export performed no additional GET/POST/PATCH or refresh.
        $this->travelBack();
    }

    public function test_uncommitted_files_can_be_replayed_after_source_change_without_mixing_orphans(): void
    {
        $this->atListings(false);
        $before = file_get_contents($this->file);
        $engine = app(BrowserMigrationSnapshot::class);
        $committed = $engine->tick($this->dir, self::ATTEMPT);
        $orphan = $committed['datasets']['marketplace_listings']['shards'][0];
        file_put_contents($this->file, $before); // Simulate a crash after files, before checkpoint commit.
        DB::table('marketplace_listings')->where('id', 22576)->update(['quantity' => 0]);
        $retried = $engine->tick($this->dir, self::ATTEMPT);
        $this->assertFalse($retried['failed']);
        $this->assertCount(1, $retried['datasets']['marketplace_listings']['shards']);
        $current = $retried['datasets']['marketplace_listings']['shards'][0];
        $this->assertNotSame($orphan['path'], $current['path']);
        $this->assertSame($orphan['sha256'], hash_file('sha256', $this->dir.'/'.$orphan['path']));
        $this->assertSame(5, $retried['datasets']['marketplace_listings']['processed']);
    }

    public function test_recovery_from_verification_reexports_only_listings_then_returns_to_verification(): void
    {
        $this->atListings(true);
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->index($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 300 && ! ($index['stage'] === 'verify' && $index['current_dataset'] === 'marketplace_listings'); $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        DB::table('marketplace_listings')->where('id', 22576)->update(['quantity' => 0, 'updated_at' => '2099-01-01 12:00:00']);
        $failed = $engine->tick($this->dir, self::ATTEMPT);
        $this->assertSame('snapshot_row_updated_after_cutoff', $failed['last_error']);
        $before = array_diff_key($this->state()['datasets'], ['marketplace_listings' => true]);
        $engine->requestDatasetRecovery($this->dir, self::ATTEMPT);
        $index = $engine->index($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 100 && ! ($index['stage'] === 'verify' && $index['current_dataset'] === 'marketplace_listings'); $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
            $this->assertFalse($index['failed'], $index['last_error'] ?? '');
        }
        $this->assertSame('verify', $index['stage']);
        $this->assertSame($before, array_diff_key($this->state()['datasets'], ['marketplace_listings' => true]));
        $this->assertSame(15, $index['datasets']['marketplace_listings']['processed']);
        $this->assertSame(1, $index['datasets']['marketplace_listings']['dataset_generation']);
    }

    public function test_all_deleted_shard_remains_a_verified_empty_shard_with_tombstones(): void
    {
        $this->atListings(false);
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->index($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 300 && ! ($index['stage'] === 'verify' && $index['current_dataset'] === 'marketplace_listings'); $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        DB::table('marketplace_listings')->whereBetween('id', [22576, 22580])->delete();
        for ($n = 0; $n < 100 && ! $index['done'] && ! $index['failed']; $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        $this->assertTrue($index['done'], $index['last_error'] ?? '');
        $shard = $index['datasets']['marketplace_listings']['shards'][0];
        $this->assertSame(0, $shard['rows']);
        $this->assertCount(5, $shard['deleted_ids']);
        $this->assertTrue($shard['observation_stable']);
        $this->assertFalse($engine->lookup($this->dir, 'marketplace_listings', '22576', self::ATTEMPT)['found']);
    }

    public function test_continuously_mutating_row_stops_after_three_rounds_without_another_boundary_reset(): void
    {
        $this->atListings(false);
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->index($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 300 && ! ($index['stage'] === 'verify' && $index['current_dataset'] === 'marketplace_listings'); $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        $boundary = $index['datasets']['marketplace_listings']['dataset_boundary_id'];
        for ($n = 0; $n < 100 && ! $index['failed'] && ! $index['done']; $n++) {
            DB::table('marketplace_listings')->where('id', 22576)->update(['quantity' => $n + 10]);
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        $this->assertSame('snapshot_mutable_dataset_unstable', $index['last_error']);
        $this->assertSame(3, $index['datasets']['marketplace_listings']['mutable_round']);
        $this->assertSame($boundary, $index['datasets']['marketplace_listings']['dataset_boundary_id']);
        $this->expectException(MigrationExportException::class);
        $engine->requestDatasetRecovery($this->dir, self::ATTEMPT);
    }

    public function test_recovery_cannot_hide_corrupt_old_shards_or_checksum_chain(): void
    {
        $before = $this->legacyFailure();
        $old = $before['datasets']['marketplace_listings']['shards'][0];
        file_put_contents($this->dir.'/'.$old['path'], 'corrupt');
        $this->resume()->assertOk();
        $result = $this->tick();
        $this->assertSame('snapshot_shard_checksum_mismatch', $result['last_error']);
        $this->assertSame($old, $this->state()['datasets']['marketplace_listings']['shards'][0]);
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('next_action', 'blocked');
        $this->resume()->assertConflict()->assertJsonPath('reason_code', 'dataset_recovery_not_allowed');
    }

    public function test_corrupt_chain_cannot_be_reset_even_when_individual_files_are_valid(): void
    {
        $state = $this->legacyFailure();
        $state['datasets']['marketplace_listings']['checksum_partial'] = str_repeat('0', 64);
        $this->state($state);
        $this->resume()->assertOk();
        $this->assertSame('snapshot_shard_checksum_mismatch', $this->tick()['last_error']);
        $this->assertArrayNotHasKey('dataset_generation', $this->state()['datasets']['marketplace_listings']);
    }

    public function test_cutoff_guard_remains_strict_for_cars(): void
    {
        DB::table('cars')->insert(['id' => 90, 'uuid' => (string) Str::uuid(), 'make' => 'Fixture', 'model' => 'Fixture']);
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->begin($this->dir, self::ATTEMPT);
        while ($index['stage'] === 'boundaries') {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        DB::table('cars')->where('id', 90)->update(['updated_at' => '2099-01-01 12:00:00']);
        while ($index['current_dataset'] !== 'cars') {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        $failed = $engine->tick($this->dir, self::ATTEMPT);
        $this->assertSame('snapshot_row_updated_after_cutoff', $failed['last_error']);
        $this->assertSame('cars', $failed['current_dataset']);
        $this->expectException(MigrationExportException::class);
        $engine->requestDatasetRecovery($this->dir, self::ATTEMPT);
    }

    public function test_mismatched_attempt_schema_source_and_nonwhitelisted_failures_are_blocked(): void
    {
        $before = $this->legacyFailure();
        $this->postJson(self::BASE.self::RUN, ['action' => 'resume', 'expected_snapshot_attempt' => (string) Str::uuid()])->assertConflict()->assertJsonPath('reason_code', 'stale_snapshot_attempt');
        foreach (['snapshot_schema_changed', 'snapshot_source_changed', 'snapshot_shard_checksum_mismatch', 'snapshot_immutable_chunk_conflict'] as $reason) {
            $state = $before;
            $state['last_error'] = $reason;
            $this->state($state);
            $this->resume()->assertConflict()->assertJsonPath('reason_code', 'dataset_recovery_not_allowed');
        }
        $state = $before;
        $state['datasets']['marketplace_listings']['schema_sha256'] = 'bad';
        $this->state($state);
        $this->resume()->assertConflict()->assertJsonPath('reason_code', 'snapshot_schema_changed');
        $this->state($before);
        $this->root(['snapshot_source_fingerprint' => 'different']);
        $this->resume()->assertConflict()->assertJsonPath('reason_code', 'snapshot_source_context_mismatch');
    }

    public function test_legacy_diagnostic_reads_only_safe_fields_and_labels_missing_historical_evidence(): void
    {
        $state = $this->legacyFailure();
        $this->getJson(self::BASE.self::RUN.'?format=dataset-conflict')->assertOk()->assertJsonPath('evidence', 'recorded_at_failure')->assertJsonPath('conflict.record_id', '22581');
        unset($state['source_conflict']);
        $this->state($state);
        $before = hash_file('sha256', $this->file);
        $response = $this->getJson(self::BASE.self::RUN.'?format=dataset-conflict')->assertOk()->assertJsonPath('record.id', 22581)
            ->assertJsonPath('evidence', 'current_read_of_legacy_failure_page_not_historical_proof')->assertJsonPath('changed_business_fields', null);
        $this->assertStringNotContainsString('raw_payload', $response->getContent());
        $this->assertStringNotContainsString('DO_NOT_EXPORT_SECRET', $response->getContent());
        $this->assertSame($before, hash_file('sha256', $this->file));
    }

    public function test_recovered_run_finishes_existing_pipeline_with_manifest_history_and_no_old_generation_files(): void
    {
        $before = $this->legacyFailure();
        $oldPath = $before['datasets']['marketplace_listings']['shards'][0]['path'];
        $this->resume()->assertOk();
        $export = app(GpswissMigrationExport::class);
        $status = $export->status($this->owner, self::RUN);
        for ($n = 0; $n < 1000 && $status['state'] !== 'completed' && $status['next_action'] !== 'blocked'; $n++) {
            $status = $status['next_action'] === 'snapshot_tick' ? $this->tick() : $export->tick($this->owner, self::RUN);
        }
        $this->assertSame('completed', $status['state'], $status['last_error'] ?? '');
        $manifest = json_decode(file_get_contents($this->dir.'/migration-manifest.json'), true);
        $this->assertSame(self::ATTEMPT, $manifest['snapshot_attempt_id']);
        $this->assertSame('snapshot_row_updated_after_cutoff', $manifest['snapshot']['dataset_boundary_history'][0]['recovery_reason']);
        $this->assertSame('bounded_current_rows_reconciled_observations_v1', $manifest['snapshot']['datasets']['marketplace_listings']['consistency_mode']);
        $this->assertStringNotContainsString($oldPath, file_get_contents($this->dir.'/archive-input.jsonl'));
        $this->assertStringNotContainsString('dataset-history/', file_get_contents($this->dir.'/archive-input.jsonl'));
        $sample = json_decode(file_get_contents($this->dir.'/sample/manifest.json'), true);
        $this->assertCount(1, $sample['dataset_boundary_history']);
        $this->assertFileExists($this->dir.'/'.$oldPath);
        Http::assertNothingSent();
    }

    public function test_kick_pause_resume_and_replayed_tick_keep_the_same_generation(): void
    {
        $this->legacyFailure();
        $kick = $this->postJson(self::BASE.self::RUN.'/kick', ['attempt_id' => self::ATTEMPT])->assertOk()->assertJsonPath('kick_reason', 'recovery_started');
        $boundary = $this->state()['dataset_recovery']['dataset_boundary_id'];
        app(GpswissMigrationExport::class)->pauseBrowserSnapshot($this->owner, self::RUN);
        $this->resume()->assertOk();
        $this->postJson(self::BASE.self::RUN.'/kick', ['attempt_id' => self::ATTEMPT])->assertOk()->assertJsonPath('kick_reason', 'tick_completed');
        $this->assertSame($boundary, $this->state()['datasets']['marketplace_listings']['dataset_boundary_id']);
        $this->tick();
        $this->tick(); // A lost successful response is followed by another checkpointed unit.
        $definition = $this->state()['datasets']['marketplace_listings'];
        $this->assertSame($boundary, $definition['dataset_boundary_id']);
        $ids = [];
        foreach ($definition['shards'] as $shard) {
            $ids = [...$ids, ...array_column(json_decode(file_get_contents($this->dir.'/'.$shard['path']), true), 'id')];
        }
        $this->assertCount(10, array_unique($ids));
        $this->assertCount(10, $ids);
    }
}
