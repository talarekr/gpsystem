<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\ConsolidatedMigrationCsv;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsolidatedMigrationCsvTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $parent;

    private string $dir;

    private string $run;

    private string $attempt;

    private array $datasets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/consolidated-csv-'.Str::uuid();
        $this->run = (string) Str::uuid();
        $this->attempt = (string) Str::uuid();
        $this->parent = $this->root.'/'.$this->run;
        $this->dir = $this->parent.'/consolidated-csv/'.$this->attempt;
        mkdir($this->parent.'/data-only/'.$this->attempt, 0700, true);
        config(['migration_export.root' => $this->root]);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Provider calls forbidden'));
        $this->write($this->parent.'/owner.json', ['owner_id' => 7, 'run_id' => $this->run]);
        $this->write($this->parent.'/state.json', ['owner_id' => 7, 'run_id' => $this->run, 'snapshot_mode' => 'browser', 'snapshot_attempt_id' => $this->attempt, 'state' => 'photos', 'phase' => 'photo_copy']);
        file_put_contents($this->parent.'/photo-checkpoint.json', '{"processed":0}');
        $this->datasets = [
            'parts' => [
                ['id' => '0001', 'sku' => '000SKU', 'status' => 'sold', 'needs_listing' => false, 'stock' => 9, 'name' => 'Łącznik; "duży"', 'description' => "Dwa\nwiersze", 'legacy_payload' => ['parametry' => ['OE' => '000099']], 'vehicle_snapshot' => null, 'review_metadata' => [], 'code_photo_path' => null, 'main_photo_path' => null, 'allegro_shipping_rate_name' => 'KURIER DPD', 'price' => '0.00', 'formula' => '=HYPERLINK("https://invalid")', 'slash' => '\\N'],
                ['id' => '0002', 'sku' => '002', 'status' => 'draft', 'needs_listing' => true, 'stock' => 0, 'code_photo_path' => 'parts/0002/code.jpg'],
                ['id' => '0003', 'sku' => '003', 'status' => 'ready', 'needs_listing' => false, 'stock' => 0, 'code_photo_path' => 'parts/0003/shared.jpg'],
                ['id' => '0004', 'sku' => '004', 'status' => 'archived', 'needs_listing' => 1, 'stock' => 0],
                ['id' => '0005', 'sku' => '005', 'status' => 'ready', 'needs_listing' => 'false', 'stock' => 4, 'main_photo_path' => 'parts/0005/five.jpg'],
            ],
            'cars' => [['id' => '09', 'ovoko_car_id' => '00099', 'name' => 'Żółty', 'legacy_payload' => ['year' => '2001']], ['id' => '10', 'name' => 'No parts'], ['id' => '11', 'status' => 'archived']],
            'part_images' => [
                ['id' => '001', 'part_id' => '0001', 'path' => 'parts/0001/a.jpg', 'original_name' => 'oryginał.jpg', 'sort_order' => 2, 'is_primary' => true, 'mime_type' => 'image/jpeg', 'extension' => 'jpg'],
                ['id' => '001', 'part_id' => '0001', 'path' => 'parts/0001/a.jpg', 'original_name' => 'oryginał.jpg', 'sort_order' => 2, 'is_primary' => true, 'mime_type' => 'image/jpeg', 'extension' => 'jpg'],
                ['id' => '002', 'part_id' => '0001', 'path' => 'parts/0001/b.jpg', 'sort_order' => 0, 'is_primary' => false, 'source_type' => 'allegro'],
                ['id' => '003', 'part_id' => '0003', 'path' => 'parts/0003/shared.jpg', 'sort_order' => 1, 'is_primary' => true, 'role' => 'primary'],
                ['id' => '005', 'part_id' => '0005', 'path' => 'parts/0005/five.jpg', 'sort_order' => 3, 'is_primary' => false],
                ['id' => '006', 'part_id' => 'missing', 'path' => 'orphan.jpg'],
                ['id' => '007', 'part_id' => null, 'path' => 'unknown.jpg'],
                ['id' => '008', 'part_id' => '0001', 'path' => null],
            ],
        ];
        $this->freeze();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function write(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function freeze(int $shardSize = 2): void
    {
        $definitions = [];
        foreach ($this->datasets as $name => $rows) {
            $columns = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
            $definition = ['name' => $name, 'csv_columns' => $columns, 'exported_columns' => $columns, 'total' => count($rows), 'processed' => count($rows), 'shards' => []];
            foreach (array_chunk($rows, $shardSize) as $i => $chunk) {
                $relative = 'logical-snapshot/'.$this->attempt.'/datasets/'.$name.'/'.sprintf('%06d', $i).'.json';
                File::ensureDirectoryExists(dirname($this->parent.'/'.$relative));
                $this->write($this->parent.'/'.$relative, $chunk);
                $definition['shards'][] = ['path' => $relative, 'rows' => count($chunk), 'sha256' => hash_file('sha256', $this->parent.'/'.$relative)];
            }
            $definitions[$name] = $definition;
        }
        $index = ['done' => true, 'failed' => false, 'attempt_id' => $this->attempt, 'datasets' => $definitions];
        $path = $this->parent.'/data-only/'.$this->attempt.'/parent-index.json';
        $this->write($path, $index);
        $this->write($this->parent.'/data-only/'.$this->attempt.'/checkpoint.json', ['done' => true, 'frozen_index_sha256' => hash_file('sha256', $path)]);
    }

    private function service(): ConsolidatedMigrationCsv
    {
        return app(ConsolidatedMigrationCsv::class);
    }

    private function finish(): array
    {
        $status = $this->service()->start(7, $this->run, $this->attempt);
        for ($n = 0; $n < 2000 && ! $status['done']; $n++) {
            $status = $this->service()->tick(7, $this->run, $this->attempt);
        }
        $this->assertTrue($status['done']);

        return $status;
    }

    private function csv(string $name): array
    {
        $stream = fopen($this->service()->download(7, $this->run, $this->attempt, $name)['path'], 'rb');
        $this->assertSame("\xEF\xBB\xBF", fread($stream, 3));
        $columns = fgetcsv($stream, 0, ';', '"', '');
        $rows = [];
        while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            $rows[] = array_combine($columns, $row);
        }
        fclose($stream);

        return $rows;
    }

    private function frozenHashes(): array
    {
        $files = [];
        foreach (File::allFiles($this->parent) as $file) {
            if (! str_starts_with($file->getRelativePathname(), 'consolidated-csv/')) {
                $files[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
            }
        }

        return $files;
    }

    public function test_all_fields_filters_csv_contract_and_zero_sql_or_providers(): void
    {
        $before = $this->frozenHashes();
        DB::connection()->beforeExecuting(fn () => throw new \LogicException('ALL SQL forbidden during generation'));
        $result = $this->finish();
        $counts = array_column($result['downloads'], 'rows', 'name');
        $this->assertSame(['czesci.csv' => 5, 'sprzedane.csv' => 1, 'do_wystawienia.csv' => 2, 'moje_samochody.csv' => 3, 'zdjecia_czesci.csv' => 5, 'zdjecia_czesci_issues.csv' => 3], $counts);
        $parts = $this->csv('czesci.csv');
        $this->assertSame(array_keys($parts[0]), array_keys($this->csv('sprzedane.csv')[0]));
        $this->assertSame(array_keys($parts[0]), array_keys($this->csv('do_wystawienia.csv')[0]));
        $this->assertEqualsCanonicalizing(array_keys($this->datasets['parts'][0]), array_keys($parts[0]));
        $this->assertSame('000SKU', $parts[0]['sku']);
        $this->assertSame('0001', $parts[0]['id']);
        $this->assertSame('Łącznik; "duży"', $parts[0]['name']);
        $this->assertSame("Dwa\nwiersze", $parts[0]['description']);
        $this->assertSame(['parametry' => ['OE' => '000099']], json_decode($parts[0]['legacy_payload'], true));
        $this->assertSame('\\N', $parts[0]['vehicle_snapshot']);
        $this->assertSame('\\\\N', $parts[0]['slash']);
        $this->assertSame('0.00', $parts[0]['price']);
        $this->assertStringStartsWith("'=", $parts[0]['formula']);
        $this->assertSame(['0001'], array_column($this->csv('sprzedane.csv'), 'id'));
        $this->assertSame(['0002', '0004'], array_column($this->csv('do_wystawienia.csv'), 'id'));
        $this->assertSame(['09', '10', '11'], array_column($this->csv('moje_samochody.csv'), 'id'));
        foreach ($result['downloads'] as $file) {
            $this->assertSame($file['sha256'], hash_file('sha256', $this->dir.'/'.$file['name']));
            $this->assertSame($file['bytes'], filesize($this->dir.'/'.$file['name']));
        }
        $this->assertSame($before, $this->frozenHashes());
        Http::assertNothingSent();
    }

    public function test_gallery_primary_order_code_photo_and_orphan_report_without_original_files(): void
    {
        $this->finish();
        $photos = $this->csv('zdjecia_czesci.csv');
        $byPath = array_column($photos, null, 'source_path');
        $this->assertCount(5, $byPath);
        $this->assertSame('oryginał.jpg', $byPath['parts/0001/a.jpg']['original_name']);
        $this->assertSame('2', $byPath['parts/0001/a.jpg']['sort_order']);
        $this->assertSame('1', $byPath['parts/0001/a.jpg']['is_primary']);
        $this->assertSame('0', $byPath['parts/0001/b.jpg']['sort_order']);
        $this->assertSame('allegro', $byPath['parts/0001/b.jpg']['source_type']);
        $this->assertSame('code_photo', $byPath['parts/0002/code.jpg']['role']);
        $this->assertEqualsCanonicalizing(['primary', 'code_photo'], json_decode($byPath['parts/0003/shared.jpg']['roles'], true));
        $this->assertSame('003', $byPath['parts/0003/shared.jpg']['image_id']);
        $this->assertSame('1', $byPath['parts/0005/five.jpg']['is_primary']);
        $this->assertNotContains('0004', array_column($photos, 'part_id'));
        foreach ($photos as $photo) {
            $this->assertContains($photo['part_id'], array_column($this->csv('czesci.csv'), 'id'));
        }
        $issues = $this->csv('zdjecia_czesci_issues.csv');
        $this->assertSame(['orphan_image', 'orphan_image', 'missing_source_path'], array_column($issues, 'reason_code'));
        $this->assertSame('missing', $issues[0]['part_id']);
    }

    public function test_reload_lost_response_uncommitted_append_and_double_start_have_no_duplicates(): void
    {
        $this->service()->start(7, $this->run, $this->attempt);
        $checkpoint = file_get_contents($this->dir.'/checkpoint.json');
        $this->service()->tick(7, $this->run, $this->attempt);
        // Simulated process failure after append, before checkpoint commit.
        file_put_contents($this->dir.'/checkpoint.json', $checkpoint);
        $this->service()->tick(7, $this->run, $this->attempt);
        $after = file_get_contents($this->dir.'/checkpoint.json');
        $this->service()->start(7, $this->run, $this->attempt);
        $this->assertSame($after, file_get_contents($this->dir.'/checkpoint.json'));
        // Success response lost: new instance loads committed offsets through status.
        $status = $this->service()->status(7, $this->run, $this->attempt);
        $this->assertSame($this->run, $status['run_id']);
        $this->assertSame($this->attempt, $status['attempt_id']);
        $this->finish();
        $this->assertCount(5, $this->csv('czesci.csv'));
        $this->assertCount(5, $this->csv('zdjecia_czesci.csv'));
    }

    public function test_idempotent_audit_reuses_valid_files_and_repairs_only_missing_or_corrupt_files(): void
    {
        $first = $this->finish();
        $inodes = [];
        foreach ($first['downloads'] as $file) {
            $inodes[$file['name']] = stat($this->dir.'/'.$file['name'])['mtime'];
            touch($this->dir.'/'.$file['name'], 1000000000);
        }
        $this->assertSame($first['downloads'], $this->finish()['downloads']);
        foreach ($first['downloads'] as $file) {
            clearstatcache(true, $this->dir.'/'.$file['name']);
            $this->assertSame(1000000000, filemtime($this->dir.'/'.$file['name']));
        }
        unlink($this->dir.'/sprzedane.csv');
        file_put_contents($this->dir.'/zdjecia_czesci.csv', 'CORRUPT');
        $repaired = $this->finish();
        $this->assertSame($first['downloads'], $repaired['downloads']);
        $this->assertSame(1000000000, filemtime($this->dir.'/czesci.csv'));
        $this->assertSame(1000000000, filemtime($this->dir.'/moje_samochody.csv'));
    }

    public function test_bounded_generation_large_shard_and_resume(): void
    {
        $this->datasets['parts'] = [];
        for ($i = 0; $i < 301; $i++) {
            $this->datasets['parts'][] = ['id' => sprintf('%06d', $i), 'sku' => '000'.$i, 'status' => $i % 2 ? 'sold' : 'ready', 'needs_listing' => $i % 3 === 0];
        }
        $this->datasets['part_images'] = [];
        // Keep known schema on an empty dataset.
        $this->freeze(301);
        $this->service()->start(7, $this->run, $this->attempt);
        $tick = $this->service()->tick(7, $this->run, $this->attempt);
        $this->assertGreaterThan(0, $tick['processed']);
        $this->assertLessThanOrEqual(50, $tick['processed']);
        $this->assertFalse($tick['done']);
        $result = $this->finish();
        $counts = array_column($result['downloads'], 'rows', 'name');
        $this->assertSame(301, $counts['czesci.csv']);
        $this->assertSame(150, $counts['sprzedane.csv']);
        $this->assertSame(101, $counts['do_wystawienia.csv']);
        $this->assertSame(0, $counts['zdjecia_czesci.csv']);
    }

    public function test_integrity_failure_does_not_edit_source_or_checkpoint(): void
    {
        $this->service()->start(7, $this->run, $this->attempt);
        $path = $this->parent.'/logical-snapshot/'.$this->attempt.'/datasets/parts/000000.json';
        file_put_contents($path, 'TRUNCATED');
        $before = file_get_contents($this->dir.'/checkpoint.json');
        try {
            $this->service()->tick(7, $this->run, $this->attempt);
            $this->fail('Expected integrity guard');
        } catch (MigrationExportException $e) {
            $this->assertSame('csv_shard_integrity_failed', $e->reasonCode);
        }
        $this->assertSame('TRUNCATED', file_get_contents($path));
        $this->assertSame($before, file_get_contents($this->dir.'/checkpoint.json'));
    }

    public function test_download_blocks_same_size_corruption_then_bounded_audit_repairs_it(): void
    {
        $first = $this->finish();
        $file = $this->dir.'/czesci.csv';
        $original = file_get_contents($file);
        file_put_contents($file, str_repeat('X', strlen($original)));
        try {
            $this->service()->download(7, $this->run, $this->attempt, 'czesci.csv');
            $this->fail('Corrupt download was served');
        } catch (MigrationExportException $e) {
            $this->assertSame('csv_output_repair_required', $e->reasonCode);
        }
        $this->assertSame($first['downloads'], $this->finish()['downloads']);
        $this->assertSame($original, file_get_contents($file));
    }

    public function test_photo_emit_interrupted_before_checkpoint_replays_exactly_once(): void
    {
        $status = $this->service()->start(7, $this->run, $this->attempt);
        while ($status['phase'] !== 'photo_metadata') {
            $status = $this->service()->tick(7, $this->run, $this->attempt);
        }
        $before = file_get_contents($this->dir.'/checkpoint.json');
        $this->service()->tick(7, $this->run, $this->attempt);
        file_put_contents($this->dir.'/checkpoint.json', $before);
        $this->finish();
        $photos = $this->csv('zdjecia_czesci.csv');
        $this->assertCount(5, $photos);
        $this->assertCount(5, array_unique(array_column($photos, 'source_path')));
    }

    public function test_completed_status_and_download_are_read_only_including_no_sql(): void
    {
        $this->finish();
        $before = [];
        foreach (File::allFiles($this->parent) as $file) {
            $before[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }
        DB::connection()->beforeExecuting(fn () => throw new \LogicException('All SQL forbidden for CSV status/download'));
        $this->assertTrue($this->service()->status(7, $this->run, $this->attempt)['done']);
        foreach ($this->service()->status(7, $this->run, $this->attempt)['downloads'] as $file) {
            $this->service()->download(7, $this->run, $this->attempt, $file['name']);
        }
        foreach ($before as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', $path));
        }
        Http::assertNothingSent();
    }

    public function test_unknown_field_is_blocked_instead_of_dropped(): void
    {
        $this->datasets['parts'][1]['unexpected'] = 'preserve me';
        $this->freeze();
        $path = $this->parent.'/data-only/'.$this->attempt.'/parent-index.json';
        $index = json_decode(file_get_contents($path), true);
        $index['datasets']['parts']['csv_columns'] = array_values(array_diff($index['datasets']['parts']['csv_columns'], ['unexpected']));
        $this->write($path, $index);
        $this->write(dirname($path).'/checkpoint.json', ['frozen_index_sha256' => hash_file('sha256', $path)]);
        $this->expectExceptionMessage('csv_schema_columns_missing');
        $this->service()->start(7, $this->run, $this->attempt);
        $this->service()->tick(7, $this->run, $this->attempt);
    }

    public function test_busy_lock_and_source_identity_mismatch_remain_blockers(): void
    {
        $this->service()->start(7, $this->run, $this->attempt);
        $stream = fopen($this->dir.'/lock', 'c');
        flock($stream, LOCK_EX);
        try {
            $this->service()->tick(7, $this->run, $this->attempt);
            $this->fail('Parallel tick entered lock');
        } catch (MigrationExportException $e) {
            $this->assertSame('csv_busy', $e->reasonCode);
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
        file_put_contents($this->dir.'/frozen-index.json', '{}');
        $this->expectExceptionMessage('csv_source_identity_mismatch');
        $this->service()->tick(7, $this->run, $this->attempt);
    }

    public function test_rechecking_large_output_is_bounded_and_resumes_without_rewriting(): void
    {
        $this->datasets['parts'][0]['description'] = str_repeat('Długi opis; ', 30000);
        $this->freeze();
        $first = $this->finish();
        $file = $this->dir.'/czesci.csv';
        touch($file, 1000000000);
        $state = $this->service()->start(7, $this->run, $this->attempt);
        $this->assertSame('verify_outputs', $state['phase']);
        $state = $this->service()->tick(7, $this->run, $this->attempt);
        $checkpoint = json_decode(file_get_contents($this->dir.'/checkpoint.json'), true);
        $this->assertSame(256 * 1024, $checkpoint['audit']['offset']);
        $this->assertSame(0, $checkpoint['audit']['file']);
        $this->assertFalse($state['done']);
        $this->assertSame($first['downloads'], $this->finish()['downloads']);
        clearstatcache(true, $file);
        $this->assertSame(1000000000, filemtime($file));
    }

    public function test_private_downloads_require_owner_admin_and_same_parent_attempt(): void
    {
        $this->finish();
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $owner = User::query()->forceCreate(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test-password']);
        $owner->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($owner);
        $url = '/admin/tools/gpswiss-migration-export/'.$this->run.'/consolidated-csv?parent_attempt_id='.$this->attempt;
        $this->getJson($url)->assertOk()->assertJsonPath('done', true);
        foreach (['czesci.csv', 'sprzedane.csv', 'do_wystawienia.csv', 'moje_samochody.csv', 'zdjecia_czesci.csv', 'zdjecia_czesci_issues.csv'] as $file) {
            $response = $this->get($url.'&download='.$file);
            $response->assertOk();
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->getJson($url.'&download=../state.json')->assertUnprocessable();
        $this->getJson(str_replace($this->attempt, (string) Str::uuid(), $url))->assertConflict()->assertJsonPath('reason_code', 'data_only_parent_attempt_mismatch');
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'test-password']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other)->getJson($url)->assertNotFound();
        $ordinary = User::query()->create(['name' => 'Ordinary', 'email' => 'ordinary@example.test', 'password' => 'test-password']);
        $this->actingAs($ordinary)->getJson($url)->assertForbidden();
        Http::assertNothingSent();
    }
}
