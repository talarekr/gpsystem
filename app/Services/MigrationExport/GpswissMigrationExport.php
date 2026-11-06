<?php

namespace App\Services\MigrationExport;

use App\Models\Order;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/** Private, resumable filesystem artifacts only. No queue, DB writes, source mutations, API or importer. */
final class GpswissMigrationExport
{
    public function __construct(private GpswissSnapshot $snapshot, private MigrationOriginals $originals) {}

    public function start(int $owner, ?string $id = null): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('zip_extension_required');
        }
        $id ??= (string) Str::uuid();
        abort_unless(Str::isUuid($id), 422);
        $dir = $this->root().'/'.$id;
        if (is_file($dir.'/state.json')) {
            return $this->status($owner, $id);
        }
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $startLock = fopen($dir.'/lock', 'c');
        chmod($dir.'/lock', 0600);
        flock($startLock, LOCK_EX);
        if (is_file($dir.'/state.json')) {
            flock($startLock, LOCK_UN);
            fclose($startLock);

            return $this->status($owner, $id);
        }
        $state = ['schema_version' => 'gpswiss-wystawczesc-2', 'contains_customer_data' => true, 'run_id' => $id, 'owner_id' => $owner, 'state' => 'snapshot', 'processed_images' => 0, 'photo_results' => [], 'last_error' => null];
        $this->write($dir.'/state.json', $state);
        try {
            $data = $this->snapshot->capture();
            $parts = array_column($data['products'], null, 'id');
            $tasks = [];
            $orphans = [];
            foreach ($data['images'] as $image) {
                if (! isset($parts[$image['part_id']])) {
                    $orphans[] = $image['id'];

                    continue;
                }
                $tasks[] = $this->originals->task($image, $parts[$image['part_id']]);
            }
            foreach ($data['products'] as &$part) {
                if (! empty($part['code_photo_path'])) {
                    $tasks[] = $this->originals->task(['id' => 'code', 'part_id' => $part['id'], 'path' => $part['code_photo_path'], 'role' => 'code_photo'], $part);
                }
                unset($part['code_photo_path']);
            }
            unset($part, $data['images']);
            $usedPaths = [];
            foreach ($tasks as &$task) {
                $key = mb_strtolower($task['package_path']);
                if (isset($usedPaths[$key])) {
                    $task['package_path'] = dirname($task['package_path']).'/image-'.$task['image_id'].'/'.basename($task['package_path']);
                }
                $usedPaths[mb_strtolower($task['package_path'])] = true;
            }
            unset($task);
            $data['orphan_image_ids'] = $orphans;
            $this->write($dir.'/snapshot.json', $data);
            $this->write($dir.'/photo-tasks.json', $tasks);
            $state += ['snapshot_at' => $data['snapshot_at'], 'source_counts' => $data['source_counts'], 'total_images' => count($tasks), 'files_snapshot_note' => 'DB snapshot is consistent. File fingerprints captured afterwards; global filesystem freeze is not asserted.'];
            $state['state'] = 'photos';
            $this->write($dir.'/state.json', $state);
        } catch (\Throwable) {
            $state['state'] = 'failed';
            $state['last_error'] = 'snapshot_failed';
            $this->write($dir.'/state.json', $state);
            throw new RuntimeException('snapshot_failed');
        } finally {
            flock($startLock, LOCK_UN);
            fclose($startLock);
        }

        return $this->status($owner, $id);
    }

    public function tick(int $owner, string $id): array
    {
        $dir = $this->directory($owner, $id);
        $lock = fopen($dir.'/lock', 'c');
        chmod($dir.'/lock', 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return $this->status($owner, $id) + ['busy' => true];
        }
        try {
            $state = $this->read($dir.'/state.json');
            if (($state['schema_version'] ?? null) !== 'gpswiss-wystawczesc-2') {
                throw new RuntimeException('new_export_required_for_v2');
            }
            if (! in_array($state['state'], ['photos', 'archive'], true)) {
                return $this->status($owner, $id);
            }
            if ($state['state'] === 'photos') {
                $tasks = $this->read($dir.'/photo-tasks.json');
                $deadline = microtime(true) + 3;
                for ($n = 0; $n < 20 && $state['processed_images'] < count($tasks) && microtime(true) < $deadline; $n++) {
                    $result = $this->originals->copy($tasks[$state['processed_images']], $dir);
                    file_put_contents($dir.'/photos.jsonl', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
                    chmod($dir.'/photos.jsonl', 0600);
                    $state['processed_images']++;
                    $state['photo_results'][$result['status']] = ($state['photo_results'][$result['status']] ?? 0) + 1;
                    $this->write($dir.'/state.json', $state);
                }
                if ($state['processed_images'] === count($tasks)) {
                    $state['state'] = 'archive';
                }
            } else {
                $snapshot = $this->read($dir.'/snapshot.json');
                $photos = [];
                foreach ((is_file($dir.'/photos.jsonl') ? file($dir.'/photos.jsonl', FILE_IGNORE_NEW_LINES) : []) as $line) {
                    $photo = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $photos[$photo['part_id'].':'.$photo['image_id']] = $photo; // crash/replay does not duplicate a logical image
                }
                $photos = array_values($photos);
                $sample = $this->sampleIds($snapshot['products'], $photos);
                $this->package($dir, 'sample', $snapshot, $photos, $sample);
                $this->package($dir, 'full', $snapshot, $photos, array_column($snapshot['products'], 'id'));
                $state['state'] = 'completed';
                $state['sample_ids'] = $sample;
                $state['archives'] = [];
                foreach (['sample', 'full'] as $kind) {
                    $state['archives'][$kind] = ['bytes' => filesize($dir.'/'.$kind.'.zip'), 'sha256' => hash_file('sha256', $dir.'/'.$kind.'.zip')];
                }
            }
            $state['last_error'] = null;
            $this->write($dir.'/state.json', $state);
        } catch (\Throwable) {
            // Durable cursor remains resumable; never expose file paths, SQL, credentials or exception text.
            $state['last_error'] = 'export_tick_failed_resume_same_run';
            $this->write($dir.'/state.json', $state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $this->status($owner, $id);
    }

    public function status(int $owner, string $id): array
    {
        $state = $this->read($this->directory($owner, $id).'/state.json');
        unset($state['owner_id']);

        return $state + ['database_writes' => false, 'source_file_writes' => false, 'export_file_writes' => true, 'provider_calls' => false, 'marketplace_writes' => false, 'automatic_publish' => false, 'import_performed' => false];
    }

    public function download(int $owner, string $id, string $kind): string
    {
        abort_unless(in_array($kind, ['sample', 'full'], true), 422);
        $dir = $this->directory($owner, $id);
        abort_unless($this->read($dir.'/state.json')['state'] === 'completed' && is_file($dir.'/'.$kind.'.zip'), 409);

        return $dir.'/'.$kind.'.zip';
    }

    private function package(string $dir, string $kind, array $snapshot, array $photos, array $ids): void
    {
        $selected = array_fill_keys($ids, true);
        $products = array_values(array_filter($snapshot['products'], fn ($p) => isset($selected[$p['id']])));
        $photos = array_values(array_filter($photos, fn ($p) => isset($selected[$p['part_id']])));
        $photosByPart = [];
        foreach ($photos as $photo) {
            $photosByPart[$photo['part_id']][] = $photo;
        }
        $cars = array_column($snapshot['cars'], null, 'id');
        $categories = array_column($snapshot['categories'], null, 'id');
        $locations = array_column($snapshot['storage_locations'], null, 'id');
        $categoryIds = array_values(array_unique(array_filter(array_column($products, 'category_id'))));
        $issues = [];
        for ($i = 0; $i < count($categoryIds); $i++) {
            $category = $categories[$categoryIds[$i]] ?? null;
            if (! $category) {
                $issues[] = ['reason' => 'missing_category', 'source_id' => $categoryIds[$i]];

                continue;
            }
            $parent = $category['parent_id'] ?? null;
            if ($parent && ! in_array($parent, $categoryIds, true)) {
                $categoryIds[] = $parent;
            }
        }
        foreach ($categoryIds as $categoryId) {
            $seen = [];
            $cursor = $categoryId;
            while ($cursor && isset($categories[$cursor])) {
                if (isset($seen[$cursor])) {
                    $issues[] = ['reason' => 'category_cycle', 'source_id' => $cursor];
                    break;
                }
                $seen[$cursor] = true;
                $cursor = $categories[$cursor]['parent_id'] ?? null;
            }
        }
        foreach ($products as &$part) {
            $part['local_car_id'] = $part['car_id'] ?? null;
            $part['ovoko_car_id'] = $cars[$part['car_id']]['ovoko_car_id'] ?? null;
            $part['category_name'] = $categories[$part['category_id']]['name'] ?? null;
            $part['storage_location_name'] = $locations[$part['storage_location_id']]['name'] ?? null;
            $part['quality'] = $part['condition_notes'] ?? null;
            $part['images'] = $photosByPart[$part['id']] ?? [];
            if ($part['car_id'] && ! isset($cars[$part['car_id']])) {
                $issues[] = ['reason' => 'missing_car', 'part_id' => $part['id']];
            }
            if ($part['storage_location_id'] && ! isset($locations[$part['storage_location_id']])) {
                $issues[] = ['reason' => 'missing_storage_location', 'part_id' => $part['id']];
            }
        }
        unset($part);
        $base = $dir.'/'.$kind;
        if (! is_dir($base)) {
            mkdir($base, 0700);
        }
        $this->write($base.'/products.json', $products);
        $this->write($base.'/categories.json', $kind === 'full' ? array_values($categories) : array_values(array_intersect_key($categories, array_flip($categoryIds))));
        $this->write($base.'/cars.json', $kind === 'full' ? array_values($cars) : array_values(array_intersect_key($cars, array_flip(array_filter(array_column($products, 'car_id'))))));
        $this->write($base.'/storage-locations.json', $kind === 'full' ? array_values($locations) : array_values(array_intersect_key($locations, array_flip(array_filter(array_column($products, 'storage_location_id'))))));
        $this->write($base.'/photos.json', $photos);
        $this->write($base.'/marketplace-category-mappings.json', array_values(array_filter($snapshot['marketplace_category_mappings'], fn ($m) => in_array($m['local_category_id'], $categoryIds, true))));
        $this->csv($base.'/products.csv', $products, ['id', 'sku', 'name', 'part_number', 'additional_part_codes', 'category_id', 'category_name', 'quality', 'part_position', 'storage_location_id', 'storage_location_name', 'description', 'local_car_id', 'ovoko_car_id', 'price', 'currency', 'allegro_price', 'ovoko_price', 'ebay_price', 'allegro_shipping_rate_name', 'allegro_parameters', 'offers', 'status', 'quantity', 'images']);
        $this->csv($base.'/photos.csv', $photos, ['part_id', 'image_id', 'role', 'original_filename', 'original_relative_path', 'package_path', 'sort_order', 'is_primary', 'status', 'bytes', 'sha256', 'mime']);
        $datasets = MigrationPackageData::select($snapshot, $kind, $products, $categoryIds);
        foreach (['products' => $products, 'cars' => $kind === 'full' ? array_values($cars) : array_values(array_intersect_key($cars, array_flip(array_filter(array_column($products, 'car_id'))))), 'categories' => $kind === 'full' ? array_values($categories) : array_values(array_intersect_key($categories, array_flip($categoryIds))), 'storage-locations' => $kind === 'full' ? array_values($locations) : array_values(array_intersect_key($locations, array_flip(array_filter(array_column($products, 'storage_location_id')))))] as $name => $rows) {
            $this->csv($base.'/'.$name.'.csv', $rows, $this->fields($rows));
        }
        if (! is_dir($base.'/datasets')) {
            mkdir($base.'/datasets', 0700);
        }
        foreach ($datasets as $table => $rows) {
            $this->write($base.'/datasets/'.$table.'.json', $rows);
            $this->csv($base.'/datasets/'.$table.'.csv', $rows, $this->fields($rows, $snapshot['schema_inventory'][$table]['exported_columns'] ?? []));
        }
        $this->write($base.'/fields.generated.json', MigrationPackageData::dictionary($snapshot, array_merge($datasets, ['parts' => $products, 'cars' => $kind === 'full' ? array_values($cars) : array_values(array_intersect_key($cars, array_flip(array_filter(array_column($products, 'car_id'))))), 'part_categories' => array_values(array_intersect_key($categories, array_flip($categoryIds))), 'storage_locations' => array_values($locations), 'part_images' => $photos])));
        $this->write($base.'/statuses.json', ['part_statuses' => ['draft', 'needs_review', 'ready', 'published', 'sold', 'archived'], 'order_statuses' => Order::STATUSES, 'group_rules' => $snapshot['group_rules'] ?? [], 'source_status_counts' => $snapshot['source_counts'], 'source_dates' => 'preserved as stored; no inferred timezone conversion']);
        foreach (['sold-part-ids' => 'sold_part_ids', 'parts-to-list-ids' => 'parts_to_list_ids'] as $name => $group) {
            $groupIds = array_values(array_filter($snapshot['groups'][$group] ?? [], fn ($id) => isset($selected[(string) $id])));
            $this->write($base.'/'.$name.'.json', $groupIds);
            $this->csv($base.'/'.$name.'.csv', array_map(fn ($id) => ['part_id' => (string) $id], $groupIds), ['part_id']);
        }
        $missing = array_values(array_filter($photos, fn ($p) => $p['status'] !== 'COPIED_VERIFIED'));
        $this->write($base.'/missing-originals.json', $missing);
        $sourceSets = array_merge($snapshot['datasets'], ['parts' => $snapshot['products'], 'cars' => $snapshot['cars'], 'part_categories' => $snapshot['categories'], 'storage_locations' => $snapshot['storage_locations']]);
        $sourceIds = [];
        foreach ($sourceSets as $table => $rows) {
            $sourceIds[$table] = array_fill_keys(array_map('strval', array_column($rows, 'id')), true);
        }
        foreach ($datasets as $table => $rows) {
            foreach ($snapshot['relations'][$table] ?? [] as $column => $target) {
                if (! is_string($target) || ! isset($sourceIds[$target])) {
                    continue;
                }
                foreach ($rows as $row) {
                    $foreign = $row[$column] ?? null;
                    if ($foreign !== null && $foreign !== '' && ! isset($sourceIds[$target][(string) $foreign])) {
                        $issues[] = ['reason' => 'missing_source_reference', 'table' => $table, 'row_id' => $row['id'] ?? null, 'column' => $column, 'target_table' => $target, 'target_id' => (string) $foreign];
                    }
                }
            }
        }
        $this->write($base.'/dependency-issues.json', $issues);
        $schema = resource_path('migration-export.schema.json');
        copy($schema, $base.'/schema.json');
        copy(base_path('docs/migration/gpswiss-export-and-sync-stop.md'), $base.'/READ-ME.md');
        $hashes = [];
        foreach ($this->packageFiles($base) as $relative => $file) {
            if ($relative !== 'manifest.json') {
                $hashes[$relative] = hash_file('sha256', $file);
            }
        }
        $manifest = ['schema_version' => 'gpswiss-wystawczesc-2', 'run_id' => basename($dir), 'package_kind' => $kind, 'source_instance' => 'gpswiss.pl', 'source_environment' => $snapshot['source_environment'], 'source_host' => $snapshot['source_host'], 'privacy_review_required' => true, 'contains_customer_data' => true, 'access' => 'authenticated_owner_only_no_public_url', 'allegro_mapping_policy' => 'historical_only_never_activate', 'ebay_ovoko_mapping_policy' => 'preserve_exact_market_and_account_identity_no_execution',
            'snapshot_at' => $snapshot['snapshot_at'], 'snapshot_finished_at' => $snapshot['snapshot_finished_at'], 'database_consistency' => $snapshot['database_consistency'],
            'file_consistency' => 'fingerprints_captured_after_db_snapshot_verified_during_copy_not_global_fs_snapshot',
            'sample_case_coverage' => $this->caseCoverage($snapshot, $products), 'datasets_counts' => array_map('count', $datasets), 'schema_inventory' => $snapshot['schema_inventory'] ?? [], 'optional_tables_absent' => $snapshot['optional_tables_absent'] ?? [], 'relations' => $snapshot['relations'] ?? [], 'source_counts' => $snapshot['source_counts'], 'products_count' => count($products), 'sample_source_ids' => $kind === 'sample' ? $ids : [],
            'photo_associations' => count($photos), 'verified_originals' => count($photos) - count($missing), 'missing_or_blocked_originals' => count($missing), 'photo_status_counts' => array_count_values(array_column($photos, 'status')),
            'warnings' => $snapshot['warnings'], 'orphan_image_ids' => $snapshot['orphan_image_ids'], 'file_sha256' => $hashes,
            'automatic_import_allowed' => false, 'automatic_publish' => false, 'synchronization_enabled' => false, 'provider_calls' => false, 'database_writes' => false, 'source_file_writes' => false,
            'cutover_ready' => false, 'cutover_requires' => ['operator_review', 'global_filesystem_snapshot_or_coordinated_freeze', 'target_tenant_uuid', 'id_and_sku_collision_plan'],
            'source_remains_independent_copy' => true];
        $this->write($base.'/manifest.json', $manifest);
        $zip = new ZipArchive;
        if ($zip->open($dir.'/'.$kind.'.partial.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('archive_open_failed');
        }
        try {
            foreach ($this->packageFiles($base) as $relative => $file) {
                if (! $zip->addFile($file, $relative)) {
                    throw new RuntimeException('archive_add_failed');
                }
            }
            foreach ($photos as $photo) {
                if ($photo['status'] !== 'COPIED_VERIFIED') {
                    continue;
                }
                if (! $zip->addFile($dir.'/'.$photo['package_path'], $photo['package_path'])) {
                    throw new RuntimeException('archive_photo_failed');
                }
                $zip->setCompressionName($photo['package_path'], ZipArchive::CM_STORE); // Originals are not re-encoded/recompressed.
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('archive_close_failed');
            }
        }
        chmod($dir.'/'.$kind.'.partial.zip', 0600);
        rename($dir.'/'.$kind.'.partial.zip', $dir.'/'.$kind.'.zip');
    }

    private function sampleIds(array $parts, array $photos): array
    {
        $counts = array_count_values(array_column(array_filter($photos, fn ($p) => $p['role'] === 'gallery'), 'part_id'));
        $bad = array_column(array_filter($photos, fn ($p) => $p['status'] !== 'COPIED_VERIFIED'), 'part_id');
        $has = static fn ($p, $channel) => collect($p['offers'] ?? [])->contains(fn ($o) => str_starts_with((string) ($o['marketplace'] ?? ''), $channel));
        $ids = [];
        foreach ([fn ($p) => $p['status'] === 'sold', fn ($p) => ! empty($p['needs_listing']), fn ($p) => $has($p, 'ebay'), fn ($p) => $has($p, 'ovoko'), fn ($p) => ($counts[$p['id']] ?? 0) > 1, fn ($p) => in_array($p['id'], $bad, true)] as $predicate) {
            if (count($ids) >= 5) {
                break;
            }
            foreach ($parts as $part) {
                if (! in_array($part['id'], $ids, true) && $predicate($part)) {
                    $ids[] = (string) $part['id'];
                    break;
                }
            }
        }
        foreach ($parts as $part) {
            if (count($ids) < 5 && ! in_array($part['id'], $ids, true)) {
                $ids[] = (string) $part['id'];
            }
        }

        return $ids;
    }

    private function caseCoverage(array $snapshot, array $selected): array
    {
        $galleryCounts = array_count_values(array_column($snapshot['datasets']['part_images'] ?? [], 'part_id'));
        $hasOffer = static fn ($part, $channel) => collect($part['offers'] ?? [])->contains(fn ($offer) => str_starts_with((string) ($offer['marketplace'] ?? ''), $channel));
        $cases = ['sold' => fn ($p) => $p['status'] === 'sold', 'to_list' => fn ($p) => ! empty($p['needs_listing']),
            'ebay_offer' => fn ($p) => $hasOffer($p, 'ebay'), 'ovoko_offer' => fn ($p) => $hasOffer($p, 'ovoko'), 'multiple_photos' => fn ($p) => ($galleryCounts[$p['id']] ?? 0) > 1];
        $coverage = [];
        foreach ($cases as $name => $test) {
            $coverage[$name] = ['source_count' => count(array_filter($snapshot['products'], $test)), 'selected_ids' => array_values(array_column(array_filter($selected, $test), 'id'))];
        }

        return $coverage;
    }

    private function csv(string $path, array $rows, array $fields): void
    {
        $file = fopen($path, 'wb');
        fwrite($file, "\xEF\xBB\xBF");
        fputcsv($file, $fields, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($file, array_map(function ($field) use ($row) {
                $value = $row[$field] ?? null;
                if ($value === null) {
                    return '\\N';
                }
                $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
                if (str_starts_with($value, '\\')) {
                    $value = '\\'.$value;
                }

                return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
            }, $fields), ';', '"', '');
        }
        fclose($file);
        chmod($path, 0600);
    }

    private function fields(array $rows, array $fallback = []): array
    {
        return array_values(array_unique(array_merge($fallback, ...array_map('array_keys', $rows ?: [[]]))));
    }

    private function packageFiles(string $base): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($base) + 1)] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    private function root(): string
    {
        $root = config('migration_export.root', storage_path('app/private/migration-exports'));
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        foreach (array_unique([...$this->originals->roots(), realpath(public_path()) ?: public_path(), realpath(dirname(base_path()).'/public_html') ?: dirname(base_path()).'/public_html']) as $source) {
            if (realpath($root) === $source || str_starts_with(realpath($root), $source.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('export_root_must_be_private');
            }
        }

        if ((fileperms($root) & 0077) !== 0) {
            throw new RuntimeException('export_root_permissions_must_be_private');
        }

        return $root;
    }

    private function directory(int $owner, string $id): string
    {
        abort_unless(Str::isUuid($id), 404);
        $dir = $this->root().'/'.$id;
        abort_unless(is_file($dir.'/state.json'), 404);
        abort_unless($this->read($dir.'/state.json')['owner_id'] === $owner, 404);

        return $dir;
    }

    private function read(string $file): array
    {
        return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function write(string $file, array $data): void
    {
        file_put_contents($file.'.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        chmod($file.'.tmp', 0600);
        rename($file.'.tmp', $file);
    }
}
