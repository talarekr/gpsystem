<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\DataOnlyMigrationPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/** Synthetic stored-artifact fixtures only; packing must never query live business records. */
class DataOnlyMigrationPackageTest extends TestCase
{
    use RefreshDatabase;

    private string $temporary;

    private string $parent;

    private string $directory;

    private string $run;

    private string $attempt;

    private array $expected = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/data-only-'.Str::uuid();
        $this->run = (string) Str::uuid();
        $this->attempt = (string) Str::uuid();
        $this->parent = $this->temporary.'/exports/'.$this->run;
        $this->directory = $this->parent.'/data-only/'.$this->attempt;
        mkdir($this->parent.'/images/1', 0700, true);
        mkdir($this->parent.'/logical-snapshot/'.$this->attempt, 0700, true);
        config(['migration_export.root' => $this->temporary.'/exports', 'filesystems.disks.public.root' => $this->temporary.'/originals', 'migration_export.archive_payload_bytes' => 4096]);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Provider forbidden'));
        $this->write($this->parent.'/owner.json', ['owner_id' => 7, 'run_id' => $this->run]);
        $this->write($this->parent.'/state.json', ['run_id' => $this->run, 'owner_id' => 7, 'state' => 'photos', 'phase' => 'photo_copy', 'snapshot_state' => 'snapshot_completed', 'snapshot_mode' => 'browser', 'snapshot_attempt_id' => $this->attempt, 'processed_images' => 1, 'relation_audit' => ['done' => true, 'issues_count' => 1]]);
        $this->write($this->parent.'/photo-checkpoint.json', ['processed' => 1, 'cursor' => 50]);
        file_put_contents($this->parent.'/images/1/original.jpg', 'ORIGINAL_PHOTO_DO_NOT_READ_OR_PACKAGE');
        file_put_contents($this->parent.'/photo-manifest.csv', 'PHOTO_MANIFEST_NOT_DATA');
        file_put_contents($this->parent.'/missing-originals.jsonl', 'MISSING_PHOTO_REPORT_NOT_DATA');
        file_put_contents($this->parent.'/relation-issues.jsonl', "{\"reason\":\"missing_source_reference\"}\n");
        $parts = [['id' => '0001', 'sku' => '000SKU', 'status' => 'sold', 'quantity' => 7, 'needs_listing' => false], ['id' => '0002', 'status' => 'draft', 'quantity' => 0, 'needs_listing' => true], ['id' => '0003', 'status' => 'ready', 'quantity' => 0, 'needs_listing' => false]];
        $datasets = ['parts' => $parts, 'cars' => [['id' => '9', 'make' => 'Make']], 'part_car_relations' => [['part_id' => '0001', 'car_id' => '9']], 'part_categories' => [['id' => '5', 'name' => 'Category']], 'storage_locations' => [['id' => '6', 'name' => 'Shelf']], 'marketplace_category_mappings' => [['id' => '1', 'channel' => 'allegro_main']], 'allegro_parameter_selections' => [['id' => '1', 'part_id' => '0001', 'value_id' => '000value']], 'marketplace_listings' => [['id' => '1', 'part_id' => '0001', 'marketplace' => 'allegro']], 'orders' => [['id' => '1', 'customer_name' => 'Synthetic customer']], 'order_items' => [['id' => '1', 'order_id' => '1', 'part_id' => '0001']], 'shipments' => [['id' => '1', 'order_id' => '1']], 'payments' => [], 'refunds' => [], 'part_images' => [['id' => '1', 'part_id' => '0001', 'path' => 'original.jpg']]];
        $definitions = [];
        foreach ($datasets as $name => $rows) {
            $path = 'logical-snapshot/'.$this->attempt.'/datasets/'.$name.'/000000.json';
            mkdir(dirname($this->parent.'/'.$path), 0700, true);
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE);
            $csv = "\xEF\xBB\xBFid\n".implode('', array_map(fn ($r) => ($r['id'] ?? '')."\n", $rows));
            file_put_contents($this->parent.'/'.$path, $json);
            file_put_contents($this->parent.'/'.substr($path, 0, -5).'.csv', $csv);
            $this->expected[$path] = $json;
            $this->expected[substr($path, 0, -5).'.csv'] = $csv;
            $definitions[$name] = ['available' => true, 'name' => $name, 'source_table' => $name, 'exported_columns' => ['id'], 'csv_columns' => ['id'], 'column_definitions' => ['id' => ['type' => 'bigint']], 'relations' => [], 'omitted_columns' => [], 'processed' => count($rows), 'total' => count($rows), 'verified' => 0, 'done' => true,
                'shards' => [['path' => $path, 'csv_path' => substr($path, 0, -5).'.csv', 'rows' => count($rows), 'sha256' => hash('sha256', $json), 'csv_sha256' => hash('sha256', $csv), 'bytes' => strlen($json), 'csv_bytes' => strlen($csv)]]];
        }
        $exception = ['accepted_by_operator' => true, 'dataset' => 'parts', 'known_count_from_operator' => 2, 'recorded_ids' => ['2262'], 'remaining_operator_declared_without_recorded_id' => 1, 'outcome' => 'accepted_known_drift'];
        $index = ['attempt_id' => $this->attempt, 'format' => 'gpswiss-wystawczesc-logical-3', 'stage' => 'completed', 'dataset_index' => count($definitions), 'dataset_names' => array_keys($definitions), 'datasets' => $definitions, 'database_consistency' => 'operator_accepted_drift_not_transactional', 'consistency_exception' => $exception, 'operator_finalization' => ['live_source_verification_skipped' => true]];
        $this->write($this->parent.'/logical-snapshot/'.$this->attempt.'/checkpoint.json', $index);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    private function write(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value));
    }

    private function finish(): array
    {
        $service = app(DataOnlyMigrationPackage::class);
        $state = $service->start(7, $this->run, $this->attempt);
        for ($i = 0; $i < 500 && ! $state['done']; $i++) {
            $state = app(DataOnlyMigrationPackage::class)->tick(7, $this->run, $this->attempt);
        }
        $this->assertTrue($state['done'], json_encode($state));

        return $state;
    }

    private function parentHashes(): array
    {
        $hashes = [];
        foreach (File::allFiles($this->parent) as $file) {
            if (! str_starts_with($file->getRelativePathname(), 'data-only/')) {
                $hashes[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
            }
        }

        return $hashes;
    }

    private function unpack(): array
    {
        $manifest = json_decode(file_get_contents($this->directory.'/data-only-archive-manifest.json'), true);
        $parts = [];
        foreach ($manifest['volumes'] as $volume) {
            $download = app(DataOnlyMigrationPackage::class)->download(7, $this->run, $this->attempt, $volume['key']);
            $this->assertSame($volume['file'], $download['name']);
            $this->assertSame($volume['sha256'], hash_file('sha256', $download['path']));
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($download['path']));
            $embedded = json_decode($zip->getFromName('__gpswiss_volume_manifest.json'), true);
            foreach ($embedded['entries'] as $entry) {
                $bytes = $zip->getFromName($entry['zip_entry']);
                $this->assertSame($entry['sha256'], hash('sha256', $bytes));
                $this->assertDoesNotMatchRegularExpression('~(?:^|/)images/~', $entry['archive_path']);
                $this->assertStringNotContainsString('photo-manifest', $entry['archive_path']);
                $parts[$entry['archive_path']][$entry['offset']] = $bytes;
            }
            $zip->close();
        }
        $files = [];
        foreach ($parts as $path => $chunks) {
            ksort($chunks);
            $files[$path] = implode('', $chunks);
        }

        return $files;
    }

    public function test_all_stored_datasets_and_drift_survive_without_live_sql_or_photos(): void
    {
        $before = $this->parentHashes();
        DB::connection()->beforeExecuting(fn () => throw new \LogicException('No SQL permitted during data-only packaging'));
        $result = $this->finish();
        $files = $this->unpack();
        foreach ($this->expected as $path => $bytes) {
            $this->assertSame($bytes, $files[$path], $path);
        }
        foreach (['fields.generated.json', 'statuses.json', 'sold-part-ids.csv', 'parts-to-list-ids.csv', 'migration-manifest.json', 'data-only-package-manifest.json', 'relation-issues.jsonl', 'data-only-checksums.jsonl'] as $path) {
            $this->assertArrayHasKey($path, $files);
        }
        $manifest = json_decode($files['data-only-package-manifest.json'], true);
        $this->assertFalse($manifest['photos_included']);
        $this->assertTrue($manifest['photos_migrated_separately']);
        $this->assertTrue($manifest['allegro_parameters_supplement_required']);
        $this->assertFalse($manifest['cutover_ready']);
        $this->assertTrue($manifest['live_source_verification_skipped_by_operator']);
        $this->assertSame(['2262'], $manifest['accepted_parts_drift']['recorded_ids']);
        $this->assertSame(2, $manifest['accepted_parts_drift']['known_count']);
        $this->assertSame(1, $manifest['accepted_parts_drift']['unknown_count']);
        $this->assertSame($this->attempt, $manifest['parent_attempt_id']);
        $this->assertSame($this->run, $manifest['parent_run_id']);
        $this->assertSame("\xEF\xBB\xBFpart_id\n0001\n", $files['sold-part-ids.csv']);
        $this->assertSame("\xEF\xBB\xBFpart_id\n0002\n", $files['parts-to-list-ids.csv']);
        foreach (explode("\n", trim($files['data-only-checksums.jsonl'])) as $line) {
            $entry = json_decode($line, true);
            $this->assertSame($entry['sha256'], hash('sha256', $files[$entry['path']]));
        }
        $this->assertGreaterThan(1, $result['volume_count']);
        $this->assertSame($before, $this->parentHashes());
        Http::assertNothingSent();
    }

    public function test_repeat_reuses_valid_volumes_and_repairs_only_missing_or_corrupt_ones(): void
    {
        $state = $this->finish();
        $volumes = json_decode(file_get_contents($this->directory.'/archive-checkpoint.json'), true)['volumes'];
        $this->assertGreaterThan(2, count($volumes));
        $fingerprints = [];
        foreach ($volumes as $volume) {
            $fingerprints[$volume['path']] = stat($this->directory.'/'.$volume['path'])['ino'];
        }
        unlink($this->directory.'/'.$volumes[0]['path']);
        file_put_contents($this->directory.'/'.$volumes[1]['path'], 'CORRUPT');
        DB::connection()->beforeExecuting(fn () => throw new \LogicException('No live SQL'));
        $again = $this->finish();
        $this->assertSame($state['volume_count'], $again['volume_count']);
        foreach ($volumes as $i => $volume) {
            $path = $this->directory.'/'.$volume['path'];
            $this->assertSame($volume['sha256'], hash_file('sha256', $path));
            if ($i > 1) {
                $this->assertSame($fingerprints[$volume['path']], stat($path)['ino']);
            }
        }
    }

    public function test_lost_catalog_checkpoint_does_not_duplicate_files_or_groups(): void
    {
        $service = app(DataOnlyMigrationPackage::class);
        $service->start(7, $this->run, $this->attempt);
        $checkpoint = file_get_contents($this->directory.'/checkpoint.json');
        $service->tick(7, $this->run, $this->attempt);
        file_put_contents($this->directory.'/checkpoint.json', $checkpoint);
        $this->finish();
        $entries = array_map(fn ($line) => json_decode($line, true), file($this->directory.'/archive-input.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertCount(count($entries), array_unique(array_column($entries, 'archive_path')));
        $this->assertSame("\xEF\xBB\xBFpart_id\n0001\n", file_get_contents($this->directory.'/sold-part-ids.csv'));
    }

    public function test_no_copied_photos_or_photo_manifests_are_required(): void
    {
        File::deleteDirectory($this->parent.'/images');
        unlink($this->parent.'/photo-manifest.csv');
        unlink($this->parent.'/missing-originals.jsonl');
        mkdir($this->parent.'/allegro-parameters-supplement', 0700);
        file_put_contents($this->parent.'/allegro-parameters-supplement/extra.json', 'SEPARATE_SUPPLEMENT_NOT_INCLUDED');
        $state = $this->finish();
        $this->assertTrue($state['done']);
        $files = $this->unpack();
        $this->assertStringNotContainsString('SEPARATE_SUPPLEMENT_NOT_INCLUDED', implode('', $files));
        $this->assertArrayHasKey('logical-snapshot/'.$this->attempt.'/datasets/part_images/000000.json', $files);
    }

    public function test_lost_archive_response_continues_without_rebuilding_committed_volumes(): void
    {
        $service = app(DataOnlyMigrationPackage::class);
        $state = $service->start(7, $this->run, $this->attempt);
        while ($state['phase'] !== 'archive') {
            $state = $service->tick(7, $this->run, $this->attempt);
        }
        $checkpoint = file_get_contents($this->directory.'/checkpoint.json');
        $service->tick(7, $this->run, $this->attempt);
        $volume = $this->directory.'/archive-volumes/volume-000001.zip';
        $inode = stat($volume)['ino'];
        file_put_contents($this->directory.'/checkpoint.json', $checkpoint);
        $this->finish();
        $this->assertSame($inode, stat($volume)['ino']);
    }

    public function test_repair_rejects_changed_source_instead_of_inventing_new_checksums(): void
    {
        $this->finish();
        $descriptor = json_decode(file_get_contents($this->directory.'/archive-volumes/volume-000001.json'), true);
        $source = $this->parent.'/'.$descriptor['entries'][0]['source_path'];
        $bytes = file_get_contents($source);
        file_put_contents($source, str_repeat('x', strlen($bytes)));
        unlink($this->directory.'/'.$descriptor['file']);
        $this->expectExceptionMessage('data_only_artifact_integrity_failed');
        $this->finish();
    }

    public function test_all_recorded_drift_ids_are_preserved_instead_of_hardcoding_the_reported_example(): void
    {
        $path = $this->parent.'/logical-snapshot/'.$this->attempt.'/checkpoint.json';
        $index = json_decode(file_get_contents($path), true);
        $index['consistency_exception']['recorded_ids'] = ['1937', '2262'];
        $index['consistency_exception']['remaining_operator_declared_without_recorded_id'] = 0;
        $this->write($path, $index);
        $this->finish();
        $manifest = json_decode(file_get_contents($this->directory.'/data-only-package-manifest.json'), true);
        $this->assertSame(['1937', '2262'], $manifest['accepted_parts_drift']['recorded_ids']);
        $this->assertSame(0, $manifest['accepted_parts_drift']['unknown_count']);
    }

    public function test_source_checksum_mismatch_is_blocked_without_snapshot_retry(): void
    {
        $service = app(DataOnlyMigrationPackage::class);
        $service->start(7, $this->run, $this->attempt);
        file_put_contents($this->parent.'/'.array_key_first($this->expected), 'CORRUPTED');
        $this->expectExceptionMessage('data_only_source_checksum_mismatch');
        $service->tick(7, $this->run, $this->attempt);
    }

    public function test_parent_boundary_change_is_blocked(): void
    {
        $service = app(DataOnlyMigrationPackage::class);
        $service->start(7, $this->run, $this->attempt);
        file_put_contents($this->parent.'/logical-snapshot/'.$this->attempt.'/checkpoint.json', ' ', FILE_APPEND);
        $this->expectExceptionMessage('data_only_parent_boundary_changed');
        $service->tick(7, $this->run, $this->attempt);
    }

    public function test_unsealed_capture_is_not_packaged(): void
    {
        $path = $this->parent.'/logical-snapshot/'.$this->attempt.'/checkpoint.json';
        $index = json_decode(file_get_contents($path), true);
        $index['stage'] = 'verify';
        $this->write($path, $index);
        $this->expectExceptionMessage('data_only_capture_not_sealed');
        app(DataOnlyMigrationPackage::class)->start(7, $this->run, $this->attempt);
    }

    public function test_matching_existing_metadata_is_preserved_and_photos_are_removed_from_projection(): void
    {
        $this->write($this->parent.'/migration-manifest.json', ['run_id' => $this->run, 'snapshot_attempt_id' => $this->attempt, 'business_policy' => 'preserve', 'photo_manifest' => 'photo-manifest.csv']);
        $this->write($this->parent.'/statuses.json', ['stored_status_labels' => ['sold' => 'Sold']]);
        $this->finish();
        $manifest = json_decode(file_get_contents($this->directory.'/migration-manifest.json'), true);
        $this->assertSame('preserve', $manifest['business_policy']);
        $this->assertArrayNotHasKey('photo_manifest', $manifest);
        $this->assertArrayHasKey('stored_status_labels', json_decode(file_get_contents($this->directory.'/statuses.json'), true));
    }

    public function test_private_downloads_owner_and_attempt_guards(): void
    {
        $user = User::query()->forceCreate(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test-password']);
        Role::findOrCreate(UserRole::OwnerAdmin->value);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $url = route('admin.tools.gpswiss-migration-export.data-only', ['run' => $this->run]);
        $this->actingAs($user)->postJson($url, ['parent_attempt_id' => $this->attempt, 'action' => 'start'])->assertOk()->assertJsonPath('business_database_reads', false);
        $this->finish();
        $response = $this->get($url.'?parent_attempt_id='.$this->attempt.'&download=data-only-package-manifest')->assertOk();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->postJson($url, ['parent_attempt_id' => (string) Str::uuid(), 'action' => 'tick'])->assertStatus(409)->assertJsonPath('reason_code', 'data_only_parent_attempt_mismatch');
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'test-password']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other)->getJson($url.'?parent_attempt_id='.$this->attempt)->assertNotFound();
        $other->removeRole(UserRole::OwnerAdmin->value);
        $this->getJson($url.'?parent_attempt_id='.$this->attempt)->assertForbidden();
    }
}
