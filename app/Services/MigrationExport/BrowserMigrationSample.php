<?php

namespace App\Services\MigrationExport;

use Illuminate\Support\Str;
use RuntimeException;

/** Actual five-product projection of verified frozen shards. No database, source-image copies, or APIs. */
final class BrowserMigrationSample
{
    private const CHILD_TARGETS = ['orders', 'shop_events', 'shipments', 'payments', 'order_payments', 'refunds', 'order_refunds', 'returns', 'order_returns', 'customer_returns', 'local_sales'];

    private const PHOTO_COLUMNS = ['part_id', 'image_id', 'role', 'original_filename', 'original_relative_path', 'package_path', 'sort_order', 'is_primary', 'status', 'bytes', 'sha256', 'mime'];

    public function __construct(private BrowserMigrationSnapshot $snapshots) {}

    /** Caller holds the run lock. At most one frozen shard or twenty photo records per invocation. */
    public function tick(string $dir, string $attempt, ?float $deadline = null): array
    {
        $folder = $this->folder($dir, $attempt);
        $index = $this->snapshots->index($dir, $attempt);
        if (! $index['done'] || $index['failed']) {
            throw new RuntimeException('sample_requires_completed_snapshot');
        }
        $state = is_file($folder.'/checkpoint.json') ? $this->read($folder.'/checkpoint.json') : [
            'attempt_id' => $attempt, 'stage' => 'parts', 'dataset_index' => 0, 'shard' => 0, 'photo_offset' => 0,
            'processed' => 0, 'file_count' => 0, 'index_bytes' => 0, 'file_manifest_bytes' => 0, 'photo_json_bytes' => 0,
            'photo_csv_bytes' => 0, 'missing_bytes' => 0, 'candidates' => [], 'fillers' => [], 'sample_ids' => [],
            'wanted' => [], 'found' => [], 'counts' => [], 'photo_seen' => [], 'photo_count' => 0, 'missing_count' => 0,
            'pass' => 0, 'closure_changed' => false, 'last_success' => null,
        ];
        $files = [];
        if ($state['stage'] === 'completed' || ($deadline !== null && microtime(true) >= $deadline)) {
            return $this->result($state, $files);
        }
        $datasets = array_keys($index['datasets']);
        if ($state['stage'] === 'parts' || $state['stage'] === 'listings') {
            $name = $state['stage'] === 'parts' ? 'parts' : 'marketplace_listings';
            $shard = $index['datasets'][$name]['shards'][$state['shard']] ?? null;
            if ($shard) {
                foreach ($this->rows($dir, $shard) as $row) {
                    if ($name === 'parts') {
                        $id = (string) $row['id'];
                        $this->write($folder.'/members/'.hash('sha256', $id).'.json', ['id' => $id]);
                        if (count($state['fillers']) < 5) {
                            $state['fillers'][] = $id;
                        }
                        if (($row['status'] ?? null) === 'sold' || ($row['is_sold_by_panel_rule'] ?? false) === true) {
                            $state['candidates']['sold'] ??= $id;
                        }
                        if (in_array($row['needs_listing'] ?? false, [true, 1, '1'], true) || ($row['is_to_list_by_panel_rule'] ?? false) === true) {
                            $state['candidates']['to_list'] ??= $id;
                        }
                    } else {
                        $id = (string) ($row['part_id'] ?? '');
                        if ($id !== '' && is_file($folder.'/members/'.hash('sha256', $id).'.json')) {
                            foreach (['ebay', 'ovoko'] as $market) {
                                if (str_starts_with(strtolower((string) ($row['marketplace'] ?? '')), $market)) {
                                    $state['candidates'][$market] ??= $id;
                                }
                            }
                        }
                    }
                }
                $state['shard']++;
            } else {
                $state['stage'] = $state['stage'] === 'parts' ? 'listings' : 'gallery';
                $state['shard'] = 0;
            }
        } elseif ($state['stage'] === 'gallery') {
            [$records, $offset, $end] = $this->photos($dir, $state['photo_offset']);
            foreach ($records as [$position, $row]) {
                $id = (string) ($row['part_id'] ?? '');
                if (($row['status'] ?? null) !== 'COPIED_VERIFIED' || ($row['role'] ?? 'gallery') === 'code_photo'
                    || ! is_file($folder.'/members/'.hash('sha256', $id).'.json')) {
                    continue;
                }
                $path = $folder.'/gallery/'.hash('sha256', $id).'.json';
                $seen = is_file($path) ? $this->read($path) : ['first_offset' => $position, 'first_image' => (string) $row['image_id']];
                if ($position > $seen['first_offset'] && (string) $row['image_id'] !== $seen['first_image']) {
                    $seen['second_offset'] ??= $position;
                }
                $this->write($path, $seen);
                if (isset($seen['second_offset']) && (! isset($state['gallery_position']) || $seen['second_offset'] < $state['gallery_position'])) {
                    $state['gallery_position'] = $seen['second_offset'];
                    $state['candidates']['multiple_photos'] = $id;
                }
            }
            $state['photo_offset'] = $offset;
            // A complete batch resolves replayed gallery counters; later offsets cannot precede this pair.
            if ($end || isset($state['candidates']['multiple_photos'])) {
                foreach (['sold', 'to_list', 'ebay', 'ovoko', 'multiple_photos'] as $case) {
                    if (isset($state['candidates'][$case]) && ! in_array($state['candidates'][$case], $state['sample_ids'], true)) {
                        $state['sample_ids'][] = $state['candidates'][$case];
                    }
                }
                foreach ($state['fillers'] as $id) {
                    if (count($state['sample_ids']) < 5 && ! in_array($id, $state['sample_ids'], true)) {
                        $state['sample_ids'][] = $id;
                    }
                }
                foreach ($state['sample_ids'] as $id) {
                    $this->want($state, 'parts', $id);
                }
                $state['stage'] = 'closure';
                $state['dataset_index'] = $state['shard'] = 0;
            }
        } elseif ($state['stage'] === 'closure' || $state['stage'] === 'project') {
            $name = $datasets[$state['dataset_index']] ?? null;
            if ($name === null) {
                if ($state['stage'] === 'closure' && $state['closure_changed']) {
                    if (++$state['pass'] > 64) {
                        throw new RuntimeException('sample_relationship_depth_limit');
                    }
                    $state['closure_changed'] = false;
                    $state['dataset_index'] = $state['shard'] = 0;
                } else {
                    $state['stage'] = $state['stage'] === 'closure' ? 'project' : 'photos';
                    $state['dataset_index'] = $state['shard'] = 0;
                    $state['photo_offset'] = 0;
                }
            } else {
                $descriptor = $index['datasets'][$name];
                $shard = $descriptor['shards'][$state['shard']] ?? null;
                if (! $shard) {
                    $state['dataset_index']++;
                    $state['shard'] = 0;
                } else {
                    $picked = [];
                    foreach ($this->rows($dir, $shard) as $row) {
                        if (! $this->selected($name, $row, $descriptor, $state)) {
                            continue;
                        }
                        if ($state['stage'] === 'closure') {
                            $key = $this->identity($row, $descriptor);
                            if ($key !== null) {
                                $state['found'][$name][$key] = true;
                                $state['closure_changed'] = $this->want($state, $name, $key) || $state['closure_changed'];
                            }
                            foreach ($this->relations($descriptor) as $column => $target) {
                                if ($target !== 'parts' && isset($index['datasets'][$target])) {
                                    $state['closure_changed'] = $this->want($state, $target, $row[$column] ?? null) || $state['closure_changed'];
                                }
                            }
                        } else {
                            if ($name === 'order_items' && isset($row['part_id']) && $row['part_id'] !== null && $row['part_id'] !== '') {
                                $row['sample_part_reference'] = isset($state['wanted']['parts'][(string) $row['part_id']]) ? 'in_sample' : 'outside_sample';
                            }
                            $picked[] = $row;
                        }
                    }
                    if ($state['stage'] === 'project' && $picked !== []) {
                        $prefix = 'sample/datasets/'.$name.'/'.sprintf('%06d', $state['shard']);
                        $this->writeOutput($dir, $prefix.'.json', json_encode($picked, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $files);
                        $columns = array_values(array_unique([...($descriptor['csv_columns'] ?? $descriptor['exported_columns'] ?? []), ...array_merge(...array_map('array_keys', $picked))]));
                        $this->writeOutput($dir, $prefix.'.csv', $this->csv($picked, $columns), $files);
                        $state['counts'][$name] = ($state['counts'][$name] ?? 0) + count($picked);
                    }
                    $state['shard']++;
                }
            }
        } elseif ($state['stage'] === 'photos') {
            [$records, $offset, $end] = $this->photos($dir, $state['photo_offset']);
            $json = $missing = $csv = '';
            if ($state['photo_offset'] === 0) {
                $csv = $this->csv([], self::PHOTO_COLUMNS);
            }
            foreach ($records as [, $row]) {
                $key = (string) ($row['part_id'] ?? '').':'.(string) ($row['image_id'] ?? '');
                if (! isset($state['wanted']['parts'][(string) ($row['part_id'] ?? '')]) || isset($state['photo_seen'][$key])) {
                    continue;
                }
                $state['photo_seen'][$key] = true;
                $json .= json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                $csv .= $this->csvRows([$row], self::PHOTO_COLUMNS);
                $state['photo_count']++;
                if (($row['status'] ?? null) === 'COPIED_VERIFIED') {
                    $path = (string) ($row['package_path'] ?? '');
                    $this->safeImage($dir, $path, $row);
                    $files[] = ['path' => $path, 'archive_path' => $path, 'bytes' => $row['bytes'], 'sha256' => $row['sha256']];
                } else {
                    $missing .= json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                    $state['missing_count']++;
                }
            }
            foreach (['photo-manifest.jsonl' => [$json, 'photo_json_bytes'], 'photo-manifest.csv' => [$csv, 'photo_csv_bytes'], 'missing-originals.jsonl' => [$missing, 'missing_bytes']] as $name => [$content, $counter]) {
                $this->append($dir.'/sample/'.$name, $state[$counter], $content);
                $state[$counter] += strlen($content);
            }
            $state['photo_offset'] = $offset;
            if ($end) {
                $state['stage'] = 'metadata';
                foreach (['photo-manifest.jsonl', 'photo-manifest.csv', 'missing-originals.jsonl'] as $name) {
                    $files[] = $this->file($dir, 'sample/'.$name);
                }
            }
        } elseif ($state['stage'] === 'metadata') {
            $unresolved = [];
            foreach ($state['wanted'] as $name => $ids) {
                $missing = array_keys(array_diff_key($ids, $state['found'][$name] ?? []));
                if ($missing !== []) {
                    $unresolved[$name] = array_map('strval', $missing);
                }
            }
            $boundaries = [];
            foreach (array_keys($state['counts']) as $name) {
                $boundaries[$name] = array_intersect_key($index['datasets'][$name], array_flip(['max_key', 'boundary_frozen_at', 'checksum_partial', 'total', 'schema_sha256']));
            }
            $manifest = ['schema_version' => 'gpswiss-wystawczesc-sample-3', 'run_id' => basename($dir), 'snapshot_attempt_id' => $attempt,
                'sample_source_ids' => $state['sample_ids'], 'products_count' => count($state['sample_ids']), 'datasets_counts' => $state['counts'],
                'case_selection' => $state['candidates'], 'photo_associations' => $state['photo_count'], 'missing_originals' => $state['missing_count'],
                'photo_manifest' => 'photo-manifest.csv', 'photo_jsonl' => 'photo-manifest.jsonl', 'files' => 'files.jsonl', 'unresolved_references' => $unresolved,
                'database_consistency' => $index['database_consistency'], 'snapshot_limitations' => $index['limitations'] ?? [],
                'snapshot_cutoff_database_time' => $index['cutoff_database_time'] ?? null, 'source_dataset_boundaries' => $boundaries,
                'contains_customer_data' => true, 'access' => 'authenticated_owner_only_no_public_url', 'automatic_import_allowed' => false,
                'automatic_publish' => false, 'synchronization_enabled' => false, 'database_writes' => false, 'provider_calls' => false,
                'source_remains_independent_copy' => true, 'allegro_mapping_policy' => 'historical_only_never_activate',
                'ebay_ovoko_mapping_policy' => 'retain_offer_and_account_identity_pending_explicit_adoption',
                'projection_policy' => 'five selected products; related parents and order children; never expand all orders by customer; order siblings may reference products outside sample'];
            $this->writeOutput($dir, 'sample/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $files);
            $dictionary = ['csv' => ['encoding' => 'UTF-8 BOM', 'delimiter' => ';', 'null' => '\\N', 'arrays' => 'JSON', 'identifiers' => 'text; preserve leading zeros', 'formula_prefix' => 'apostrophe'], 'datasets' => []];
            foreach (array_keys($state['counts']) as $name) {
                $dictionary['datasets'][$name] = array_intersect_key($index['datasets'][$name], array_flip(['exported_columns', 'csv_columns', 'relations', 'column_definitions']));
            }
            $this->writeOutput($dir, 'sample/fields.json', json_encode($dictionary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $files);
            $this->writeOutput($dir, 'sample/README.txt', "Private sample: real frozen product records and related data.\nRead manifest.json, fields.json and photo-manifest.csv.\nRestore all sample ZIP volumes, concatenate fragments by the volume manifest, and verify SHA-256.\nIDs and SKU are text. CSV null is \\N; nested values are JSON.\nNo import, publishing or synchronization is authorized by this package.\n", $files);
            $state['stage'] = 'completed';
        }
        $this->recordFiles($dir, $state, $files);
        if ($state['stage'] === 'completed') {
            $file = $this->file($dir, 'sample/files.jsonl');
            $this->append($dir.'/sample-archive-input.jsonl', $state['index_bytes'], json_encode($file, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            $state['index_bytes'] += strlen(json_encode($file, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            $state['file_count']++;
            $files[] = $file;
        }
        $state['processed']++;
        $state['last_success'] = now()->toIso8601String();
        $this->write($folder.'/checkpoint.json', $state);

        return $this->result($state, $files);
    }

    public function status(string $dir, string $attempt): array
    {
        $path = $this->folder($dir, $attempt).'/checkpoint.json';

        return is_file($path) ? $this->result($this->read($path), []) : ['done' => false, 'processed' => 0, 'total' => null, 'stage' => 'parts', 'sample_ids' => [], 'files' => [], 'index_file' => 'sample-archive-input.jsonl', 'last_success' => null];
    }

    private function selected(string $name, array $row, array $descriptor, array $state): bool
    {
        $key = $this->identity($row, $descriptor);
        if ($key !== null && isset($state['wanted'][$name][$key])) {
            return true;
        }
        if ($name === 'parts') {
            return false;
        }
        if ($this->referencesPart($row, $state['wanted']['parts'] ?? [])) {
            return true;
        }
        if ($name === 'car_images' && isset($state['wanted']['cars'][(string) ($row['car_id'] ?? '')])) {
            return true;
        }
        if ($name === 'category_translations' && isset($state['wanted']['part_categories'][(string) ($row['category_id'] ?? '')])) {
            return true;
        }
        foreach ($this->relations($descriptor) as $column => $target) {
            if (in_array($target, self::CHILD_TARGETS, true) && isset($state['wanted'][$target][(string) ($row[$column] ?? '')])) {
                return true;
            }
        }

        return false;
    }

    private function relations(array $descriptor): array
    {
        $result = [];
        foreach ($descriptor['relations'] ?? [] as $column => $target) {
            $target = is_array($target) ? ($target['table'] ?? $target['references_table'] ?? null) : $target;
            if (is_string($target)) {
                $result[$column] = $target;
            }
        }

        return $result;
    }

    private function referencesPart(array $row, array $ids, int $depth = 0): bool
    {
        if ($depth > 16) {
            return false;
        }
        foreach ($row as $key => $value) {
            if (in_array($key, ['part_id', 'local_part_id'], true) && is_scalar($value) && isset($ids[(string) $value])) {
                return true;
            }
            if ($key === 'part_snapshot' && is_array($value) && isset($ids[(string) ($value['id'] ?? '')])) {
                return true;
            }
            if (is_array($value) && $this->referencesPart($value, $ids, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function want(array &$state, string $table, mixed $value): bool
    {
        if (! is_scalar($value) || (string) $value === '' || isset($state['wanted'][$table][(string) $value])) {
            return false;
        }
        $state['wanted'][$table][(string) $value] = true;
        if (array_sum(array_map('count', $state['wanted'])) > 20000) {
            throw new RuntimeException('sample_relationship_limit');
        }

        return true;
    }

    private function identity(array $row, array $descriptor): ?string
    {
        $column = array_key_exists('id', $row) ? 'id' : (count($descriptor['key_columns'] ?? []) === 1 ? $descriptor['key_columns'][0] : null);

        return $column !== null && isset($row[$column]) && is_scalar($row[$column]) ? (string) $row[$column] : null;
    }

    private function rows(string $dir, array $shard): array
    {
        $path = $this->inside($dir, $shard['path']);
        if (! hash_equals($shard['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('sample_snapshot_shard_corrupted');
        }

        return $this->read($path);
    }

    private function photos(string $dir, int $offset): array
    {
        // Root completes this canonical latest-result stream before entering the sample phase.
        $path = is_file($dir.'/photo-manifest.jsonl') ? $dir.'/photo-manifest.jsonl' : $dir.'/photos.jsonl';
        if (! is_file($path)) {
            return [[], 0, true];
        }
        $stream = fopen($path, 'rb');
        fseek($stream, $offset);
        $records = [];
        try {
            for ($n = 0; $n < 20; $n++) {
                $position = ftell($stream);
                $line = fgets($stream, 524288);
                if ($line === false) {
                    break;
                }
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('sample_photo_record_invalid');
                }
                $records[] = [$position, json_decode($line, true, 64, JSON_THROW_ON_ERROR)];
            }

            return [$records, ftell($stream), feof($stream)];
        } finally {
            fclose($stream);
        }
    }

    private function safeImage(string $dir, string $path, array $row): void
    {
        if (! str_starts_with($path, 'images/') || ! is_string($row['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256'])
            || filesize($this->inside($dir, $path)) !== $row['bytes']) {
            throw new RuntimeException('sample_verified_image_missing');
        }
    }

    private function writeOutput(string $dir, string $path, string $content, array &$files): void
    {
        $this->writeBytes($dir.'/'.$path, $content);
        $files[] = $this->file($dir, $path);
    }

    private function file(string $dir, string $path): array
    {
        return ['path' => $path, 'archive_path' => str_starts_with($path, 'sample/') ? substr($path, 7) : $path, 'bytes' => filesize($dir.'/'.$path), 'sha256' => hash_file('sha256', $dir.'/'.$path)];
    }

    private function recordFiles(string $dir, array &$state, array $files): void
    {
        $index = $manifest = '';
        foreach ($files as $file) {
            $index .= json_encode($file, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
            $manifest .= json_encode(['path' => $file['archive_path'], 'bytes' => $file['bytes'], 'sha256' => $file['sha256']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
        }
        $this->append($dir.'/sample-archive-input.jsonl', $state['index_bytes'], $index);
        $this->append($dir.'/sample/files.jsonl', $state['file_manifest_bytes'], $manifest);
        $state['index_bytes'] += strlen($index);
        $state['file_manifest_bytes'] += strlen($manifest);
        $state['file_count'] += count($files);
    }

    private function append(string $file, int $offset, string $content): void
    {
        $this->directory(dirname($file));
        $stream = fopen($file, 'c+b');
        try {
            if (fstat($stream)['size'] < $offset) {
                throw new RuntimeException('sample_checkpoint_artifact_missing');
            }
            if (! ftruncate($stream, $offset) || fseek($stream, $offset) !== 0 || ($content !== '' && fwrite($stream, $content) !== strlen($content)) || ! fflush($stream)) {
                throw new RuntimeException('sample_checkpoint_write_failed');
            }
            chmod($file, 0600);
        } finally {
            fclose($stream);
        }
    }

    private function csv(array $rows, array $columns): string
    {
        return "\xEF\xBB\xBF".$this->csvLine($columns).$this->csvRows($rows, $columns);
    }

    private function csvRows(array $rows, array $columns): string
    {
        $result = '';
        foreach ($rows as $row) {
            $result .= $this->csvLine(array_map(static function ($column) use ($row) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    return '\\N';
                }
                $value = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
                if (str_starts_with($value, '\\')) {
                    $value = '\\'.$value;
                }

                return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
            }, $columns));
        }

        return $result;
    }

    private function csvLine(array $values): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $values, ';', '"', '');
        rewind($stream);
        $value = stream_get_contents($stream);
        fclose($stream);

        return $value;
    }

    private function result(array $state, array $files): array
    {
        return ['done' => $state['stage'] === 'completed', 'stage' => $state['stage'], 'processed' => $state['processed'],
            'total' => $state['stage'] === 'completed' ? $state['processed'] : null, 'last_success' => $state['last_success'],
            'sample_ids' => $state['sample_ids'], 'file_count' => $state['file_count'], 'files' => $files, 'index_file' => 'sample-archive-input.jsonl'];
    }

    private function folder(string $dir, string $attempt): string
    {
        if (! Str::isUuid($attempt)) {
            throw new RuntimeException('invalid_snapshot_attempt');
        }
        $path = $dir.'/sample-work/'.$attempt;
        $this->directory($path);

        return $path;
    }

    private function inside(string $dir, string $path): string
    {
        if (str_starts_with($path, '/') || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('sample_artifact_path_invalid');
        }
        $resolved = realpath($dir.'/'.$path);
        if (! $resolved || ! is_file($resolved) || ! str_starts_with($resolved, realpath($dir).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('sample_artifact_path_invalid');
        }

        return $resolved;
    }

    private function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true)) {
            throw new RuntimeException('sample_directory_failed');
        }
    }

    private function read(string $path): array
    {
        return json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
    }

    private function write(string $path, array $data): void
    {
        $this->writeBytes($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function writeBytes(string $path, string $content): void
    {
        $this->directory(dirname($path));
        if (is_file($path) && hash_equals(hash('sha256', $content), hash_file('sha256', $path))) {
            return;
        }
        if (file_put_contents($path.'.pending', $content) !== strlen($content) || ! chmod($path.'.pending', 0600) || ! rename($path.'.pending', $path)) {
            throw new RuntimeException('sample_checkpoint_write_failed');
        }
    }
}
