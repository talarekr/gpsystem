<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\AllegroParametersSupplement;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AllegroParametersSupplementTest extends TestCase
{
    use RefreshDatabase;

    private string $temporary;

    private string $run;

    private string $attempt;

    private string $parent;

    private string $supplement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/allegro-supplement-'.Str::uuid();
        config(['migration_export.root' => $this->temporary.'/exports', 'filesystems.disks.public.root' => $this->temporary.'/originals']);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Provider forbidden'));
        DB::table('part_categories')->insert(['id' => 7, 'name' => 'Category']);
        DB::table('cars')->insert(['id' => 8, 'uuid' => (string) Str::uuid(), 'make' => 'Audi', 'model' => 'A4', 'body_type' => 'sedan']);
        DB::table('parts')->insert(['id' => 1, 'name' => 'Part', 'sku' => '0001', 'part_number' => '0000123', 'category_id' => 7, 'car_id' => 8]);
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 7, 'channel' => 'allegro_main', 'external_category_id' => '00123']);
        DB::table('allegro_parameter_selections')->insert(['part_id' => 1, 'allegro_category_id' => '00123', 'parameter_id' => 'manual', 'parameter_name' => 'Choice', 'value_id' => 'v1']);
        DB::table('marketplace_listings')->insert(['id' => 10, 'part_id' => 1, 'marketplace' => 'allegro', 'external_offer_id' => 'offer', 'status' => 'active', 'raw_payload' => json_encode(['category' => ['id' => '00123'], 'parameters' => [['id' => 'old', 'values' => ['legacy']]], 'access_token' => 'DO_NOT_EXPORT', 'customer' => ['email' => 'PRIVATE@example.test']])]);
        DB::table('allegro_category_parameters_cache')->insert(['allegro_category_id' => '00123', 'raw_response' => json_encode(['parameters' => [
            ['id' => 'manual', 'name' => 'Choice', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'v1', 'value' => 'First']], 'options' => ['describesProduct' => false]],
            ['id' => '227345', 'name' => 'Numer katalogowy części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
            ['id' => '129591', 'name' => 'Typ samochodu', 'type' => 'dictionary', 'dictionary' => [['id' => '129591_1', 'value' => 'Samochody osobowe']]],
            ['id' => 'mapped', 'name' => 'Special', 'type' => 'dictionary', 'dictionary' => [['id' => 'v2', 'value' => 'Second']]],
            ['id' => 'missing', 'name' => 'Missing', 'type' => 'string', 'required' => true],
        ]]), 'fetched_at' => '2026-01-01 01:02:03']);
        DB::table('allegro_parameter_mappings')->insert(['local_category_id' => 7, 'allegro_category_id' => '00123', 'parameter_id' => 'mapped', 'fixed_value_id' => 'v2', 'enabled' => true, 'metadata' => json_encode(['dictionary_map' => ['local' => 'v2'], 'api_token' => 'DO_NOT_EXPORT'])]);
        $prepared = app(GpswissMigrationExport::class)->prepareBrowser(7);
        $this->run = $prepared['run_id'];
        $this->attempt = $prepared['snapshot_attempt_id'];
        $this->parent = $this->temporary.'/exports/'.$this->run;
        $this->supplement = $this->temporary.'/exports/.allegro-parameters-supplements/'.$this->run.'/'.$this->attempt;
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->index($this->parent, $this->attempt);
        for ($i = 0; $i < 250 && ! $index['done'] && ! $index['failed']; $i++) {
            $index = $engine->tick($this->parent, $this->attempt);
        }
        $this->assertTrue($index['done'], $index['last_error'] ?? 'capture failed');
        file_put_contents($this->parent.'/photo-copy-in-progress.fixture', 'DO NOT TOUCH');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    private function finish(): array
    {
        $service = app(AllegroParametersSupplement::class);
        $state = $service->start(7, $this->run, $this->attempt);
        for ($i = 0; $i < 250 && ! $state['done']; $i++) {
            $state = app(AllegroParametersSupplement::class)->tick(7, $this->run, $this->attempt);
        }
        $this->assertTrue($state['done'], json_encode($state));

        return $state;
    }

    private function rows(string $dataset): array
    {
        return array_map(fn ($line) => json_decode($line, true), file($this->supplement.'/'.$dataset.'.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    private function parentHashes(): array
    {
        $hashes = [];
        foreach (File::allFiles($this->parent) as $file) {
            $hashes[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }

        return $hashes;
    }

    public function test_full_package_uses_frozen_rows_and_never_writes_business_data_or_calls_providers(): void
    {
        $before = $this->parentHashes();
        DB::table('parts')->where('id', 1)->update(['part_number' => 'LIVE_NEW_VALUE']);
        DB::table('cars')->where('id', 8)->update(['body_type' => 'truck']);
        $business = [];
        foreach (['parts', 'cars', 'marketplace_listings', 'marketplace_sync_logs', 'orders', 'part_images', 'allegro_category_parameters_cache', 'allegro_parameter_mappings'] as $table) {
            $business[$table] = DB::table($table)->get()->toJson();
        }
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $state = $this->finish();
        $this->assertSame($before, $this->parentHashes());
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $query);
            $this->assertDoesNotMatchRegularExpression('/\bfrom\s+["`]?\b(parts|cars|marketplace_listings|orders|part_images|marketplace_sync_logs)\b/i', $query);
        }
        foreach ($business as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson());
        }
        $resolved = collect($this->rows('allegro_resolved_parameters'));
        $this->assertSame(['0000123'], $resolved->firstWhere('parameter_id', '227345')['values']);
        $this->assertSame(['129591_1'], $resolved->firstWhere('parameter_id', '129591')['values_ids']);
        $this->assertSame('historical_only', $resolved->firstWhere('parameter_id', 'old')['completeness_status']);
        $this->assertSame(1, $state['summary']['parts_with_manual_selections']);
        $this->assertSame(1, $state['summary']['historical_only_parameters']);
        $this->assertSame(1, $state['summary']['missing_required_values']);
        $this->assertSame('00123', $this->rows('allegro_category_parameters_cache')[0]['allegro_category_id']);
        $this->assertSame('2026-01-01 01:02:03', $this->rows('allegro_category_parameters_cache')[0]['fetched_at']);
        $this->assertSame(['local' => 'v2'], $this->rows('allegro_parameter_mappings')[0]['metadata']['dictionary_map']);
        $all = implode('', array_map(fn ($file) => file_get_contents($file->getPathname()), File::files($this->supplement)));
        $this->assertStringNotContainsString('DO_NOT_EXPORT', $all);
        $this->assertStringNotContainsString('PRIVATE@example.test', $all);
        Http::assertNothingSent();
    }

    public function test_manifest_checksums_archives_and_idempotent_rerun(): void
    {
        $state = $this->finish();
        $manifest = json_decode(file_get_contents($this->supplement.'/manifest.json'), true);
        $this->assertSame(AllegroParametersSupplement::FORMAT, $manifest['format']);
        $this->assertSame($this->run, $manifest['parent_run_id']);
        $this->assertSame($this->attempt, $manifest['parent_attempt_id']);
        $this->assertFalse($manifest['provider_calls']);
        $this->assertFalse($manifest['business_writes']);
        $this->assertFalse($manifest['automatic_publish']);
        $this->assertFalse($manifest['contains_customer_data']);
        $schema = json_decode(file_get_contents($this->supplement.'/schema.json'), true);
        foreach ($this->rows('allegro_resolved_parameters') as $row) {
            $this->assertSame([], array_diff($schema['required'], array_keys($row)));
            $this->assertSame([], array_diff(array_keys($row), array_keys($schema['properties'])));
        }
        foreach (json_decode(file_get_contents($this->supplement.'/checksums.json'), true) as $entry) {
            $this->assertSame($entry['sha256'], hash_file('sha256', $this->supplement.'/'.$entry['path']));
            $this->assertSame($entry['bytes'], filesize($this->supplement.'/'.$entry['path']));
        }
        $archive = json_decode(file_get_contents($this->supplement.'/archive-manifest.json'), true);
        $this->assertFalse($archive['contains_customer_data']);
        $this->assertNotEmpty($archive['volumes']);
        foreach ($archive['volumes'] as $volume) {
            $this->assertSame($volume['sha256'], hash_file('sha256', $this->supplement.'/'.$volume['path']));
        }
        $digest = hash_file('sha256', $this->supplement.'/allegro_resolved_parameters.jsonl');
        $service = app(AllegroParametersSupplement::class);
        $this->assertSame($state, $service->start(7, $this->run, $this->attempt));
        $this->assertSame($state, $service->tick(7, $this->run, $this->attempt));
        $this->assertSame($digest, hash_file('sha256', $this->supplement.'/allegro_resolved_parameters.jsonl'));
        $this->assertSame($this->supplement.'/manifest.json', $service->download(7, $this->run, $this->attempt, 'manifest'));
    }

    public function test_lost_checkpoint_replays_frozen_local_page_and_rolls_back_uncommitted_rows(): void
    {
        $service = app(AllegroParametersSupplement::class);
        $service->start(7, $this->run, $this->attempt);
        $checkpoint = file_get_contents($this->supplement.'/checkpoint.json');
        $service->tick(7, $this->run, $this->attempt);
        $oldCache = file_get_contents($this->supplement.'/allegro_category_parameters_cache.jsonl');
        file_put_contents($this->supplement.'/checkpoint.json', $checkpoint);
        DB::table('allegro_category_parameters_cache')->update(['raw_response' => '{"parameters":[]}']);
        $service->tick(7, $this->run, $this->attempt);
        $this->assertSame($oldCache, file_get_contents($this->supplement.'/allegro_category_parameters_cache.jsonl'));
        for ($i = 0; $i < 150 && $service->status(7, $this->run, $this->attempt)['phase'] !== 'resolve'; $i++) {
            $service->tick(7, $this->run, $this->attempt);
        }
        $checkpoint = file_get_contents($this->supplement.'/checkpoint.json');
        $service->tick(7, $this->run, $this->attempt);
        $resolved = file_get_contents($this->supplement.'/allegro_resolved_parameters.jsonl');
        file_put_contents($this->supplement.'/checkpoint.json', $checkpoint);
        $service->tick(7, $this->run, $this->attempt);
        $this->assertSame($resolved, file_get_contents($this->supplement.'/allegro_resolved_parameters.jsonl'));
        $this->finish();
        $ids = array_column($this->rows('allegro_resolved_parameters'), 'candidate_id');
        $this->assertCount(count(array_unique($ids)), $ids);
    }

    public function test_parent_boundary_or_attempt_change_is_blocked_without_restart(): void
    {
        $service = app(AllegroParametersSupplement::class);
        $service->start(7, $this->run, $this->attempt);
        $checkpoint = $this->parent.'/logical-snapshot/'.$this->attempt.'/checkpoint.json';
        file_put_contents($checkpoint, file_get_contents($checkpoint).' ');
        $this->expectExceptionMessage('supplement_parent_boundary_changed');
        $service->tick(7, $this->run, $this->attempt);
    }

    public function test_missing_cache_is_reported_and_no_provider_is_used(): void
    {
        DB::table('allegro_category_parameters_cache')->delete();
        $state = $this->finish();
        $this->assertSame(1, $state['summary']['categories_without_local_schema']);
        $this->assertSame('schema_unknown', $this->rows('allegro_resolved_parameters')[0]['completeness_status']);
        $this->assertNull($this->rows('allegro_resolved_parameters')[0]['required_snapshot']);
        $this->assertSame('schema_unavailable_locally', $this->rows('completeness')[0]['reason_code']);
        Http::assertNothingSent();
    }

    public function test_lock_prevents_parallel_ticks(): void
    {
        $service = app(AllegroParametersSupplement::class);
        $before = $service->start(7, $this->run, $this->attempt);
        $lock = fopen($this->supplement.'/lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $service->tick(7, $this->run, $this->attempt);
            $this->fail('Parallel tick must be blocked');
        } catch (MigrationExportException $exception) {
            $this->assertSame('supplement_busy', $exception->reasonCode);
            $this->assertSame($before, $service->status(7, $this->run, $this->attempt));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_corrupt_parent_shard_is_blocked_without_recapture(): void
    {
        $service = app(AllegroParametersSupplement::class);
        $service->start(7, $this->run, $this->attempt);
        $index = json_decode(file_get_contents($this->supplement.'/parent-index.json'), true);
        $path = $this->parent.'/'.$index['datasets']['parts']['shards'][0]['path'];
        file_put_contents($path, '[]');
        $this->expectExceptionMessage('supplement_parent_artifact_invalid');
        for ($i = 0; $i < 20; $i++) {
            $service->tick(7, $this->run, $this->attempt);
        }
    }

    public function test_corrupted_committed_output_fails_integrity_before_download(): void
    {
        $service = app(AllegroParametersSupplement::class);
        $state = $service->start(7, $this->run, $this->attempt);
        for ($i = 0; $i < 150 && $state['phase'] !== 'package'; $i++) {
            $state = $service->tick(7, $this->run, $this->attempt);
        }
        $path = $this->supplement.'/allegro_resolved_parameters.jsonl';
        $bytes = file_get_contents($path);
        file_put_contents($path, str_repeat('x', strlen($bytes)));
        $this->expectExceptionMessage('supplement_artifact_integrity_failed');
        for ($i = 0; $i < 10; $i++) {
            $service->tick(7, $this->run, $this->attempt);
        }
    }

    public function test_orphan_listing_parameters_and_missing_tables_are_preserved_without_inventing_part(): void
    {
        // Add an unlinked historical listing to the already frozen parent artifact fixture.
        $indexPath = $this->parent.'/logical-snapshot/'.$this->attempt.'/checkpoint.json';
        $index = json_decode(file_get_contents($indexPath), true);
        $shard = &$index['datasets']['marketplace_listings']['shards'][0];
        $path = $this->parent.'/'.$shard['path'];
        $rows = json_decode(file_get_contents($path), true);
        $rows[0]['part_id'] = null;
        $bytes = json_encode($rows, JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $bytes);
        $shard['sha256'] = hash('sha256', $bytes);
        file_put_contents($indexPath, json_encode($index));
        Schema::drop('allegro_category_parameters_cache');
        Schema::drop('allegro_parameter_mappings');
        $state = $this->finish();
        $this->assertSame(1, $state['summary']['orphan_listing_parameter_rows']);
        $row = collect($this->rows('allegro_resolved_parameters'))->firstWhere('source', 'marketplace_listing_payload');
        $this->assertNull($row['part_id']);
        $this->assertSame('schema_unknown', $row['completeness_status']);
        $manifest = json_decode(file_get_contents($this->supplement.'/manifest.json'), true);
        $this->assertFalse($manifest['local_table_boundaries']['allegro_parameter_mappings']['available']);
    }

    public function test_http_endpoint_is_owner_only_and_downloads_are_private(): void
    {
        $user = User::query()->forceCreate(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test-password']);
        Role::findOrCreate(UserRole::OwnerAdmin->value);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $url = route('admin.tools.gpswiss-migration-export.allegro-parameters-supplement', ['run' => $this->run]);
        $this->actingAs($user)->postJson($url, ['parent_attempt_id' => $this->attempt, 'action' => 'start'])->assertStatus(409)->assertJsonPath('reason_code', 'supplement_schema_refresh_required');
        $this->finish();
        $response = $this->get($url.'?parent_attempt_id='.$this->attempt.'&download=manifest')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->postJson($url, ['parent_attempt_id' => (string) Str::uuid(), 'action' => 'tick'])->assertStatus(409)->assertJsonPath('reason_code', 'supplement_parent_attempt_mismatch');
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'test-password']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other)->getJson($url.'?parent_attempt_id='.$this->attempt)->assertNotFound();
        $other->removeRole(UserRole::OwnerAdmin->value);
        $this->getJson($url.'?parent_attempt_id='.$this->attempt)->assertForbidden();
    }
}
