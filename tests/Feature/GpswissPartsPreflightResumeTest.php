<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\CompactSnapshotProgress;
use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\GpswissSnapshot;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Recorded production control metadata, with local fixture rows/files only. */
class GpswissPartsPreflightResumeTest extends TestCase
{
    use RefreshDatabase;

    private const RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba';

    private const ATTEMPT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c';

    private const BOUNDARY = 'a42db372-cf85-4f46-99e7-ccacce2bc6c3';

    private const BASE = '/admin/tools/gpswiss-migration-export/';

    private string $tmp;

    private string $dir;

    private string $file;

    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/preflight-resume-'.Str::uuid();
        $this->dir = $this->tmp.'/'.self::RUN;
        $this->file = $this->dir.'/logical-snapshot/'.self::ATTEMPT.'/checkpoint.json';
        config(['migration_export.root' => $this->tmp, 'migration_export.browser_snapshot_rows' => 5]);
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Owner', 'email' => 'preflight-resume@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->owner = $user->id;
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(GpswissMigrationExport::class)->prepareBrowser($this->owner, self::RUN);
        File::deleteDirectory($this->dir.'/logical-snapshot');
        $root = json_decode(file_get_contents($this->dir.'/state.json'), true);
        $root['snapshot_attempt_id'] = self::ATTEMPT;
        $root['state'] = $root['snapshot_state'] = $root['phase'] = 'snapshot_failed';
        $root['last_error'] = 'snapshot_row_updated_after_cutoff';
        file_put_contents($this->dir.'/state.json', json_encode($root));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function state(?array $value = null): array
    {
        if ($value !== null) {
            file_put_contents($this->file, json_encode($value));
        }

        return json_decode(file_get_contents($this->file), true);
    }

    private function resume()
    {
        return $this->postJson(self::BASE.self::RUN, ['action' => 'resume', 'expected_snapshot_attempt' => self::ATTEMPT]);
    }

    private function tick(): array
    {
        return app(GpswissMigrationExport::class)->browserSnapshotTick($this->owner, self::RUN, self::ATTEMPT);
    }

