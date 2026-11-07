<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\CompactSnapshotProgress;
use App\Services\MigrationExport\GpswissMigrationExport;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GpswissOperatorFinalizeHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const RUN = BrowserMigrationSnapshot::DRIFT_RUN;

    private const ATT = BrowserMigrationSnapshot::DRIFT_ATTEMPT;

    private const BOUNDARY = 'a42db372-cf85-4f46-99e7-ccacce2bc6c3';

    private const BASE = '/admin/tools/gpswiss-migration-export/';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg==';

    private string $tmp;

    private string $dir;

    private string $file;

    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/operator-handoff-'.Str::uuid();
        $this->dir = $this->tmp.'/exports/'.self::RUN;
        $this->file = $this->dir.'/logical-snapshot/'.self::ATT.'/checkpoint.json';
        File::ensureDirectoryExists($this->tmp.'/originals', 0700);
        config(['migration_export.root' => $this->tmp.'/exports', 'filesystems.disks.public.root' => $this->tmp.'/originals',
            'filesystems.served_public_storage_root' => null, 'migration_export.browser_snapshot_rows' => 5]);
        $this->seed(RoleSeeder::class);
        $owner = User::create(['name' => 'Export operator', 'email' => 'drift@example.test', 'password' => 'password']);
        $owner->assignRole(UserRole::OwnerAdmin->value);
        $this->owner = $owner->id;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(GpswissMigrationExport::class)->prepareBrowser($this->owner, self::RUN);
        File::deleteDirectory($this->dir.'/logical-snapshot');
        $root = $this->root();
        $root['snapshot_attempt_id'] = self::ATT;
        $root['state'] = $root['snapshot_state'] = 'snapshot_running';
        $root['phase'] = 'snapshot_recovery_preflight';
        $root['last_error'] = null;
        $this->root($root);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function root(?array $value = null): array
    {
        if ($value !== null) {
            file_put_contents($this->dir.'/state.json', json_encode($value));
        }

        return json_decode(file_get_contents($this->dir.'/state.json'), true);
    }

    private function state(?array $value = null): array
    {
        if ($value !== null) {
            file_put_contents($this->file, json_encode($value));
        }

        return json_decode(file_get_contents($this->file), true);
    }

    private function accept(string $token = BrowserMigrationSnapshot::DRIFT_CONFIRM, ?string $attempt = null)
    {
        return $this->postJson(self::BASE.self::RUN, ['action' => 'accept_parts_drift', 'expected_snapshot_attempt' => $attempt ?? self::ATT, 'confirm_parts_drift' => $token]);
    }

    private function fixture(): array
    {
        foreach ([8, 9] as $id) {
            DB::table('cars')->insert(['id' => $id, 'uuid' => (string) Str::uuid(), 'make' => 'Fixture', 'model' => 'Car '.$id, 'external_id' => '000'.$id, 'main_photo_path' => $id === 8 ? 'car.png' : 'standalone.png']);
        }
        foreach (range(2262, 2266) as $id) {
            DB::table('parts')->insert(['id' => $id, 'sku' => '000-'.$id, 'name' => 'Part '.$id, 'car_id' => 8, 'quantity' => 1, 'status' => 'ready', 'needs_listing' => true, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
        }
        foreach (['part.png', 'car.png', 'standalone.png'] as $path) {
            file_put_contents($this->tmp.'/originals/'.$path, base64_decode(self::PNG));
        }
        mkdir($this->tmp.'/originals/unreadable.png', 0700); // A non-file original is explicitly unreadable/unsafe, including when tests run as root.
        foreach (['part.png', 'missing.png', 'unreadable.png'] as $n => $path) {
            DB::table('part_images')->insert(['id' => $n + 1, 'part_id' => 2262, 'path' => $path, 'sort_order' => $n, 'is_primary' => $n === 0]);
        }
        DB::table('car_images')->insert(['id' => 1, 'car_id' => 8, 'path' => 'car.png', 'sort_order' => 0, 'is_primary' => true]);
        DB::table('orders')->insert(['id' => 1, 'order_number' => '000-order', 'customer_name' => 'Fixture buyer', 'email' => 'fixture@example.test', 'phone' => '', 'address_line1' => 'Fixture', 'postal_code' => '00-000', 'city' => 'Fixture', 'country' => 'PL', 'status' => 'pending', 'currency' => 'PLN', 'subtotal' => 0, 'shipping_total' => 0, 'total' => 0]);
        DB::table('order_items')->insert(['id' => 1, 'order_id' => 1, 'part_id' => 2262, 'sku' => '000-2262', 'product_name' => 'Stored title', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0]);
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->begin($this->dir, self::ATT);
        for ($n = 0; $n < 500 && in_array($index['stage'], ['boundaries', 'export'], true); $n++) {
            $index = $engine->tick($this->dir, self::ATT);
        }
        $this->assertSame('verify', $index['stage']);
        $state = $this->state();
        $state['stage'] = 'recovery_preflight';
        $state['dataset_index'] = 0;
        $state['source_conflict'] = ['dataset' => 'parts', 'record_id' => '2262', 'timestamp_field' => 'updated_at', 'record_updated_at' => '2026-11-06 19:20:03',
            'cutoff_database_time' => '2026-11-06 15:17:19.925197', 'historical_version_available' => false];
        $state['dataset_recovery'] = ['dataset' => 'parts', 'previous_generation' => 0, 'new_generation' => 1, 'previous_boundary_id' => 'legacy', 'dataset_boundary_id' => self::BOUNDARY,
            'recovery_reason' => 'snapshot_row_updated_after_cutoff', 'preflight_shard' => 0, 'preflight_rows' => 0, 'preflight_total_shards' => count($state['datasets']['parts']['shards']),
            'preflight_chain' => hash('sha256', 'parts'), 'return_stage' => 'verify', 'strategy' => 'adopt_complete_shards_then_reconcile'];
        $this->state($state);
        app(CompactSnapshotProgress::class)->publish($this->dir, self::ATT, $state);
        DB::table('parts')->where('id', 2262)->update(['status' => 'sold', 'quantity' => 0, 'updated_at' => '2026-11-06 19:20:03']);

        return $state;
    }

    private function completedCheckpoint(bool $exact = false): array
    {
        $this->fixture();
        $this->accept()->assertOk();
        $engine = app(BrowserMigrationSnapshot::class);
        for ($n = 0; $n < 100 && $this->state()['stage'] !== 'completed'; $n++) {
            $engine->tick($this->dir, self::ATT);
        }
        $state = $this->state();
        $this->assertSame('completed', $state['stage']);
        $state['consistency_exception']['recorded_ids'] = ['1937', '2262'];
        $state['consistency_exception']['remaining_operator_declared_without_recorded_id'] = 0;
        $state['operator_finalization']['artifacts_checked'] = 15629;
        if ($exact) {
            // Technical production-counter projection only; no claim of 79957 real fixture rows.
            $delta = 79957 - array_sum(array_column($state['datasets'], 'total'));
            $state['datasets']['part_translations']['total'] += $delta;
            $state['datasets']['part_translations']['processed'] += $delta;
            $state['datasets']['parts']['verified'] = 2155;
        }
        $this->state($state);
        app(CompactSnapshotProgress::class)->publish($this->dir, self::ATT, $state);
        $root = $this->root();
        $root['consistency_exception'] = $state['consistency_exception'];
        $this->root($root);

        return $state;
    }

    private function postTick()
    {
        return $this->postJson(self::BASE.self::RUN, ['action' => 'snapshot_tick', 'expected_snapshot_attempt' => self::ATT]);
    }

    public function test_exact_completed_checkpoint_and_stale_root_resume_at_handoff_without_rechecking_artifacts_or_source(): void
    {
        $state = $this->completedCheckpoint(true);
        $before = file_get_contents($this->file);
        $cache = json_decode(file_get_contents($this->dir.'/compact-snapshot-progress.json'), true);
        $cache['progress']['current_dataset'] = $cache['progress']['cursor'] = null;
        unset($cache['progress']['operator_finalize_stage']);
        file_put_contents($this->dir.'/compact-snapshot-progress.json', json_encode($cache));
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('operator_finalize_stage', 'snapshot_seal')
            ->assertJsonPath('current_dataset', 'operator_finalize:snapshot_seal')->assertJsonPath('cursor.dataset_index', 39)
            ->assertJsonPath('cursor.artifacts_checked', 15629)->assertJsonPath('snapshot_retry_allowed', false);
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $this->postJson(self::BASE.self::RUN, ['action' => 'resume', 'expected_snapshot_attempt' => self::ATT])->assertOk();
        $result = $this->postTick()->assertOk()->assertJsonPath('phase', 'relation_audit')->assertJsonPath('operator_finalize_stage', 'relations')
            ->assertJsonPath('exported_records', 79957)->assertJsonPath('record_capture_total', 79957)->assertJsonPath('verification_processed', 2155);
        $this->assertLessThan(32768, strlen($result->getContent()));
        $this->assertSame([], $queries);
        $this->assertSame($before, file_get_contents($this->file));
        $this->assertSame(self::ATT, $this->root()['snapshot_attempt_id']);
        $this->assertSame($state['consistency_exception'], $this->root()['consistency_exception']);
        $this->assertFileDoesNotExist($this->dir.'/snapshot.json'); // No full legacy mirror is required for the browser pipeline.
        $trace = json_decode(file_get_contents($this->dir.'/operator-finalize-last-request.json'), true);
        $this->assertSame('snapshot_seal', $trace['stage_before']);
        $this->assertSame('relations', $trace['stage_after']);
        $this->assertSame(15629, $trace['artifact_offset_after']['artifacts_checked']);
        $this->assertSame('response_serialized', $trace['reason_code']);
        Http::assertNothingSent();
    }

    public function test_final_dataset_transition_uses_only_saved_artifacts_then_reaches_photo_phase(): void
    {
        $state = $this->completedCheckpoint();
        $state['stage'] = 'operator_finalize';
        $state['operator_finalization']['dataset_index'] = 38;
        unset($state['operator_finalization']['artifact_integrity_complete'],$state['operator_finalization']['completed_at']);
        $this->state($state);
        app(CompactSnapshotProgress::class)->publish($this->dir, self::ATT, $state);
        $rowsBefore = DB::table('parts')->orderBy('id')->get()->toJson();
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $this->postTick()->assertOk()->assertJsonPath('phase', 'snapshot_operator_finalize')->assertJsonPath('operator_finalize_stage', 'snapshot_seal');
        $this->postTick()->assertOk()->assertJsonPath('phase', 'relation_audit');
        $service = app(GpswissMigrationExport::class);
        for ($n = 0; $n < 500 && $this->root()['state'] !== 'photos'; $n++) {
            $service->browserSnapshotTick($this->owner, self::RUN, self::ATT);
        }
        $this->assertSame('photos', $this->root()['state']);
        $this->assertSame([], $queries);
        $this->assertFileDoesNotExist($this->dir.'/photos.jsonl');
        $this->assertSame($state['consistency_exception'], $this->state()['consistency_exception']);
        $this->assertSame($rowsBefore, DB::table('parts')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_artifact_corruption_is_a_409_blocker_with_stage_offsets_not_generic_500(): void
    {
        $state = $this->fixture();
        $this->accept()->assertOk();
        $shard = $state['datasets']['parts']['shards'][0];
        file_put_contents($this->dir.'/'.$shard['path'], 'broken');
        $this->postTick()->assertStatus(409)->assertJsonPath('reason_code', 'snapshot_shard_checksum_mismatch')
            ->assertJsonPath('backend_exception_class', \RuntimeException::class)
            ->assertJsonPath('operator_finalize_diagnostic.operator_finalize_stage', 'artifact_integrity')
            ->assertJsonPath('operator_finalize_diagnostic.artifact_offset_before.shard', 0);
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('blocked_reason', 'snapshot_shard_checksum_mismatch')
            ->assertJsonPath('snapshot_retry_allowed', false);
        $this->postJson(self::BASE.self::RUN, ['action' => 'retry_snapshot', 'expected_snapshot_attempt' => self::ATT, 'confirm_restart' => true])
            ->assertStatus(409)->assertJsonPath('reason_code', 'operator_finalization_restart_forbidden');
        $this->assertTrue($this->state()['consistency_exception']['accepted_by_operator']);
        Http::assertNothingSent();
    }

    public function test_fatal_after_healthy_last_tick_is_recorded_without_overwriting_its_historical_result(): void
    {
        $this->completedCheckpoint();
        file_put_contents($this->dir.'/last-tick.json', json_encode(['action' => 'operator_artifact_finalization', 'reason_code' => 'tick_completed']));
        $service = app(GpswissMigrationExport::class);
        $this->assertNotNull($service->beginOperatorFinalizeRequest($this->owner, self::RUN));
        $service->markTickFatal($this->owner, self::RUN, 'memory_exhaustion');
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('operator_finalize_diagnostic.reason_code', 'memory_exhaustion')
            ->assertJsonPath('operator_finalize_diagnostic.backend_exception_class', 'PHPFatalError')
            ->assertJsonPath('operator_finalize_diagnostic.operator_finalize_stage', 'snapshot_seal')
            ->assertJsonPath('last_tick.reason_code', 'tick_completed');
        $trace = file_get_contents($this->dir.'/operator-finalize-last-request.json');
        $this->assertStringNotContainsString('message', $trace);
        $this->assertStringNotContainsString('stack', $trace);
        Http::assertNothingSent();
    }

    public function test_post_commit_storage_failure_has_safe_500_diagnostic_and_resume_reuses_completed_checkpoint(): void
    {
        $this->completedCheckpoint(true);
        $before = hash_file('sha256', $this->file);
        mkdir($this->dir.'/state.json.tmp', 0700);
        $response = $this->postTick()->assertStatus(500)->assertJsonPath('reason_code', 'server_exception')
            ->assertJsonPath('operator_finalize_diagnostic.operator_finalize_stage', 'snapshot_seal')
            ->assertJsonPath('operator_finalize_diagnostic.safe_exception_category', 'infrastructure');
        $this->assertStringNotContainsString($this->tmp, $response->getContent());
        $this->assertSame($before, hash_file('sha256', $this->file));
        File::deleteDirectory($this->dir.'/state.json.tmp');
        $this->postJson(self::BASE.self::RUN, ['action' => 'resume', 'expected_snapshot_attempt' => self::ATT])->assertOk();
        $this->postTick()->assertOk()->assertJsonPath('phase', 'relation_audit');
        $this->assertSame($before, hash_file('sha256', $this->file));
        Http::assertNothingSent();
    }

    public function test_relation_artifact_corruption_keeps_acceptance_and_returns_explicit_relation_blocker(): void
    {
        $state = $this->completedCheckpoint();
        $this->postTick()->assertOk()->assertJsonPath('phase', 'relation_audit');
        file_put_contents($this->dir.'/'.$state['datasets']['parts']['shards'][0]['path'], 'broken');
        $this->postTick()->assertStatus(409)->assertJsonPath('reason_code', 'relation_shard_missing_or_changed')
            ->assertJsonPath('operator_finalize_diagnostic.operator_finalize_stage', 'relations');
        $this->assertSame('relation_shard_missing_or_changed', $this->root()['last_error']);
        $this->assertTrue($this->state()['consistency_exception']['accepted_by_operator']);
        Http::assertNothingSent();
    }

    public function test_invalid_stored_checkpoint_is_a_controlled_409_not_an_infrastructure_500(): void
    {
        $this->completedCheckpoint();
        file_put_contents($this->file, '{"truncated":');
        $this->postTick()->assertStatus(409)->assertJsonPath('reason_code', 'operator_finalization_invalid_json')
            ->assertJsonPath('backend_exception_class', \JsonException::class)
            ->assertJsonPath('operator_finalize_diagnostic.operator_finalize_stage', 'checkpoint_load');
        $this->assertTrue($this->root()['consistency_exception']['accepted_by_operator']);
        Http::assertNothingSent();
    }

    public function test_twelve_mb_15629_artifact_metadata_handoff_and_response_fit_128_mib_without_redecoding_index(): void
    {
        $state = $this->completedCheckpoint();
        $names = $state['dataset_names'];
        $datasets = $state['datasets'];
        foreach ($datasets as &$definition) {
            $definition['shards'] = [];
            $definition['processed'] = $definition['total'] = $definition['verified'] = 0;
        }unset($definition);
        for ($i = 0; $i < 15629; $i++) {
            $name = $names[$i % 39];
            $path = 'logical-snapshot/'.self::ATT.'/datasets/'.$name.'/'.sprintf('%06d', $i);
            $datasets[$name]['shards'][] = ['path' => $path.'.json', 'csv_path' => $path.'.csv', 'sha256' => str_repeat('a', 64), 'csv_sha256' => str_repeat('b', 64),
                'rows' => 5, 'bytes' => 12345, 'csv_bytes' => 12345, 'cursor_end' => ['id' => $i * 5],
                'row_versions' => [(string) ($i * 5) => ['sha256' => str_repeat('c', 64), 'updated_at' => '2026-11-06 15:17:19']]];
            $datasets[$name]['total'] += 5;
            $datasets[$name]['processed'] += 5;
        }
        $datasets['parts']['processed'] += 1812;
        $datasets['parts']['total'] += 1812;
        $datasets['parts']['verified'] = 2155;
        $state['datasets'] = $datasets;
        // Metadata-only production-size fixture: a completed checkpoint must not re-open 15629 shard files.
        $bytes = json_encode($state);
        $state['metadata_padding'] = str_repeat('x', max(0, 12076656 - strlen($bytes) - 24));
        $this->state($state);
        app(CompactSnapshotProgress::class)->publish($this->dir, self::ATT, $state);
        unset($state,$datasets,$bytes);
        $worker = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Fixtures/operator-finalize-memory.php'), $this->dir], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array']);
        $worker->setTimeout(30);
        $worker->run();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        $result = json_decode(trim($worker->getOutput()), true);
        $this->assertSame('relation_audit', $result['phase']);
        $this->assertTrue($result['checkpoint_unchanged']);
        $this->assertSame(79957, $result['exported_records']);
        $this->assertSame(2155, $result['verification_processed']);
        $this->assertLessThan(128 * 1024 * 1024, $result['peak']);
        $this->assertLessThan(8192, $result['engine_result_bytes']);
    }
}
