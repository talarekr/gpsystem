<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\CompactSnapshotProgress;
use App\Services\MigrationExport\GpswissMigrationExport;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class GpswissOperatorDriftFinalizeTest extends TestCase
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
        $this->tmp = sys_get_temp_dir().'/operator-drift-'.Str::uuid();
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

    public function test_explicit_accept_keeps_shards_and_generation_skips_live_reads_and_finishes_photos_sample_full_checksums(): void
    {
        $before = $this->fixture();
        $files = [];
        foreach ($before['datasets'] as $definition) {
            foreach ($definition['shards'] as $shard) {
                foreach (['path', 'csv_path'] as $key) {
                    $files[$shard[$key]] = hash_file('sha256', $this->dir.'/'.$shard[$key]);
                }
            }
        }
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('operator_parts_drift_allowed', true);
        $this->accept('WRONG')->assertStatus(422);
        $this->assertArrayNotHasKey('consistency_exception', $this->state());
        $this->accept()->assertOk()->assertJsonPath('phase', 'snapshot_operator_finalize');
        $accepted = $this->state()['consistency_exception'];
        $this->assertSame(['2262'], $accepted['recorded_ids']);
        $this->assertSame(2, $accepted['known_count_from_operator']);
        $this->assertSame(1, $accepted['remaining_operator_declared_without_recorded_id']);
        $this->assertSame(1, $accepted['generation_at_acceptance']);
        $this->assertSame(self::BOUNDARY, $accepted['boundary_at_acceptance']);
        $this->accept()->assertOk();
        $this->assertSame($accepted, $this->state()['consistency_exception']);
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $service = app(GpswissMigrationExport::class);
        $status = $service->status($this->owner, self::RUN);
        for ($n = 0; $n < 1500 && $status['state'] !== 'completed' && $status['next_action'] !== 'blocked'; $n++) {
            $status = $status['next_action'] === 'snapshot_tick' ? $service->browserSnapshotTick($this->owner, self::RUN, self::ATT) : $service->tick($this->owner, self::RUN);
        }
        $this->assertSame('completed', $status['state'], $status['last_error'] ?? '');
        $this->assertSame([], $queries);
        foreach ($files as $path => $sha) {
            $this->assertSame($sha, hash_file('sha256', $this->dir.'/'.$path));
        }
        foreach ($before['datasets'] as $name => $definition) {
            $after = $this->state()['datasets'][$name];
            unset($after['artifact_integrity']);
            $this->assertSame($definition, $after);
        }
        $this->assertSame($accepted, $this->state()['consistency_exception']);
        $manifest = json_decode(file_get_contents($this->dir.'/migration-manifest.json'), true);
        $this->assertSame($accepted, $manifest['consistency_exception']);
        $this->assertTrue($manifest['operator_finalization']['live_source_verification_skipped']);
        $this->assertFalse($manifest['cutover_ready']);
        $this->assertStringContainsString('operator_accepted_parts_drift', $manifest['snapshot']['database_consistency']);
        $this->assertSame($before['database_consistency'], $manifest['operator_finalization']['previous_database_consistency']);
        $sample = json_decode(file_get_contents($this->dir.'/sample/manifest.json'), true);
        $this->assertSame($accepted, $sample['consistency_exception']);
        $photoRows = array_map(fn ($l) => json_decode($l, true), file($this->dir.'/photo-manifest.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertCount(5, $photoRows);
        $this->assertSame(['copied', 'missing', 'unreadable', 'copied', 'copied'], array_column($photoRows, 'export_status'));
        $this->assertSame('car', $photoRows[3]['entity_type']);
        $this->assertSame('8', $photoRows[3]['car_id']);
        $this->assertNull($photoRows[3]['part_id']);
        $this->assertStringContainsString('images/cars/8/', $photoRows[3]['package_path']);
        foreach ($photoRows as $row) {
            if ($row['status'] === 'COPIED_VERIFIED') {
                $this->assertSame($row['sha256'], hash_file('sha256', $this->dir.'/'.$row['package_path']));
            }
        }
        $this->assertCount(2, file($this->dir.'/missing-originals.jsonl', FILE_IGNORE_NEW_LINES));
        $catalog = json_decode(file_get_contents($this->dir.'/migration-package-manifest.json'), true);
        $this->assertTrue($catalog['technical_package_ready']);
        $this->assertFalse($catalog['originals_complete']);
        $this->assertFalse($catalog['cutover_ready']);
        foreach ($catalog['files'] as $entry) {
            $this->assertSame($entry['sha256'], hash_file('sha256', $this->dir.'/'.$entry['path']));
        }
        $this->assertSame(file_get_contents($this->dir.'/archive-input.jsonl'), file_get_contents($this->dir.'/checksums.jsonl'));
        foreach (['sample-archive-manifest.json', 'archive-manifest.json'] as $path) {
            $map = json_decode(file_get_contents($this->dir.'/'.$path), true);
            $this->assertNotEmpty($map['volumes']);
            foreach ($map['volumes'] as $v) {
                $this->assertSame($v['sha256'], hash_file('sha256', $this->dir.'/'.$v['path']));
                $zip = new ZipArchive;
                $this->assertTrue($zip->open($this->dir.'/'.$v['path']));
                $zip->close();
            }
        }
        Http::assertNothingSent();
    }

    public function test_exact_global_79957_counter_is_preserved_by_accept_without_claiming_unread_artifacts_verified(): void
    {
        $state = $this->fixture();
        $total = array_sum(array_column($state['datasets'], 'processed'));
        $delta = 79957 - $total;
        // Technical counter fixture only; the end-to-end artifact test above uses genuine small fixture shards.
        $state['datasets']['part_translations']['processed'] = $state['datasets']['part_translations']['total'] = $delta;
        $sha = hash('sha256', 'technical counter fixture only');
        $state['datasets']['part_translations']['shards'] = [['rows' => $delta, 'sha256' => $sha]];
        $state['datasets']['part_translations']['checksum_partial'] = hash('sha256', hash('sha256', 'part_translations').$sha);
        $state['datasets']['parts']['cursor'] = ['id' => 8926];
        $this->state($state);
        $this->accept()->assertOk()->assertJsonPath('exported_records', 79957)->assertJsonPath('total', 79957);
        $after = $this->state();
        $this->assertSame(79957, array_sum(array_column($after['datasets'], 'processed')));
        $this->assertSame(['id' => 8926], $after['datasets']['parts']['cursor']);
        $this->assertSame($state['datasets'], $after['datasets']);
        $this->assertSame('operator_finalize', $after['stage']);
        $this->assertArrayNotHasKey('artifact_integrity_complete', $after['operator_finalization']);
    }

    public function test_non_complete_export_wrong_attempt_and_corruption_are_not_accepted(): void
    {
        $before = $this->fixture();
        $this->accept(BrowserMigrationSnapshot::DRIFT_CONFIRM, (string) Str::uuid())->assertStatus(409);
        $state = $before;
        $state['datasets']['parts']['processed']--;
        $this->state($state);
        $this->accept()->assertStatus(409)->assertJsonPath('reason_code', 'operator_finalization_requires_complete_export');
        $this->state($before);
        $root = $this->root();
        $root['last_error'] = 'snapshot_shard_checksum_mismatch';
        $this->root($root);
        $this->accept()->assertStatus(409);
        $this->assertArrayNotHasKey('consistency_exception', $this->state());
    }

    public function test_artifact_corruption_still_blocks_after_explicit_accept_and_cannot_be_waived_by_replay(): void
    {
        $state = $this->fixture();
        $shard = $state['datasets']['parts']['shards'][0];
        file_put_contents($this->dir.'/'.$shard['path'], 'broken');
        $this->accept()->assertOk();
        $service = app(GpswissMigrationExport::class);
        $result = $service->browserSnapshotTick($this->owner, self::RUN, self::ATT);
        $this->assertSame('snapshot_failed', $result['state']);
        $this->assertSame('snapshot_shard_checksum_mismatch', $result['last_error']);
        $this->accept()->assertOk()->assertJsonPath('state', 'snapshot_failed');
        $this->assertFileDoesNotExist($this->dir.'/migration-package-manifest.json');
    }

    public function test_photo_byte_checkpoint_survives_lost_response_pause_reload_resume_and_kick(): void
    {
        $this->fixture();
        config(['migration_export.photo_chunk_bytes' => 65536]);
        $bytes = base64_decode(self::PNG).str_repeat('x', 4 * 1024 * 1024);
        file_put_contents($this->tmp.'/originals/part.png', $bytes);
        $this->accept()->assertOk();
        $service = app(GpswissMigrationExport::class);
        for ($n = 0; $n < 500 && $this->root()['state'] !== 'photos'; $n++) {
            $service->browserSnapshotTick($this->owner, self::RUN, self::ATT);
        }
        $this->assertSame('photos', $this->root()['state']);
        $service->tick($this->owner, self::RUN); // Ignore the response, as if it were lost after commit.
        $status = $service->compactStatus($this->owner, self::RUN);
        $this->assertSame(0, $status['processed']);
        $offset = $status['cursor']['current_image_offset'];
        $this->assertGreaterThan(0, $offset);
        $this->assertLessThanOrEqual(4 * 65536, $offset);
        $this->postJson(self::BASE.self::RUN, ['action' => 'pause_snapshot'])->assertOk()->assertJsonPath('state', 'pipeline_paused');
        $service->kickBrowserSnapshot($this->owner, self::RUN, self::ATT);
        $this->assertSame($offset, $service->compactStatus($this->owner, self::RUN)['cursor']['current_image_offset']);
        $this->postJson(self::BASE.self::RUN, ['action' => 'resume'])->assertOk()->assertJsonPath('state', 'photos');
        app()->forgetInstance(GpswissMigrationExport::class); // A new HTTP worker/reload uses persisted state only.
        $fresh = app(GpswissMigrationExport::class);
        $fresh->kickBrowserSnapshot($this->owner, self::RUN, self::ATT);
        $this->assertGreaterThan($offset, $fresh->compactStatus($this->owner, self::RUN)['cursor']['current_image_offset']);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $this->tmp.'/originals/part.png'));
        $this->assertSame(self::ATT, $this->root()['snapshot_attempt_id']);
        Http::assertNothingSent();
    }

    public function test_lost_accept_root_publication_resumes_the_same_accepted_intent(): void
    {
        $before = $this->fixture();
        $root = $this->root();
        $this->accept()->assertOk();
        $accepted = $this->state()['consistency_exception'];
        $root['state'] = $root['snapshot_state'] = 'snapshot_failed';
        $root['last_error'] = 'snapshot_row_updated_after_cutoff';
        $this->root($root);
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('next_action', 'resume');
        $this->postJson(self::BASE.self::RUN, ['action' => 'resume', 'expected_snapshot_attempt' => self::ATT])->assertOk()
            ->assertJsonPath('phase', 'snapshot_operator_finalize');
        $this->assertSame($accepted, $this->state()['consistency_exception']);
        $this->assertSame($before['datasets'], $this->state()['datasets']);
    }

    public function test_accept_keeps_owner_csrf_source_fencing_and_declared_count_limits(): void
    {
        $before = $this->fixture();
        $this->app->bind(ValidateCsrfToken::class, VerifyOperatorDriftCsrf::class);
        $this->accept()->assertStatus(419);
        $this->app->bind(ValidateCsrfToken::class, ValidateCsrfToken::class);
        $root = $this->root();
        $root['snapshot_source_fingerprint'] = 'wrong-source';
        $this->root($root);
        $this->accept()->assertStatus(409)->assertJsonPath('reason_code', 'snapshot_source_context_mismatch');
        $this->assertArrayNotHasKey('consistency_exception', $this->state());
        $other = User::create(['name' => 'Other operator', 'email' => 'other-drift@example.test', 'password' => 'password']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other);
        $this->accept()->assertStatus(404)->assertJsonPath('reason_code', 'run_owner_mismatch');
        $this->assertSame($before, $this->state());
    }

    public function test_more_than_two_recorded_dirty_ids_are_not_covered_by_the_operator_declaration(): void
    {
        $state = $this->fixture();
        $state['datasets']['parts']['shards'][0]['dirty_ids'] = ['2263', '2264'];
        $this->state($state);
        $this->accept()->assertStatus(409)->assertJsonPath('reason_code', 'parts_drift_exceeds_operator_declared_count');
        $this->assertArrayNotHasKey('consistency_exception', $this->state());
        $data = ['run_id' => (string) Str::uuid(), 'snapshot_attempt_id' => self::ATT, 'current_dataset' => 'parts', 'state' => 'snapshot_running', 'processed' => 79957, 'total' => 79957, 'source_conflict' => ['dataset' => 'parts']];
        $this->assertFalse(BrowserMigrationSnapshot::operatorDriftAvailable($data));
    }

    public function test_accept_preserves_legitimate_in_progress_mutable_shards_with_a_verified_archived_baseline(): void
    {
        $state = $this->fixture();
        $old = $state['datasets']['parts'];
        $relative = 'dataset-history/parts/legacy/definition.json';
        $file = $this->dir.'/logical-snapshot/'.self::ATT.'/'.$relative;
        File::ensureDirectoryExists(dirname($file), 0700);
        file_put_contents($file, json_encode($old));
        $state['datasets']['parts']['dataset_boundary_id'] = self::BOUNDARY;
        $state['dataset_boundary_history'][] = ['dataset' => 'parts', 'dataset_boundary_id' => self::BOUNDARY,
            'old_definition_path' => $relative, 'old_definition_sha256' => hash_file('sha256', $file)];
        $state['stage'] = 'verify';
        unset($state['dataset_recovery']);
        $this->state($state);
        app(BrowserMigrationSnapshot::class)->tick($this->dir, self::ATT); // Before ACCEPT, the existing writer observes Part 2262 dirty.
        $before = $this->state()['datasets']['parts'];
        $this->assertNotEmpty($before['mutable_pending']);
        $this->assertNotSame($old['shards'][0]['sha256'], $before['shards'][0]['sha256']);
        file_put_contents($file, 'corrupted baseline');
        $this->accept()->assertStatus(409)->assertJsonPath('reason_code', 'snapshot_shard_checksum_mismatch');
        $this->assertArrayNotHasKey('consistency_exception', $this->state());
        file_put_contents($file, json_encode($old));
        $this->accept()->assertOk();
        $service = app(GpswissMigrationExport::class);
        for ($n = 0; $n < 500 && $this->root()['state'] !== 'photos' && $this->root()['state'] !== 'snapshot_failed'; $n++) {
            $service->browserSnapshotTick($this->owner, self::RUN, self::ATT);
        }
        $this->assertSame('photos', $this->root()['state'], $this->root()['last_error'] ?? '');
        $after = $this->state()['datasets']['parts'];
        unset($after['artifact_integrity']);
        $this->assertSame($before, $after);
        $this->assertSame('mutable_round_chain_not_yet_finalized', $this->state()['operator_finalization']['chain_validation_exceptions']['parts']['reason']);
    }
}

class VerifyOperatorDriftCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