    private function fixture(int $shards = 2, bool $productionCounters = false): array
    {
        $ids = $shards === 1296 ? [...range(1, 2591), 8926] : [2262, 2263, 8925, 8926];
        foreach (array_chunk($ids, 100) as $batch) {
            DB::table('parts')->insert(array_map(fn ($id) => ['id' => $id, 'sku' => '000-'.$id, 'name' => 'Fixture '.$id,
                'quantity' => 1, 'status' => 'ready', 'needs_listing' => true, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2026-11-06 15:00:00'], $batch));
        }
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->begin($this->dir, self::ATTEMPT);
        while ($index['stage'] === 'boundaries') {
            $index = $engine->tick($this->dir, self::ATTEMPT);
        }
        $state = $this->state();
        $source = app(GpswissSnapshot::class);
        $csv = new \ReflectionMethod($engine, 'csv');
        $parts = array_map(fn ($r) => $source->browserNormalize((array) $r, $state['datasets']['parts']), DB::table('parts')->orderBy('id')->get()->all());
        foreach (['parts', 'part_car_relations'] as $name) {
            $definition = &$state['datasets'][$name];
            foreach (array_keys($definition) as $key) {
                if (str_starts_with($key, 'mutable_') || in_array($key, ['consistency_mode', 'mutable_contract', 'dataset_generation', 'dataset_boundary_id', 'dataset_cutoff_database_time', 'previous_boundary_id', 'boundary_refreshed_at', 'recovery_reason'])) {
                    unset($definition[$key]);
                }
            }
            $definition['processed'] = $definition['total'] = count($parts);
            $definition['cursor'] = ['id' => 8926];
            $definition['verified'] = 0;
            $definition['shards'] = [];
            $definition['checksum_partial'] = hash('sha256', $name);
            $definition['sold_count'] = 0;
            $definition['to_list_count'] = count($parts);
            $definition['status_counts'] = ['ready' => count($parts)];
            $folder = $this->dir.'/logical-snapshot/'.self::ATTEMPT.'/datasets/'.$name;
            File::ensureDirectoryExists($folder, 0700);
            foreach (array_chunk($parts, 2) as $position => $batch) {
                $rows = $name === 'parts' ? $batch : array_map(fn ($p) => ['id' => $p['id'], 'part_id' => $p['id'], 'car_id' => $p['car_id']], $batch);
                $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $columns = array_keys($rows[0]);
                $csvBytes = $csv->invoke($engine, $rows, $columns);
                $path = 'logical-snapshot/'.self::ATTEMPT.'/datasets/'.$name.'/'.$position;
                file_put_contents($this->dir.'/'.$path.'.json', $json);
                file_put_contents($this->dir.'/'.$path.'.csv', $csvBytes);
                $sha = hash('sha256', $json);
                $definition['shards'][] = ['path' => $path.'.json', 'csv_path' => $path.'.csv', 'sha256' => $sha, 'csv_sha256' => hash('sha256', $csvBytes), 'rows' => count($rows), 'bytes' => strlen($json), 'csv_bytes' => strlen($csvBytes), 'cursor_end' => ['id' => (int) $rows[count($rows) - 1]['id']]];
                $definition['checksum_partial'] = hash('sha256', $definition['checksum_partial'].$sha);
                $definition['csv_columns'] = $columns;
            }
        }
        unset($definition);
        if ($productionCounters) {
            $state['datasets']['part_translations']['processed'] = $state['datasets']['part_translations']['total'] = 79957 - count($parts) * 2;
        }
        $state['cutoff_database_time'] = '2026-11-06 15:17:19.925197';
        $state['stage'] = 'recovery_preflight';
        $state['dataset_index'] = 0;
        $state['last_error'] = null;
        $state['dataset_recovery'] = ['dataset' => 'parts', 'previous_generation' => 0, 'new_generation' => 1, 'previous_boundary_id' => 'legacy', 'dataset_boundary_id' => self::BOUNDARY,
            'recovery_reason' => 'snapshot_row_updated_after_cutoff', 'preflight_shard' => 0, 'preflight_rows' => 0, 'preflight_chain' => hash('sha256', 'parts'),
            'preflight_total_shards' => $shards, 'return_stage' => 'verify', 'strategy' => 'adopt_complete_shards_then_reconcile', 'started_at' => '2026-11-07T09:33:57+00:00'];
        $state['source_conflict'] = ['dataset' => 'parts', 'record_id' => '2262', 'timestamp_field' => 'updated_at', 'record_updated_at' => '2026-11-06 19:20:03',
            'cutoff_database_time' => $state['cutoff_database_time'], 'dataset_boundary_frozen_at' => '2026-11-06T14:17:20+00:00', 'historical_version_available' => false, 'mutation_origin' => 'not_proven_by_timestamp'];
        $this->state($state);
        app(CompactSnapshotProgress::class)->publish($this->dir, self::ATTEMPT, $state);
        DB::table('parts')->where('id', 2262)->update(['quantity' => 0, 'status' => 'sold', 'needs_listing' => false, 'updated_at' => '2026-11-06 19:20:03']);
        file_put_contents($this->dir.'/last-tick.json', json_encode(['action' => 'snapshot_tick', 'reason_code' => 'snapshot_row_updated_after_cutoff', 'started_at' => 1794006808.398781, 'source_conflict' => $state['source_conflict']]));

        return $state;
    }

    public function test_committed_recovery_intent_with_stale_failed_root_is_resumable_without_generation_two(): void
    {
        $before = $this->fixture();
        // #1518 projections predate snapshot_stage; retain their safe resume path too.
        $cache = json_decode(file_get_contents($this->dir.'/compact-snapshot-progress.json'), true);
        unset($cache['progress']['snapshot_stage']);
        file_put_contents($this->dir.'/compact-snapshot-progress.json', json_encode($cache));
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('last_error', null)
            ->assertJsonPath('next_action', 'resume')->assertJsonPath('resume_allowed', true)->assertJsonPath('snapshot_retry_allowed', false);
        $this->getJson(self::BASE.self::RUN.'?format=dataset-conflict')->assertOk()->assertJsonPath('evidence', 'recorded_at_failure')
            ->assertJsonPath('conflict.record_id', '2262');
        $this->resume()->assertOk()->assertJsonPath('state', 'snapshot_running')->assertJsonPath('phase', 'snapshot_recovery_preflight');
        $this->assertSame($before['dataset_recovery'], $this->state()['dataset_recovery']);
        $this->resume()->assertOk();
        $this->assertSame(self::BOUNDARY, $this->state()['dataset_recovery']['dataset_boundary_id']);
        $this->tick();
        $this->assertSame('verify', $this->state()['stage']);
        $this->tick();
        $definition = $this->state()['datasets']['parts'];
        $this->assertSame(1, $definition['mutable_dirty_rows']);
        $this->assertSame(self::BOUNDARY, $definition['dataset_boundary_id']);
        $this->assertNotSame($before['datasets']['parts']['shards'][0]['path'], $definition['shards'][0]['path']);
        $this->assertSame($before['datasets']['parts']['shards'][1]['path'], $definition['shards'][1]['path']);
    }

    public function test_kick_reuses_pending_intent_even_when_stale_failed_root_has_no_error(): void
    {
        $before = $this->fixture();
        $root = json_decode(file_get_contents($this->dir.'/state.json'), true);
        $root['last_error'] = null;
        file_put_contents($this->dir.'/state.json', json_encode($root));
        $this->postJson(self::BASE.self::RUN.'/kick', ['attempt_id' => self::ATTEMPT])->assertOk()
            ->assertJsonPath('kick_reason', 'recovery_started')->assertJsonPath('state', 'snapshot_running');
        $this->assertSame($before['dataset_recovery'], $this->state()['dataset_recovery']);
        $this->postJson(self::BASE.self::RUN.'/kick', ['attempt_id' => self::ATTEMPT])->assertOk()
            ->assertJsonPath('kick_reason', 'tick_completed');
        $this->assertSame('verify', $this->state()['stage']);
        $this->assertSame(self::BOUNDARY, $this->state()['datasets']['parts']['dataset_boundary_id']);
        Http::assertNothingSent();
    }

    public function test_root_corruption_is_not_hidden_by_a_healthy_pending_progress_projection(): void
    {
        $this->fixture();
        $root = json_decode(file_get_contents($this->dir.'/state.json'), true);
        $root['last_error'] = 'snapshot_shard_checksum_mismatch';
        file_put_contents($this->dir.'/state.json', json_encode($root));
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('next_action', 'blocked')
            ->assertJsonPath('dataset_recovery_allowed', false)->assertJsonPath('blocked_reason', 'snapshot_shard_checksum_mismatch');
        $this->resume()->assertStatus(409);
        $this->assertSame(self::BOUNDARY, $this->state()['dataset_recovery']['dataset_boundary_id']);
    }

    public function test_exact_1296_shards_preflight_does_not_read_live_part_2262_and_reconciles_it_after_adoption(): void
    {
        $before = $this->fixture(1296, true);
        $queries = [];
        $writes = [];
        DB::listen(function ($e) use (&$queries, &$writes) {
            if (str_contains($e->sql, 'snapshot_row_bytes')) {
                $queries[] = $e->sql;
            }if (preg_match('/^\\s*(insert|update|delete)/i', $e->sql)) {
                $writes[] = $e->sql;
            }
        });
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('processed', 79957)->assertJsonPath('total', 79957)->assertJsonPath('cursor.id', 8926);
        $this->resume()->assertOk();
        for ($n = 0; $n < 270 && $this->state()['stage'] === 'recovery_preflight'; $n++) {
            $this->tick();
        }
        $this->assertSame('verify', $this->state()['stage']);
        $this->assertSame([], $queries);
        $this->assertSame($before['datasets']['parts']['shards'], $this->state()['datasets']['parts']['shards']);
        $trace = json_decode(file_get_contents($this->dir.'/last-tick.json'), true);
        $this->assertSame('artifact_preflight', $trace['action']);
        $index = app(BrowserMigrationSnapshot::class)->index($this->dir, self::ATTEMPT);
        for ($n = 0; $n < 1320 && $index['current_dataset'] === 'parts' && ! $index['failed']; $n++) {
            $this->tick();
            $index = app(BrowserMigrationSnapshot::class)->index($this->dir, self::ATTEMPT);
        }
        $this->assertFalse($index['failed'], $index['last_error'] ?? '');
        $definition = $index['datasets']['parts'];
        $this->assertTrue($definition['done']);
        $this->assertSame(1, $definition['mutable_dirty_rows']);
        $this->assertSame(1, $definition['mutable_dirty_shards']);
        $this->assertSame(1295, $definition['mutable_reused_shards']);
        $this->assertSame(1, $definition['dataset_generation']);
        $this->assertSame(self::BOUNDARY, $definition['dataset_boundary_id']);
        $this->assertSame([], $writes);
        Http::assertNothingSent();
    }

    public function test_failed_preflight_with_saved_intent_continues_generation_one_and_finishes_pipeline(): void
    {
        $before = $this->fixture();
        $state = $before;
        $state['stage'] = 'failed';
        $state['last_error'] = 'snapshot_row_updated_after_cutoff';
        $this->state($state);
        app(CompactSnapshotProgress::class)->publish($this->dir, self::ATTEMPT, $state);
        $this->resume()->assertOk()->assertJsonPath('state', 'snapshot_running');
        $this->assertSame($before['dataset_recovery'], $this->state()['dataset_recovery']);
        $export = app(GpswissMigrationExport::class);
        $status = $export->status($this->owner, self::RUN);
        for ($n = 0; $n < 1000 && $status['state'] !== 'completed' && $status['next_action'] !== 'blocked'; $n++) {
            $status = $status['next_action'] === 'snapshot_tick' ? $this->tick() : $export->tick($this->owner, self::RUN);
        }
        $this->assertSame('completed', $status['state'], $status['last_error'] ?? '');
        $state = $this->state();
        $this->assertCount(1, $state['dataset_boundary_history']);
        $this->assertSame(self::BOUNDARY, $state['dataset_boundary_history'][0]['dataset_boundary_id']);
        $rows = iterator_to_array(app(BrowserMigrationSnapshot::class)->rows($this->dir, 'parts', self::ATTEMPT));
        $this->assertCount(4, $rows);
        $this->assertCount(4, array_unique(array_column($rows, 'id')));
        $part = array_values(array_filter($rows, fn ($r) => (int) $r['id'] === 2262))[0];
        $this->assertSame('sold', $part['status']);
        $this->assertSame('0', (string) $part['quantity']);
        Http::assertNothingSent();
    }

    public function test_partial_preflight_resume_preserves_its_committed_offset_and_chain(): void
    {
        $state = $this->fixture();
        $first = $state['datasets']['parts']['shards'][0];
        $state['dataset_recovery']['preflight_shard'] = 1;
        $state['dataset_recovery']['preflight_rows'] = $first['rows'];
        $state['dataset_recovery']['preflight_chain'] = hash('sha256', hash('sha256', 'parts').$first['sha256']);
        $this->state($state);
        $intent = $state['dataset_recovery'];
        $this->resume()->assertOk();
        $this->assertSame($intent, $this->state()['dataset_recovery']);
        $this->tick();
        $this->assertSame('verify', $this->state()['stage']);
        $trace = json_decode(file_get_contents($this->dir.'/last-tick.json'), true);
        $this->assertSame(1, $trace['preflight_shard_before']);
        $this->assertSame(2, $trace['preflight_shard_after']);
        $this->assertSame(0, $trace['records_attempted']);
        $this->assertSame(1, $trace['artifacts_checked']);
    }

    public static function corruptArtifacts(): array
    {
        return [
            'checksum' => ['checksum', 'snapshot_shard_checksum_mismatch'],
            'missing' => ['missing', 'snapshot_shard_checksum_mismatch'],
            'truncated json even with matching checksum' => ['json', 'snapshot_shard_format_invalid'],
            'csv format' => ['csv', 'snapshot_shard_format_invalid'],
            'row count' => ['rows', 'snapshot_shard_row_count_mismatch'],
            'size' => ['size', 'snapshot_shard_size_mismatch'],
            'chain' => ['chain', 'snapshot_shard_checksum_mismatch'],
            'different attempt shard' => ['path', 'snapshot_shard_identity_mismatch'],
        ];
    }

    #[DataProvider('corruptArtifacts')]
    public function test_artifact_damage_blocks_instead_of_becoming_dirty(string $damage, string $reason): void
    {
        $state = $this->fixture();
        $definition = &$state['datasets']['parts'];
        $shard = &$definition['shards'][0];
        if ($damage === 'missing') {
            unlink($this->dir.'/'.$shard['path']);
        } elseif ($damage === 'checksum') {
            file_put_contents($this->dir.'/'.$shard['path'], '[');
        } elseif (in_array($damage, ['json', 'csv'], true)) {
            $key = $damage === 'json' ? 'path' : 'csv_path';
            $hash = $damage === 'json' ? 'sha256' : 'csv_sha256';
            $size = $damage === 'json' ? 'bytes' : 'csv_bytes';
            $bytes = $damage === 'json' ? '[' : "wrong;header\n";
            file_put_contents($this->dir.'/'.$shard[$key], $bytes);
            $shard[$hash] = hash('sha256', $bytes);
            $shard[$size] = strlen($bytes);
        } elseif ($damage === 'rows') {
            $shard['rows']++;
        } elseif ($damage === 'size') {
            $shard['bytes']++;
        } elseif ($damage === 'chain') {
            $definition['checksum_partial'] = str_repeat('0', 64);
        } elseif ($damage === 'path') {
            $shard['path'] = str_replace(self::ATTEMPT, (string) Str::uuid(), $shard['path']);
        }
        unset($shard,$definition);
        $this->state($state);
        $this->resume()->assertOk();
        $status = $this->tick();
        $this->assertSame('snapshot_failed', $status['state']);
        $this->assertSame($reason, $status['last_error']);
        $this->assertSame(self::BOUNDARY, $this->state()['dataset_recovery']['dataset_boundary_id']);
        $this->resume()->assertStatus(409);
        $this->assertArrayNotHasKey('dataset_boundary_history', $this->state());
        Http::assertNothingSent();
    }

    public static function invalidIntent(): array
    {
        return [['schema', 'snapshot_schema_changed'], ['attempt', 'stale_snapshot_attempt'], ['source', 'dataset_recovery_intent_invalid'],
            ['generation', 'dataset_recovery_intent_invalid'], ['corruption', 'dataset_recovery_not_allowed']];
    }

    #[DataProvider('invalidIntent')]
    public function test_resume_rejects_mismatched_identity_or_nonwhitelisted_failure(string $damage, string $reason): void
    {
        $state = $this->fixture();
        if ($damage === 'schema') {
            $state['datasets']['parts']['schema_sha256'] = str_repeat('0', 64);
        } elseif ($damage === 'attempt') {
            $state['attempt_id'] = (string) Str::uuid();
        } elseif ($damage === 'source') {
            $state['datasets']['parts']['source_table'] = 'orders';
        } elseif ($damage === 'generation') {
            $state['dataset_recovery']['new_generation'] = 2;
        } else {
            $state['stage'] = 'failed';
            $state['last_error'] = 'snapshot_shard_checksum_mismatch';
        }
        $this->state($state);
        $before = file_get_contents($this->file);
        $this->resume()->assertStatus(409)->assertJsonPath('reason_code', $reason);
        $this->assertSame($before, file_get_contents($this->file));
        Http::assertNothingSent();
    }
}
