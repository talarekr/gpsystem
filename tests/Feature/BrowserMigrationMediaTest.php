<?php

namespace Tests\Feature;

use App\Services\MigrationExport\BrowserMigrationArchives;
use App\Services\MigrationExport\BrowserMigrationPhotos;
use App\Services\MigrationExport\MigrationOriginals;
use App\Services\MigrationExport\SerializableSha256;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BrowserMigrationMediaTest extends TestCase
{
    private string $temporary;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/browser-media-'.Str::uuid();
        foreach (['source', 'mirror', 'export/data'] as $folder) {
            mkdir($this->temporary.'/'.$folder, 0700, true);
        }
        config(['filesystems.disks.public.root' => $this->temporary.'/source', 'filesystems.served_public_storage_root' => $this->temporary.'/mirror']);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('HTTP forbidden'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    public function test_small_zip_volumes_and_large_file_fragments_resume_and_reassemble_exact_original_bytes(): void
    {
        config(['migration_export.archive_payload_bytes' => 4096]);
        $dir = $this->temporary.'/export';
        $originals = ['data/a.json' => '{"business":"keep"}', 'data/empty.csv' => '', 'data/large.png' => str_repeat('0123456789', 1800)];
        $lines = [];
        foreach ($originals as $path => $bytes) {
            file_put_contents($dir.'/'.$path, $bytes);
            $lines[] = json_encode(['path' => $path, 'archive_path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        }
        file_put_contents($dir.'/artifacts.jsonl', implode("\n", $lines)."\n");
        $progress = [];
        for ($i = 0; $i < 20; $i++) {
            $helper = app(BrowserMigrationArchives::class); // fresh instance per HTTP tick/reload
            $state = $helper->tick($dir, 'artifacts.jsonl');
            $progress[] = $state['payload_bytes'];
            if ($state['done']) {
                break;
            }
        }
        $this->assertTrue($state['done']);
        $this->assertGreaterThan(4, $state['volume_count']);
        $this->assertSame(3, $state['artifact_count']);
        for ($i = 1; $i < count($progress); $i++) {
            $this->assertGreaterThanOrEqual($progress[$i - 1], $progress[$i]);
        }
        $chunks = [];
        foreach ($state['volumes'] as $volume) {
            $zipPath = $dir.'/'.$volume['path'];
            $this->assertSame($volume['sha256'], hash_file('sha256', $zipPath));
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($zipPath));
            $this->assertLessThanOrEqual(20, $zip->numFiles);
            $map = json_decode($zip->getFromName('__gpswiss_volume_manifest.json'), true);
            $payload = 0;
            foreach ($map['entries'] as $entry) {
                $bytes = $zip->getFromName($entry['zip_entry']);
                $this->assertNotFalse($bytes);
                $this->assertSame($entry['sha256'], hash('sha256', $bytes));
                $this->assertSame($entry['bytes'], strlen($bytes));
                $chunks[$entry['archive_path']][$entry['offset']] = $bytes;
                $payload += strlen($bytes);
            }
            $this->assertLessThanOrEqual(4096, $payload);
            $zip->close();
        }
        foreach ($originals as $path => $expected) {
            ksort($chunks[$path]);
            $this->assertSame($expected, implode('', $chunks[$path]));
        }
        $manifest = json_decode(file_get_contents($dir.'/archive-manifest.json'), true);
        $this->assertFalse($manifest['automatic_import_allowed']);
        $this->assertSame($state, app(BrowserMigrationArchives::class)->tick($dir, 'artifacts.jsonl'));
        Http::assertNothingSent();
    }

    public function test_photo_copy_is_chunked_mirror_verified_and_journal_is_repaired_after_interrupted_append(): void
    {
        $dir = $this->temporary.'/export';
        $bytes = base64_decode(self::PNG).str_repeat('x', 4 * 1024 * 1024 + 100);
        foreach (['source', 'mirror'] as $root) {
            file_put_contents($this->temporary.'/'.$root.'/oryginał.png', $bytes);
        }
        $task = app(MigrationOriginals::class)->task(['id' => '001', 'part_id' => '0001', 'path' => 'oryginał.png', 'sort_order' => 0, 'is_primary' => true], ['id' => '0001']);
        file_put_contents($dir.'/tasks.jsonl', json_encode($task)."\n");
        $iterations = 0;
        for (; $iterations < 80; $iterations++) {
            $state = app(BrowserMigrationPhotos::class)->tick($dir, 'tasks.jsonl');
            if ($iterations === 0) {
                $this->assertSame(0, $state['processed']);
                $this->assertSame(512 * 1024, $state['current_image_offset']);
                file_put_contents($dir.'/photos.jsonl', "{\"uncommitted\":true}\n", FILE_APPEND);
            }
            if ($state['done']) {
                break;
            }
        }
        $this->assertTrue($state['done']);
        $this->assertGreaterThan(5, $iterations);
        $this->assertSame(1, $state['processed']);
        $rows = array_map(fn ($line) => json_decode($line, true), file($dir.'/photos.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('COPIED_VERIFIED', $row['status']);
        $this->assertSame('oryginał.png', $row['original_filename']);
        $this->assertSame(hash('sha256', $bytes), $row['sha256']);
        $this->assertSame($bytes, file_get_contents($dir.'/'.$row['package_path']));
        $this->assertSame($bytes, file_get_contents($this->temporary.'/source/oryginał.png'));
        $this->assertSame($state, app(BrowserMigrationPhotos::class)->status($dir));
        $this->assertSame($state, app(BrowserMigrationPhotos::class)->tick($dir, 'tasks.jsonl'));
        Http::assertNothingSent();
    }

    public function test_receipt_recovers_rename_before_checkpoint_without_duplicate_rows_or_source_recopy(): void
    {
        $dir = $this->temporary.'/export';
        file_put_contents($this->temporary.'/source/once.png', base64_decode(self::PNG));
        $task = app(MigrationOriginals::class)->task(['id' => '1', 'part_id' => '1', 'path' => 'once.png'], ['id' => '1']);
        file_put_contents($dir.'/tasks.jsonl', json_encode($task)."\n");
        app(BrowserMigrationPhotos::class)->tick($dir, 'tasks.jsonl'); // copy complete, final verification still pending
        $beforeCommit = file_get_contents($dir.'/photo-checkpoint.json');
        $done = app(BrowserMigrationPhotos::class)->tick($dir, 'tasks.jsonl');
        $this->assertSame(1, $done['processed']);
        $row = json_decode(trim(file_get_contents($dir.'/photos.jsonl')), true);
        // A crash can leave the private file/receipt published but the old checkpoint in place.
        file_put_contents($dir.'/photo-checkpoint.json', $beforeCommit);
        unlink($this->temporary.'/source/once.png');
        $recovered = app(BrowserMigrationPhotos::class)->tick($dir, 'tasks.jsonl');
        $this->assertSame(1, $recovered['processed']);
        $this->assertCount(1, file($dir.'/photos.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertSame($row['sha256'], hash_file('sha256', $dir.'/'.$row['package_path']));
        $this->assertSame('COPIED_VERIFIED', json_decode(trim(file_get_contents($dir.'/photos.jsonl')), true)['status']);
    }

    public function test_archive_cannot_include_private_checkpoints_or_traverse_outside_export(): void
    {
        $dir = $this->temporary.'/export';
        file_put_contents($dir.'/state.json', '{"credential":"not-exportable"}');
        file_put_contents($dir.'/artifacts.jsonl', json_encode(['path' => 'state.json', 'archive_path' => 'business.json', 'bytes' => filesize($dir.'/state.json')])."\n");
        try {
            app(BrowserMigrationArchives::class)->tick($dir, 'artifacts.jsonl');
            $this->fail('Private checkpoint must not be archived');
        } catch (RuntimeException $e) {
            $this->assertSame('archive_private_checkpoint_forbidden', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dir.'/archive-checkpoint.json');
        $this->expectException(RuntimeException::class);
        app(BrowserMigrationArchives::class)->tick($dir, '../source');
    }

    public function test_photo_source_change_or_conflicting_mirror_is_a_terminal_report_not_a_source_write(): void
    {
        $dir = $this->temporary.'/export';
        foreach (['source', 'mirror'] as $root) {
            file_put_contents($this->temporary.'/'.$root.'/image.png', base64_decode(self::PNG));
        }
        $task = app(MigrationOriginals::class)->task(['id' => '1', 'part_id' => '1', 'path' => 'image.png'], ['id' => '1']);
        file_put_contents($this->temporary.'/source/image.png', 'changed-after-inventory');
        file_put_contents($dir.'/tasks.jsonl', json_encode($task)."\n");
        $state = app(BrowserMigrationPhotos::class)->tick($dir, 'tasks.jsonl');
        $this->assertSame(1, $state['processed']);
        $this->assertSame(['SOURCE_CHANGED' => 1], $state['result_counts']);
        $this->assertSame('changed-after-inventory', file_get_contents($this->temporary.'/source/image.png'));
        $this->assertFileDoesNotExist($dir.'/'.$task['package_path']);
    }

    public function test_serializable_sha256_matches_standard_vectors_and_fragmented_binary_checkpoint_reloads(): void
    {
        $vectors = [
            '' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'abc' => 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            'abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq' => '248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1',
            str_repeat('a', 1000000) => 'cdc76e5c9914fb9281a1c7e284d73e67f1809a48a497200e046d39ccc7112cd0',
        ];
        foreach ($vectors as $bytes => $expected) {
            $state = SerializableSha256::initial();
            foreach (str_split($bytes, 91 * 1024) as $fragment) {
                $state = SerializableSha256::update($state, $fragment);
                $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 8, JSON_THROW_ON_ERROR);
            }
            $this->assertSame($expected, SerializableSha256::digest($state));
        }
        $bytes = random_bytes(2 * 1024 * 1024 + 197);
        $state = SerializableSha256::initial();
        for ($offset = 0; $offset < strlen($bytes); $offset += 32771) {
            $state = SerializableSha256::update($state, substr($bytes, $offset, 32771));
            $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 8, JSON_THROW_ON_ERROR);
            $this->assertLessThan(400, strlen(json_encode($state)));
        }
        $this->assertSame(hash('sha256', $bytes), SerializableSha256::digest($state));
        // Digest does not mutate the context; finalization is safe to repeat after a crash.
        $this->assertSame(hash('sha256', $bytes), SerializableSha256::digest($state));
    }

    public function test_sample_archive_namespace_remains_independent_and_fragmented_whole_hash_is_verified(): void
    {
        config(['migration_export.archive_payload_bytes' => 1024]);
        $dir = $this->temporary.'/export';
        $bytes = str_repeat('sample-full-identity', 200);
        file_put_contents($dir.'/data/business.json', $bytes);
        file_put_contents($dir.'/artifacts.jsonl', json_encode(['path' => 'data/business.json', 'archive_path' => 'products.json', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])."\n");
        for ($i = 0; $i < 10; $i++) {
            $sample = app(BrowserMigrationArchives::class)->tick($dir, 'artifacts.jsonl', namespace: 'sample');
            if ($sample['done']) {
                break;
            }
        }
        $this->assertTrue($sample['done']);
        $this->assertSame('sample-archive-manifest.json', $sample['manifest']);
        $this->assertStringStartsWith('sample-volume-', $sample['volumes'][0]['key']);
        $this->assertFalse(app(BrowserMigrationArchives::class)->status($dir)['done']);
        $this->assertFileDoesNotExist($dir.'/archive-checkpoint.json');
        $full = app(BrowserMigrationArchives::class)->tick($dir, 'artifacts.jsonl');
        $this->assertSame('volume-000001', $full['volumes'][0]['key']);
        $this->assertSame($sample, app(BrowserMigrationArchives::class)->status($dir, 'sample'));

        // A declared hash for a fragmented file must be checked against all fragments, not size only.
        $badDir = $dir.'/bad';
        mkdir($badDir, 0700);
        file_put_contents($badDir.'/bad.json', $bytes);
        file_put_contents($badDir.'/artifacts.jsonl', json_encode(['path' => 'bad.json', 'archive_path' => 'bad.json', 'bytes' => strlen($bytes), 'sha256' => str_repeat('0', 64)])."\n");
        try {
            for ($i = 0; $i < 10; $i++) {
                app(BrowserMigrationArchives::class)->tick($badDir, 'artifacts.jsonl');
            }
            $this->fail('Declared whole-file checksum mismatch must block archive completion');
        } catch (RuntimeException $e) {
            $this->assertSame('archive_artifact_hash_mismatch', $e->getMessage());
        }
        $this->assertFalse(app(BrowserMigrationArchives::class)->status($badDir)['done']);
        $this->assertFileDoesNotExist($badDir.'/archive-manifest.json');
    }

    public function test_photo_retry_uses_same_frozen_source_and_publishes_one_canonical_overlay_without_recopying_good_files(): void
    {
        $dir = $this->temporary.'/export';
        $bytes = base64_decode(self::PNG).str_repeat('image-byte', 15000);
        file_put_contents($this->temporary.'/source/retry.png', $bytes);
        $task = app(MigrationOriginals::class)->task(['id' => '0005', 'part_id' => '00009', 'path' => 'retry.png'], ['id' => '00009']);
        file_put_contents($dir.'/tasks.jsonl', json_encode($task)."\n");
        $photos = app(BrowserMigrationPhotos::class);
        $photos->tick($dir, 'tasks.jsonl');
        // Model a transient damaged private partial after source copying but before target verification.
        $partial = $dir.'/'.$task['package_path'].'.partial';
        file_put_contents($partial, str_repeat('x', strlen($bytes)));
        for ($i = 0; $i < 10; $i++) {
            $state = $photos->tick($dir, 'tasks.jsonl');
            if ($state['done']) {
                break;
            }
        }
        $this->assertTrue($state['done']);
        $initial = json_decode(trim(file_get_contents($dir.'/photos.jsonl')), true);
        $this->assertSame('COPY_FAILED', $initial['status']);
        $this->assertTrue($photos->retryable($initial));
        $generation = 'retry-'.Str::uuid();
        for ($i = 0; $i < 15; $i++) {
            $retry = $photos->tick($dir, 'tasks.jsonl', generation: $generation);
            if ($retry['done']) {
                break;
            }
        }
        $this->assertTrue($retry['done']);
        $this->assertSame(1, $retry['processed']);
        $this->assertSame(['COPIED_VERIFIED' => 1], $retry['result_counts']);
        $this->assertStringStartsWith('photo-retries/', $retry['results_file']);
        $this->assertSame($retry, $photos->status($dir, $generation));
        $canonical = $photos->latestResult($dir, $initial);
        $this->assertSame('COPIED_VERIFIED', $canonical['status']);
        $this->assertSame(hash('sha256', $bytes), $canonical['sha256']);
        $this->assertCount(1, file($dir.'/photos.jsonl'));
        $this->assertSame('COPY_FAILED', json_decode(trim(file_get_contents($dir.'/photos.jsonl')), true)['status']);
        $target = $dir.'/'.$task['package_path'];
        $inode = stat($target)['ino'];
        unlink($this->temporary.'/source/retry.png');
        $secondGeneration = 'retry-'.Str::uuid();
        $skip = $photos->tick($dir, 'tasks.jsonl', generation: $secondGeneration);
        $this->assertSame(1, $skip['processed']);
        $this->assertSame(['COPIED_VERIFIED' => 1], $skip['result_counts']);
        $this->assertSame($inode, stat($target)['ino']);
        $this->assertSame($bytes, file_get_contents($target));
        $this->assertFalse($photos->retryable(['status' => 'SOURCE_CHANGED']));
        Http::assertNothingSent();
    }

    public function test_retry_rejects_changed_frozen_source_and_does_not_repeat_permanent_failures(): void
    {
        $dir = $this->temporary.'/export';
        file_put_contents($this->temporary.'/source/changed.png', base64_decode(self::PNG));
        $task = app(MigrationOriginals::class)->task(['id' => '4', 'part_id' => '2', 'path' => 'changed.png'], ['id' => '2']);
        file_put_contents($dir.'/tasks.jsonl', json_encode($task)."\n");
        file_put_contents($this->temporary.'/source/changed.png', 'changed-original-after-inventory');
        $photos = app(BrowserMigrationPhotos::class);
        $initial = $photos->tick($dir, 'tasks.jsonl');
        $this->assertSame(['SOURCE_CHANGED' => 1], $initial['result_counts']);
        $retry = $photos->tick($dir, 'tasks.jsonl', generation: 'retry-'.Str::uuid());
        $this->assertSame(['SOURCE_CHANGED' => 1], $retry['result_counts']);
        $this->assertFileDoesNotExist($dir.'/'.$task['package_path']);
        $task['source']['copies'][0]['fingerprint']['ctime']++;
        file_put_contents($dir.'/other-retry-tasks.jsonl', json_encode($task)."\n");
        $this->expectExceptionMessage('photo_retry_frozen_source_changed');
        $photos->tick($dir, 'other-retry-tasks.jsonl', generation: 'retry-'.Str::uuid());
    }

    public function test_photo_target_symlink_cannot_write_to_or_chmod_source_and_expired_deadline_does_no_copy(): void
    {
        $dir = $this->temporary.'/export';
        $source = $this->temporary.'/source/safe.png';
        $bytes = base64_decode(self::PNG);
        file_put_contents($source, $bytes);
        chmod($source, 0644);
        $task = app(MigrationOriginals::class)->task(['id' => '1', 'part_id' => '1', 'path' => 'safe.png'], ['id' => '1']);
        file_put_contents($dir.'/tasks.jsonl', json_encode($task)."\n");
        $photos = app(BrowserMigrationPhotos::class);
        $state = $photos->tick($dir, 'tasks.jsonl', microtime(true) - 1);
        $this->assertSame(0, $state['processed']);
        $this->assertFileDoesNotExist($dir.'/photo-checkpoint.json');
        mkdir(dirname($dir.'/'.$task['package_path']), 0700, true);
        symlink($source, $dir.'/'.$task['package_path']);
        try {
            $photos->tick($dir, 'tasks.jsonl');
            $this->fail('Source-linked target must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame('photo_target_path_unsafe', $e->getMessage());
        }
        clearstatcache(true, $source);
        $this->assertSame(0644, fileperms($source) & 0777);
        $this->assertSame($bytes, file_get_contents($source));
    }

    public function test_failed_checkpoint_temp_write_keeps_previous_committed_state(): void
    {
        $dir = $this->temporary.'/export';
        foreach ([BrowserMigrationPhotos::class, BrowserMigrationArchives::class] as $class) {
            $checkpoint = $dir.'/'.class_basename($class).'.json';
            file_put_contents($checkpoint, '{"previous":"committed"}');
            mkdir($checkpoint.'.tmp', 0700);
            $method = new \ReflectionMethod($class, 'write');
            $thrown = false;
            try {
                $method->invoke(app($class), $checkpoint, ['new' => 'uncommitted']);
            } catch (\Throwable) {
                $thrown = true;
            }
            $this->assertTrue($thrown, 'Failed checkpoint write must throw');
            $this->assertSame('{"previous":"committed"}', file_get_contents($checkpoint));
        }
    }
}
