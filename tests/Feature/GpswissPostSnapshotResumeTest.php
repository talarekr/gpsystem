<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\BrowserMigrationRelations;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\BrowserMigrationWork;
use App\Services\MigrationExport\CompactSnapshotProgress;
use App\Services\MigrationExport\GpswissMigrationExport;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GpswissPostSnapshotResumeTest extends TestCase
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
            'filesystems.served_public_storage_root' => null, 'migration_export.browser_snapshot_rows' => 5, 'migration_export.archive_payload_bytes' => 128 * 1024]);
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
        foreach (range(10, 14) as $id) {
            DB::table('marketplace_listings')->insert(['id' => $id, 'part_id' => 2262, 'marketplace' => 'ebay', 'external_offer_id' => '000'.$id, 'status' => 'active']);
        }
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

    private function exactRelationCheckpoint(): array
    {
        $state = $this->completedCheckpoint(true);
        $this->assertSame('marketplace_listings', $state['dataset_names'][35]);
        // Only the resumed shard is read. Earlier descriptors/counters model committed history,
        // not 45983 newly generated fixture records or a fresh source export.
        $descriptor = $state['datasets']['marketplace_listings']['shards'][0];
        $state['datasets']['marketplace_listings']['shards'] = array_fill(0, 2240, $descriptor);
        $this->state($state);
        app(CompactSnapshotProgress::class)->publish($this->dir, self::ATT, $state);
        $this->postTick()->assertOk()->assertJsonPath('phase', 'relation_audit');
        app(BrowserMigrationRelations::class)->tick($this->dir, self::ATT);
        $relation = json_decode(file_get_contents($this->dir.'/relation-checkpoint.json'), true);
        $relation = array_replace($relation, ['dataset_index' => 35, 'shard_index' => 2239, 'row_index' => 0, 'column_index' => 0,
            'processed' => 45983, 'total' => 79957, 'probes' => 67253]);
        file_put_contents($this->dir.'/relation-checkpoint.json', json_encode($relation));
        @unlink($this->dir.'/operational-progress.json');

        return $state;
    }

    public function test_resume_preserves_exact_relation_checkpoint_then_next_tick_advances_without_any_source_reads(): void
    {
        $state = $this->exactRelationCheckpoint();
        $before = hash_file('sha256', $this->file);
        $relationBefore = file_get_contents($this->dir.'/relation-checkpoint.json');
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $response = $this->postJson(self::BASE.self::RUN, ['action' => 'resume', 'expected_snapshot_attempt' => self::ATT])
            ->assertOk()->assertJsonPath('state', 'snapshot_completed')->assertJsonPath('next_action', 'snapshot_tick')
            ->assertJsonPath('processed', 45983)->assertJsonPath('cursor.shard_index', 2239)->assertJsonPath('cursor.probes', 67253);
        $this->assertLessThan(8192, strlen($response->getContent()));
        $this->assertSame($relationBefore, file_get_contents($this->dir.'/relation-checkpoint.json'));
        $this->postTick()->assertOk()->assertJsonPath('processed', 45988)->assertJsonPath('cursor.shard_index', 2240)
            ->assertJsonPath('cursor.probes', 67258)->assertJsonPath('exported_records', 79957)->assertJsonPath('verification_processed', 2155);
        $this->assertSame($before, hash_file('sha256', $this->file));
        $this->assertSame($state['consistency_exception'], $this->root()['consistency_exception']);
        $this->assertSame(self::ATT, $this->root()['snapshot_attempt_id']);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_guarded_kick_continues_the_same_post_snapshot_relation_checkpoint_and_handles_lost_response(): void
    {
        $this->exactRelationCheckpoint();
        $before = hash_file('sha256', $this->file);
        $this->postJson(self::BASE.self::RUN.'/kick', ['attempt_id' => self::ATT])->assertOk()
            ->assertJsonPath('kick_reason', 'tick_completed')->assertJsonPath('processed', 45988)->assertJsonPath('cursor.probes', 67258);
        // Discard the operation reply and load the committed progress exactly as the browser does.
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('processed', 45988)->assertJsonPath('cursor.shard_index', 2240);
        $this->postJson(self::BASE.self::RUN.'/kick', ['attempt_id' => self::ATT])->assertOk()->assertJsonPath('processed', 45988)
            ->assertJsonPath('cursor.current_dataset_index', 36);
        $this->assertSame($before, hash_file('sha256', $this->file));
        Http::assertNothingSent();
    }

    public function test_every_real_post_snapshot_stage_can_pause_resume_and_complete_with_original_photo_copying(): void
    {
        $state = $this->completedCheckpoint();
        $before = hash_file('sha256', $this->file);
        $this->postTick()->assertOk()->assertJsonPath('phase', 'relation_audit');
        $service = app(GpswissMigrationExport::class);
        $queries = $visited = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        for ($n = 0; $n < 1500 && $this->root()['state'] !== 'completed'; $n++) {
            $root = $this->root();
            if (! isset($visited[$root['phase']])) {
                $visited[$root['phase']] = true;
                $service->pauseBrowserSnapshot($this->owner, self::RUN);
                $service->resume($this->owner, self::RUN, self::ATT);
            }
            $action = BrowserMigrationWork::action($this->root());
            $this->assertContains($action, ['snapshot_tick', 'tick']);
            if ($action === 'snapshot_tick') {
                $service->browserSnapshotTick($this->owner, self::RUN, self::ATT);
            } else {
                $service->tick($this->owner, self::RUN);
            }
            $this->assertNull($this->root()['last_error']);
        }
        $this->assertSame('completed', $this->root()['state']);
        foreach (['relation_audit', 'photo_inventory', 'photos', 'archive_index', 'archive_sample', 'archive_sample_volumes', 'archive_volumes', 'archive_catalog'] as $phase) {
            $this->assertArrayHasKey($phase, $visited);
        }
        $this->assertSame($before, hash_file('sha256', $this->file));
        $this->assertSame($state['consistency_exception'], $this->root()['consistency_exception']);
        $this->assertSame(self::ATT, $this->root()['snapshot_attempt_id']);
        $this->assertSame([], $queries);
        $this->assertNotEmpty(File::allFiles($this->dir.'/images'));
        $catalog = json_decode(file_get_contents($this->dir.'/migration-package-manifest.json'), true);
        $this->assertTrue($catalog['technical_package_ready']);
        Http::assertNothingSent();
    }

    public function test_one_work_contract_covers_capture_post_capture_photos_and_all_packaging_steps(): void
    {
        $work = BrowserMigrationWork::class;
        foreach (['snapshot_boundaries', 'snapshot_export', 'snapshot_verify', 'snapshot_recovery_preflight', 'snapshot_operator_finalize'] as $phase) {
            $this->assertSame('snapshot_tick', $work::next(['state' => 'snapshot_running', 'phase' => $phase]));
        }
        foreach (['relation_audit', 'photo_inventory'] as $phase) {
            $this->assertSame('snapshot_tick', $work::next(['state' => 'snapshot_completed', 'phase' => $phase]));
        }
        foreach (['photos', 'photos_retry_setup', 'photos_retry_inventory', 'photos_retry'] as $phase) {
            $this->assertSame('tick', $work::next(['state' => 'photos', 'phase' => $phase]));
        }
        foreach (['archive_index', 'archive_sample', 'archive_sample_volumes', 'archive_volumes', 'archive_catalog'] as $phase) {
            $this->assertSame('tick', $work::next(['state' => 'archive', 'phase' => $phase]));
        }
        $this->assertSame('blocked', $work::next(['state' => 'snapshot_completed', 'phase' => 'snapshot_export']));
        $this->assertSame('blocked', $work::next(['state' => 'archive', 'phase' => 'unknown']));
        $this->assertSame('resume', $work::next(['state' => 'pipeline_paused', 'phase' => 'archive_catalog']));
        $this->assertSame('download', $work::next(['state' => 'completed', 'phase' => 'completed']));
    }
}
