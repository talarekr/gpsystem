<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\CompactSnapshotProgress;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GpswissCompactStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    private string $dir;

    private string $attempt = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c';

    private string $run = '8de6e27c-ad7c-470b-830b-b9ecee9446ba';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/compact-'.Str::uuid();
        $this->dir = $this->tmp.'/'.$this->run;
        mkdir($this->dir.'/logical-snapshot/'.$this->attempt, 0700, true);
        config(['migration_export.root' => $this->tmp]);
        Http::preventStrayRequests();
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Owner', 'email' => 'compact@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        file_put_contents($this->dir.'/state.json', json_encode(['owner_id' => $user->id, 'run_id' => $this->run, 'snapshot_attempt_id' => $this->attempt,
            'schema_version' => 'gpswiss-wystawczesc-3', 'snapshot_mode' => 'browser', 'state' => 'snapshot_running', 'snapshot_state' => 'snapshot_running',
            'phase' => 'snapshot_export', 'processed_images' => 0, 'last_error' => null]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function checkpoint(int $processed, int $shards): string
    {
        $path = $this->dir.'/logical-snapshot/'.$this->attempt.'/checkpoint.json';
        file_put_contents($path.'.pending', json_encode(['stage' => 'export', 'dataset_names' => ['parts', 'marketplace_category_mappings'], 'dataset_index' => 1,
            'last_success' => '2026-11-06T13:00:00Z', 'last_error' => null, 'datasets' => [
                'parts' => ['processed' => 0, 'total' => 0, 'verified' => 0, 'shards' => array_fill(0, $shards, ['private' => 'SECRET } ] \\\" '.str_repeat('large', 100)])],
                'marketplace_category_mappings' => ['processed' => $processed, 'total' => 79957, 'verified' => 0, 'cursor' => ['id' => 2777], 'shards' => []]]], JSON_THROW_ON_ERROR));

        rename($path.'.pending', $path);
        app(CompactSnapshotProgress::class)->publish($this->dir, $this->attempt, json_decode(file_get_contents($path), true));

        return $path;
    }

    public function test_status_stays_small_at_zero_20k_and_79k_and_never_changes_snapshot(): void
    {
        $sizes = $operationalSizes = [];
        $mutations = [];
        DB::listen(function ($e) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete)/i', $e->sql)) {
                $mutations[] = $e->sql;
            }
        });
        foreach ([0, 20568, 79000] as $n) {
            $path = $this->checkpoint($n, $n === 0 ? 0 : 20000);
            $before = hash_file('sha256', $path);
            $root = hash_file('sha256', $this->dir.'/state.json');
            $response = $this->getJson('/admin/tools/gpswiss-migration-export/'.$this->run.'?format=compact')->assertOk();
            $data = $response->json();
            $sizes[] = strlen($response->getContent());
            $this->assertSame($n, $data['processed']);
            $this->assertSame($this->attempt, $data['snapshot_attempt_id']);
            $this->assertSame(['id' => 2777], $data['cursor']);
            $this->assertSame('snapshot_tick', $data['next_action']);
            $this->assertSame(strlen($response->getContent()), $data['status_response_bytes']);
            $this->assertLessThan(8192, strlen($response->getContent()));
            foreach (['datasets', 'snapshot_progress', 'photo_results', 'reports', 'source_counts', 'download_artifacts'] as $key) {
                $this->assertArrayNotHasKey($key, $data);
            }
            $this->assertStringNotContainsString('SECRET', $response->getContent());
            $this->assertSame($before, hash_file('sha256', $path));
            $this->assertSame($root, hash_file('sha256', $this->dir.'/state.json'));
            $this->assertFileExists($this->dir.'/compact-snapshot-progress.json');
            $operationalSizes[] = filesize($this->dir.'/operational-progress.json');
            $this->assertLessThan(8192, end($operationalSizes));
            $again = $this->getJson('/admin/tools/gpswiss-migration-export/'.$this->run.'?format=compact')->assertOk();
            $this->assertSame($n, $again->json('processed'));
        }
        $this->assertLessThan(100, max($sizes) - min($sizes));
        $this->assertLessThan(100, max($operationalSizes) - min($operationalSizes));
        $this->assertSame([], $mutations);
        Http::assertNothingSent();
    }

    public function test_completed_status_keeps_archive_list_separate_and_private(): void
    {
        $path = $this->dir.'/state.json';
        $state = json_decode(file_get_contents($path), true);
        $state['state'] = $state['phase'] = 'completed';
        $state['snapshot_state'] = 'snapshot_completed';
        $state['download_artifacts'] = array_fill(0, 1000, ['key' => 'volume-000001', 'name' => 'private.zip']);
        file_put_contents($path, json_encode($state));
        $this->getJson('/admin/tools/gpswiss-migration-export/'.$this->run.'?format=compact')->assertOk()
            ->assertJsonPath('next_action', 'download')->assertJsonMissingPath('download_artifacts');
        $response = $this->getJson('/admin/tools/gpswiss-migration-export/'.$this->run.'?format=artifacts')->assertOk();
        $this->assertCount(1000, $response->json('download_artifacts'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $other = User::create(['name' => 'Other', 'email' => 'other-compact@example.test', 'password' => 'password']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other);
        $this->getJson('/admin/tools/gpswiss-migration-export/'.$this->run.'?format=compact')->assertNotFound()
            ->assertJsonPath('reason_code', 'run_owner_mismatch');
        $this->getJson('/admin/tools/gpswiss-migration-export/'.$this->run.'?format=artifacts')->assertNotFound();
    }

    public function test_stale_projection_never_falls_back_to_scanning_the_checkpoint(): void
    {
        $path = $this->checkpoint(20568, 20000);
        $reader = app(CompactSnapshotProgress::class);
        $this->assertSame(20568, $reader->read($this->dir, $this->attempt)['processed']);
        $next = json_decode(file_get_contents($path), true);
        $next['datasets']['marketplace_category_mappings']['processed'] = 20573;
        file_put_contents($path.'.next', json_encode($next));
        rename($path.'.next', $path);
        $this->assertNull($reader->read($this->dir, $this->attempt));
        $this->getJson('/admin/tools/gpswiss-migration-export/'.$this->run.'?format=compact')->assertOk()
            ->assertJsonPath('progress_unavailable', true)->assertJsonPath('processed', null)->assertJsonPath('next_action', 'snapshot_tick');
        app(CompactSnapshotProgress::class)->publish($this->dir, $this->attempt, $next);
        // A tick always also commits root state; simulate that atomic operation here.
        unlink($this->dir.'/operational-progress.json');
        $this->getJson('/admin/tools/gpswiss-migration-export/'.$this->run.'?format=compact')->assertOk()->assertJsonPath('processed', 20573);
    }
}
