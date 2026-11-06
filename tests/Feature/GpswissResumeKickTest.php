<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
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

class VerifyMigrationKickCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

class GpswissResumeKickTest extends TestCase
{
    use RefreshDatabase;

    private const RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba';

    private const ATTEMPT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c';

    private const BASE = '/admin/tools/gpswiss-migration-export/';

    private string $tmp;

    private string $dir;

    private string $checkpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/kick-'.Str::uuid();
        $this->dir = $this->tmp.'/'.self::RUN;
        $this->checkpoint = $this->dir.'/logical-snapshot/'.self::ATTEMPT.'/checkpoint.json';
        config(['migration_export.root' => $this->tmp, 'migration_export.browser_snapshot_rows' => 5]);
        $this->seed(RoleSeeder::class);
        $owner = User::create(['name' => 'Owner', 'email' => 'kick@example.test', 'password' => 'password']);
        $owner->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(GpswissMigrationExport::class)->prepareBrowser($owner->id, self::RUN);
        $old = $this->root();
        File::deleteDirectory($this->dir.'/logical-snapshot');
        $old['snapshot_attempt_id'] = self::ATTEMPT;
        $old['state'] = $old['snapshot_state'] = 'snapshot_running';
        $old['phase'] = 'snapshot_export';
        $this->root($old);
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->begin($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 100 && $index['stage'] === 'boundaries'; $n++) {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        // Real schema/query/shard writer; only historical counters are fixture metadata.
        DB::table('parts')->insert(['id' => 1, 'sku' => 'kick-fixture', 'name' => 'Fixture']);
        for ($id = 15641; $id <= 15650; $id++) {
            DB::table('marketplace_listings')->insert(['id' => $id, 'part_id' => 1, 'marketplace' => 'ebay', 'external_offer_id' => (string) $id, 'status' => 'active']);
        }
        $logical = json_decode(file_get_contents($this->checkpoint), true);
        $logical['stage'] = 'export';
        $logical['dataset_index'] = array_search('marketplace_listings', $logical['dataset_names'], true);
        foreach ($logical['datasets'] as &$dataset) {
            $dataset['total'] = $dataset['processed'] = 0;
        }
        unset($dataset);
        $logical['datasets']['parts']['total'] = $logical['datasets']['parts']['processed'] = 34788;
        $logical['datasets']['marketplace_listings'] = array_replace($logical['datasets']['marketplace_listings'], [
            'total' => 45169, 'processed' => 15640, 'cursor' => ['id' => 15640], 'max_key' => ['id' => 15650],
        ]);
        file_put_contents($this->checkpoint, json_encode($logical));
        file_put_contents($this->dir.'/last-tick.json', json_encode(['reason_code' => 'tick_completed', 'records_completed' => 5, 'records_attempted' => 5, 'duration_ms' => 104]));
        foreach (['operational-progress.json', 'compact-snapshot-progress.json'] as $file) {
            @unlink($this->dir.'/'.$file);
        }
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function root(?array $state = null): array
    {
        if ($state !== null) {
            file_put_contents($this->dir.'/state.json', json_encode($state));
        }

        return json_decode(file_get_contents($this->dir.'/state.json'), true);
    }

    private function kick(?string $attempt = null)
    {
        return $this->postJson(self::BASE.self::RUN.'/kick', ['attempt_id' => $attempt ?? self::ATTEMPT]);
    }

    private function boundaries(): array
    {
        $state = json_decode(file_get_contents($this->checkpoint), true);

        return array_map(fn ($d) => array_intersect_key($d, array_flip(['max_key', 'boundary_frozen_at', 'schema_sha256', 'total'])), $state['datasets']);
    }

    public function test_kick_reconstructs_missing_progress_and_continues_exact_checkpoint_without_business_writes_or_duplicate_rows(): void
    {
        $boundaries = $this->boundaries();
        $mutations = [];
        DB::listen(function ($event) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete)/i', $event->sql)) {
                $mutations[] = $event->sql;
            }
        });
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('progress_unavailable', true);
        $first = $this->kick()->assertOk()->assertJsonPath('kick_reason', 'tick_completed')->assertJsonPath('processed', 50433)
            ->assertJsonPath('cursor.id', 15645)->assertJsonPath('run_id', self::RUN)->assertJsonPath('snapshot_attempt_id', self::ATTEMPT);
        $this->assertLessThan(8192, strlen($first->getContent()));
        $this->assertSame($boundaries, $this->boundaries());
        // Simulate lost reply: the browser has stale cursor=15640 but sends no cursor.
        $this->kick()->assertOk()->assertJsonPath('processed', 50438)->assertJsonPath('cursor.id', 15650);
        $index = json_decode(file_get_contents($this->checkpoint), true);
        $rows = [];
        foreach ($index['datasets']['marketplace_listings']['shards'] as $shard) {
            foreach (json_decode(file_get_contents($this->dir.'/'.$shard['path']), true) as $row) {
                $rows[] = $row['id'];
            }
        }
        $this->assertCount(10, $rows);
        $this->assertCount(10, array_unique($rows));
        $progress = json_decode(file_get_contents($this->dir.'/operational-progress.json'), true);
        foreach (['run_id', 'attempt_id', 'state', 'phase', 'current_dataset', 'processed', 'total', 'remaining', 'cursor', 'next_action', 'busy', 'last_success', 'last_tick_reason', 'checkpoint_fingerprint'] as $key) {
            $this->assertArrayHasKey($key, $progress);
        }
        $this->assertSame(50438, $progress['processed']);
        $this->assertSame('tick_completed', $progress['last_tick_reason']);
        $this->assertSame(0600, fileperms($this->dir.'/operational-progress.json') & 0777);
        $this->assertSame([], $mutations);
        Http::assertNothingSent();
    }

    public function test_busy_paused_completed_failed_and_attempt_mismatch_do_not_execute_work(): void
    {
        $before = hash_file('sha256', $this->checkpoint);
        $lock = fopen($this->dir.'/lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->kick()->assertOk()->assertJsonPath('kick_reason', 'busy')->assertJsonPath('busy', true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->kick((string) Str::uuid())->assertConflict()->assertJsonPath('reason_code', 'attempt_mismatch');
        foreach (['snapshot_paused' => 'paused', 'completed' => 'completed', 'snapshot_failed' => 'run_failed', 'snapshot_aborted' => 'run_failed'] as $state => $reason) {
            $root = $this->root();
            $root['state'] = $state;
            $this->root($root);
            $this->kick()->assertOk()->assertJsonPath('kick_reason', $reason);
            $this->assertSame($before, hash_file('sha256', $this->checkpoint));
        }
    }

    public function test_status_never_reads_export_index_even_without_cache_and_broken_checkpoint(): void
    {
        file_put_contents($this->checkpoint, str_repeat('not json private data ', 100000));
        $before = hash_file('sha256', $this->checkpoint);
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('progress_unavailable', true);
        for ($i = 0; $i < 5; $i++) {
            $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('next_action', 'snapshot_tick');
        }
        $this->assertSame($before, hash_file('sha256', $this->checkpoint));
    }

    public function test_kick_keeps_owner_csrf_and_source_context_guards(): void
    {
        $before = hash_file('sha256', $this->checkpoint);
        $this->app->bind(ValidateCsrfToken::class, VerifyMigrationKickCsrf::class);
        $this->kick()->assertStatus(419);
        $this->app->bind(ValidateCsrfToken::class, ValidateCsrfToken::class);
        $this->postJson(self::BASE.self::RUN.'/kick', ['attempt_id' => self::ATTEMPT, 'cursor' => ['id' => 15640]])->assertUnprocessable();
        $root = $this->root();
        $root['snapshot_source_fingerprint'] = 'different';
        $this->root($root);
        $this->kick()->assertConflict()->assertJsonPath('reason_code', 'snapshot_source_context_mismatch');
        $other = User::create(['name' => 'Other', 'email' => 'other-kick@example.test', 'password' => 'password']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other);
        $this->kick()->assertNotFound()->assertJsonPath('reason_code', 'run_owner_mismatch');
        $this->assertSame($before, hash_file('sha256', $this->checkpoint));
        Http::assertNothingSent();
    }
}
