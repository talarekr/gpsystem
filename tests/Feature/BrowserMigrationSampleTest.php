<?php

namespace Tests\Feature;

use App\Services\MigrationExport\BrowserMigrationSample;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class BrowserMigrationSampleTest extends TestCase
{
    private string $folder;

    private string $attempt;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/browser-sample-'.Str::uuid();
        $this->attempt = (string) Str::uuid();
        mkdir($this->folder, 0700);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('Sample must not call a provider'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    public function test_real_five_product_projection_is_bounded_resumable_and_preserves_only_related_private_data(): void
    {
        $sources = $this->fixture();
        DB::connection()->beforeExecuting(fn () => throw new LogicException('Sample must use only frozen files'));
        $ticks = 0;
        $previous = [];
        do {
            $state = app(BrowserMigrationSample::class)->tick($this->folder, $this->attempt);
            $current = $this->sampleDatasetFiles();
            $changed = array_filter($current, fn ($sha, $path) => ($previous[$path] ?? null) !== $sha, ARRAY_FILTER_USE_BOTH);
            $this->assertLessThanOrEqual(1, count($changed), 'One tick may project at most one bounded frozen shard');
            $previous = $current;
            $ticks++;
            $this->assertLessThan(1200, $ticks, json_encode($state));
        } while (! $state['done']);
        $this->assertGreaterThan(10, $ticks, 'The sample must resume through separate bounded stages');
        $ids = array_map('strval', $state['sample_ids']);
        sort($ids);
        $this->assertSame(['001', '002', '003', '004', '005'], $ids);
        $parts = $this->rows('parts', $state);
        $this->assertCount(5, $parts);
        $this->assertSame($ids, $this->ids($parts));
        $this->assertSame('00001', array_column($parts, 'sku', 'id')['001']);
        $this->assertSame(['10', '20'], $this->ids($this->rows('part_categories', $state)));
        $this->assertSame(['100', '101'], $this->ids($this->rows('cars', $state)));
        $this->assertSame(['30'], $this->ids($this->rows('storage_locations', $state)));
        $this->assertSame(['301', '401'], $this->ids($this->rows('marketplace_listings', $state)));
        $this->assertSame(['2', '3'], $this->ids($this->rows('marketplace_account_identities', $state)));
        $this->assertSame(['6001'], $this->ids($this->rows('allegro_parameter_selections', $state)));
        $this->assertSame(['900'], $this->ids($this->rows('orders', $state)));
        $items = $this->rows('order_items', $state);
        $this->assertSame(['9001', '9002'], $this->ids($items));
        $this->assertSame('HISTORIC-SKU', array_column($items, 'sku', 'id')['9002']);
        $this->assertSame('in_sample', array_column($items, 'sample_part_reference', 'id')['9001']);
        $this->assertSame('outside_sample', array_column($items, 'sample_part_reference', 'id')['9002']);
        $this->assertSame(['500'], $this->ids($this->rows('customers', $state)));
        $this->assertSame('buyer@example.test', $this->rows('customers', $state)[0]['email']);
        $this->assertSame(['8000'], $this->ids($this->rows('payments', $state)));

        $artifacts = $this->artifacts($state);
        $this->assertCount(count($artifacts), array_unique(array_column($artifacts, 'archive_path')), 'Archive index must contain each logical sample file once');
        $partCsv = array_values(array_filter($artifacts, fn ($file) => preg_match('~^sample/datasets/parts/[^/]+\.csv$~', $file['path'])));
        $this->assertNotEmpty($partCsv);
        foreach ($partCsv as $file) {
            $this->assertStringStartsWith("\xEF\xBB\xBF", file_get_contents($this->folder.'/'.$file['path']));
        }
        $this->assertStringContainsString('00001', implode('', array_map(fn ($file) => file_get_contents($this->folder.'/'.$file['path']), $partCsv)));

        $photoRows = [];
        $photoIds = [];
        foreach ($artifacts as $file) {
            $this->assertStringNotContainsString('..', $file['path']);
            $this->assertFalse((bool) preg_match('~(?:^|/)(?:state|owner|checkpoint|sample-checkpoint)\.json$~', $file['archive_path']), 'Private checkpoints must not become sample payload');
            $path = $this->folder.'/'.$file['path'];
            $this->assertFileExists($path);
            $this->assertSame(filesize($path), $file['bytes']);
            $this->assertSame(hash_file('sha256', $path), $file['sha256']);
            if (str_starts_with($file['path'], 'images/')) {
                $this->assertContains(explode('/', $file['path'])[1], $ids);
            }
            if (str_starts_with($file['path'], 'sample/') && preg_match('/photo.*\.jsonl$/', $file['path'])) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($row['image_id'], $row['part_id'])) {
                        $photoIds[] = $row['image_id'];
                        $photoRows[$row['image_id']] = $row;
                    }
                }
            }
        }
        $this->assertCount(count($photoIds), array_unique($photoIds), 'Resuming must not duplicate logical photo manifest rows');
        $this->assertGreaterThanOrEqual(2, count(array_filter($photoRows, fn ($row) => $row['part_id'] === '005')));
        $this->assertNotContains('999', array_column($photoRows, 'part_id'));
        $this->assertSame('original-005-b.png', $photoRows['005-b']['original_filename']);

        foreach ($sources as $path => $sha) {
            $this->assertSame($sha, hash_file('sha256', $path), 'Source snapshot and copied originals remain unchanged');
        }
        $again = app(BrowserMigrationSample::class)->tick($this->folder, $this->attempt);
        $this->assertTrue($again['done']);
        $this->assertSame($state['sample_ids'], $again['sample_ids']);
        $this->assertSame($artifacts, $this->artifacts($again));
        $this->assertSame($previous, $this->sampleDatasetFiles());
        Http::assertNothingSent();
    }

    public function test_corrupted_frozen_shard_cannot_be_silently_used_as_sample_data(): void
    {
        $sources = $this->fixture();
        $partShard = array_values(array_filter(array_keys($sources), fn ($path) => str_contains($path, '/datasets/parts/') && str_ends_with($path, '.json')))[0];
        file_put_contents($partShard, '[{"id":"intruder","sku":"changed-after-freeze"}]');
        $failed = false;
        for ($i = 0; $i < 1200; $i++) {
            try {
                $state = app(BrowserMigrationSample::class)->tick($this->folder, $this->attempt);
            } catch (\RuntimeException) {
                $failed = true;
                break;
            }
            if ($state['done'] ?? false) {
                break;
            }
            if (! empty($state['last_error']) || ! empty($state['failed'])) {
                $failed = true;
                break;
            }
        }
        $this->assertTrue($failed, 'A checksum mismatch must block projection rather than export changed data');
        Http::assertNothingSent();
    }

    public function test_canonical_photo_manifest_after_retry_wins_over_stale_initial_failures(): void
    {
        $this->fixture();
        $canonical = file_get_contents($this->folder.'/photos.jsonl');
        file_put_contents($this->folder.'/photo-manifest.jsonl', $canonical);
        $old = array_map(function ($line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $row['status'] = 'COPY_FAILED';
            $row['bytes'] = null;
            $row['sha256'] = null;

            return json_encode($row, JSON_THROW_ON_ERROR);
        }, file($this->folder.'/photos.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        file_put_contents($this->folder.'/photos.jsonl', implode("\n", $old)."\n");
        $oldHash = hash_file('sha256', $this->folder.'/photos.jsonl');
        DB::connection()->beforeExecuting(fn () => throw new LogicException('Sample must use only frozen files'));
        for ($i = 0; $i < 1200; $i++) {
            $state = app(BrowserMigrationSample::class)->tick($this->folder, $this->attempt);
            if ($state['done']) {
                break;
            }
        }
        $this->assertTrue($state['done']);
        $ids = array_map('strval', $state['sample_ids']);
        sort($ids);
        $this->assertSame(['001', '002', '003', '004', '005'], $ids, 'Gallery selection must use the successful retry manifest');
        $rows = array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($this->folder.'/sample/photo-manifest.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertCount(6, $rows);
        $this->assertSame(['COPIED_VERIFIED'], array_values(array_unique(array_column($rows, 'status'))));
        foreach ($rows as $photo) {
            $this->assertSame($photo['sha256'], hash_file('sha256', $this->folder.'/'.$photo['package_path']));
        }
        $this->assertSame('', file_get_contents($this->folder.'/sample/missing-originals.jsonl'));
        $this->assertSame($oldHash, hash_file('sha256', $this->folder.'/photos.jsonl'));
        $this->assertSame(hash('sha256', $canonical), hash_file('sha256', $this->folder.'/photo-manifest.jsonl'));
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        $tables = [
            'parts' => [
                ['id' => '000', 'sku' => 'FILLER-FIRST', 'name' => 'Ordinary filler before diverse cases', 'status' => 'draft', 'needs_listing' => false, 'car_id' => '999', 'category_id' => '99', 'storage_location_id' => '99'],
                ['id' => '001', 'sku' => '00001', 'name' => 'Sold part', 'status' => 'sold', 'needs_listing' => false, 'is_sold_by_panel_rule' => true, 'car_id' => '100', 'category_id' => '20', 'storage_location_id' => '30'],
                ['id' => '002', 'sku' => '00002', 'name' => 'Needs listing', 'status' => 'draft', 'needs_listing' => true, 'is_to_list_by_panel_rule' => true, 'car_id' => '100', 'category_id' => '20', 'storage_location_id' => '30'],
                ['id' => '003', 'sku' => '00003', 'name' => 'eBay part', 'status' => 'published', 'needs_listing' => false, 'car_id' => '100', 'category_id' => '20', 'storage_location_id' => '30'],
                ['id' => '004', 'sku' => '00004', 'name' => 'Ovoko part', 'status' => 'published', 'needs_listing' => false, 'car_id' => '101', 'category_id' => '20', 'storage_location_id' => '30'],
                ['id' => '005', 'sku' => '00005', 'name' => 'Gallery part', 'status' => 'ready', 'needs_listing' => false, 'car_id' => '101', 'category_id' => '20', 'storage_location_id' => '30'],
                ['id' => '999', 'sku' => 'UNRELATED', 'name' => 'Outside sample', 'status' => 'draft', 'needs_listing' => false, 'car_id' => '999', 'category_id' => '99', 'storage_location_id' => '99'],
            ],
            'cars' => [['id' => '100', 'ovoko_car_id' => 'OVOKO-100'], ['id' => '101', 'ovoko_car_id' => 'OVOKO-101'], ['id' => '999', 'ovoko_car_id' => 'UNRELATED']],
            'part_categories' => [['id' => '10', 'name' => 'Ancestor', 'parent_id' => null], ['id' => '20', 'name' => 'Leaf', 'parent_id' => '10'], ['id' => '99', 'name' => 'Unrelated', 'parent_id' => null]],
            'storage_locations' => [['id' => '30', 'name' => 'Shelf A'], ['id' => '99', 'name' => 'Other']],
            'marketplace_listings' => [['id' => '301', 'part_id' => '003', 'marketplace' => 'ebay_de', 'external_id' => 'EBAY-001', 'marketplace_account_id' => '2'], ['id' => '401', 'part_id' => '004', 'marketplace' => 'ovoko', 'external_id' => 'OVOKO-001', 'marketplace_account_id' => '3'], ['id' => '9991', 'part_id' => '999', 'marketplace' => 'allegro', 'marketplace_account_id' => '4']],
            'allegro_parameter_selections' => [['id' => '6001', 'part_id' => '001', 'parameter_id' => '123', 'value' => 'used'], ['id' => '6999', 'part_id' => '999', 'value' => 'unrelated']],
            'orders' => [['id' => '900', 'customer_id' => '500', 'status' => 'paid', 'currency' => 'PLN'], ['id' => '901', 'customer_id' => '500', 'status' => 'paid', 'currency' => 'PLN']],
            'order_items' => [['id' => '9001', 'order_id' => '900', 'part_id' => '001', 'sku' => '00001'], ['id' => '9002', 'order_id' => '900', 'part_id' => '999', 'sku' => 'HISTORIC-SKU'], ['id' => '9011', 'order_id' => '901', 'part_id' => '999', 'sku' => 'UNRELATED']],
            'customers' => [['id' => '500', 'email' => 'buyer@example.test', 'name' => 'Order Buyer'], ['id' => '600', 'email' => 'unrelated@example.test']],
            'payments' => [['id' => '8000', 'order_id' => '900', 'amount' => '100.00', 'status' => 'paid'], ['id' => '8001', 'order_id' => '901', 'amount' => '200.00', 'status' => 'paid']],
            'marketplace_account_identities' => [['id' => '2', 'code' => 'ebay_de'], ['id' => '3', 'code' => 'ovoko_main'], ['id' => '4', 'code' => 'allegro_main']],
        ];
        $relations = [
            'parts' => ['car_id' => 'cars', 'category_id' => 'part_categories', 'storage_location_id' => 'storage_locations'],
            'part_categories' => ['parent_id' => 'part_categories'],
            'marketplace_listings' => ['part_id' => 'parts', 'marketplace_account_id' => 'marketplace_account_identities'],
            'allegro_parameter_selections' => ['part_id' => 'parts'], 'orders' => ['customer_id' => 'customers'],
            'order_items' => ['order_id' => 'orders', 'part_id' => 'parts'], 'payments' => ['order_id' => 'orders'],
        ];
        $descriptors = [];
        foreach ($tables as $name => $rows) {
            $columns = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
            $descriptor = ['name' => $name, 'available' => true, 'key_columns' => ['id'], 'relations' => $relations[$name] ?? [], 'total' => count($rows), 'processed' => count($rows), 'verified' => count($rows), 'exported_columns' => $columns, 'csv_columns' => $columns, 'shards' => [], 'done' => true];
            foreach (array_chunk($rows, $name === 'part_categories' ? 1 : 2) as $number => $chunk) {
                $relative = 'logical-snapshot/'.$this->attempt.'/datasets/'.$name.'/'.sprintf('%06d', $number).'.json';
                $path = $this->folder.'/'.$relative;
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                $json = json_encode($chunk, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                file_put_contents($path, $json);
                $csvPath = substr($relative, 0, -5).'.csv';
                file_put_contents($this->folder.'/'.$csvPath, "\xEF\xBB\xBF".implode(';', $columns)."\n");
                $descriptor['shards'][] = ['path' => $relative, 'sha256' => hash('sha256', $json), 'bytes' => strlen($json), 'rows' => count($chunk), 'csv_path' => $csvPath, 'csv_sha256' => hash_file('sha256', $this->folder.'/'.$csvPath), 'csv_bytes' => filesize($this->folder.'/'.$csvPath)];
            }
            $descriptors[$name] = $descriptor;
        }
        $index = ['format' => 'gpswiss-wystawczesc-logical-3', 'attempt_id' => $this->attempt, 'stage' => 'completed', 'dataset_names' => array_keys($tables), 'dataset_index' => count($tables), 'datasets' => $descriptors,
            'database_consistency' => 'frozen_logical_per_dataset_boundaries_verified_second_pass_not_transactional', 'cutoff_database_time' => '2026-11-10 00:00:00', 'last_success' => '2026-11-10T00:00:00+00:00', 'last_error' => null, 'done' => true];
        file_put_contents($this->folder.'/logical-snapshot/'.$this->attempt.'/checkpoint.json', json_encode($index, JSON_THROW_ON_ERROR));
        file_put_contents($this->folder.'/snapshot.json', json_encode($index, JSON_THROW_ON_ERROR));
        $photos = [];
        foreach (['000-a' => '000', '001-a' => '001', '002-a' => '002', '003-a' => '003', '004-a' => '004', '005-a' => '005', '005-b' => '005', '999-a' => '999'] as $image => $part) {
            $path = 'images/'.$part.'/original-'.$image.'.png';
            if (! is_dir(dirname($this->folder.'/'.$path))) {
                mkdir(dirname($this->folder.'/'.$path), 0700, true);
            }
            $bytes = base64_decode(self::PNG);
            file_put_contents($this->folder.'/'.$path, $bytes);
            $photos[] = ['part_id' => $part, 'image_id' => $image, 'original_filename' => 'original-'.$image.'.png', 'package_path' => $path, 'sort_order' => str_ends_with($image, '-b') ? 1 : 0, 'is_primary' => ! str_ends_with($image, '-b'), 'status' => 'COPIED_VERIFIED', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        file_put_contents($this->folder.'/photos.jsonl', implode("\n", array_map(fn ($photo) => json_encode($photo, JSON_THROW_ON_ERROR), $photos))."\n");
        $sources = [];
        foreach (File::allFiles($this->folder) as $file) {
            $sources[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }

        return $sources;
    }

    private function sampleDatasetFiles(): array
    {
        $files = [];
        foreach (glob($this->folder.'/sample/datasets/*/*.json') ?: [] as $path) {
            $files[$path] = hash_file('sha256', $path);
        }

        return $files;
    }

    private function rows(string $dataset, array $state): array
    {
        $rows = [];
        foreach ($this->artifacts($state) as $file) {
            if (preg_match('~^sample/datasets/'.preg_quote($dataset, '~').'/[^/]+\.json$~', $file['path'])) {
                array_push($rows, ...json_decode(file_get_contents($this->folder.'/'.$file['path']), true, 512, JSON_THROW_ON_ERROR));
            }
        }

        return $rows;
    }

    private function ids(array $rows): array
    {
        $ids = array_map('strval', array_column($rows, 'id'));
        sort($ids);

        return $ids;
    }

    private function artifacts(array $state): array
    {
        $path = $this->folder.'/'.$state['index_file'];
        $this->assertFileExists($path);

        return array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}
