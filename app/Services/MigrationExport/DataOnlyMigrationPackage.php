<?php

namespace App\Services\MigrationExport;

use App\Models\Order;

/** Packages sealed files only. No SQL, providers, source verification, parent locks or photo pipeline calls. */
final class DataOnlyMigrationPackage
{
    private const MTIME = 315532800;

    private const METADATA = ['fields.generated.json', 'statuses.json', 'sold-part-ids.csv', 'parts-to-list-ids.csv', 'migration-manifest.json', 'data-only-package-manifest.json', 'relation-issues.jsonl'];

    public function __construct(private GpswissMigrationExport $parents, private BrowserMigrationSnapshot $snapshots, private BrowserMigrationArchives $archives) {}

    public function start(int $owner, string $run, string $attempt): array
    {
        $parent = $this->parents->dataOnlyParent($owner, $run, $attempt);
        $directory = $parent.'/data-only/'.$attempt;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new MigrationExportException('data_only_directory_unavailable');
        }

        return $this->locked($directory, function () use ($directory, $parent, $run, $attempt): array {
            if (is_file($directory.'/checkpoint.json')) {
                $state = $this->load($directory.'/checkpoint.json');
                $this->checkParent($parent, $state);
                if ($state['done']) {
                    $state['done'] = false;
                    $state['phase'] = 'verify_volumes';
                    $state['verify_volume'] = 1;
                    $this->write($directory.'/checkpoint.json', $state);
                }

                return $this->reply($directory, $state);
            }
            $index = $this->snapshots->index($parent, $attempt);
            if (! ($index['done'] ?? false) || ($index['failed'] ?? false) || ($index['attempt_id'] ?? null) !== $attempt) {
                throw new MigrationExportException('data_only_capture_not_sealed');
            }
            foreach ($index['datasets'] as $name => $definition) {
                if (! preg_match('/\A[a-z][a-z0-9_]*\z/D', $name) || ($definition['processed'] ?? null) !== ($definition['total'] ?? null)) {
                    throw new MigrationExportException('data_only_capture_incomplete');
                }
            }
            $state = ['format' => 'gpswiss-wystawczesc-data-only-1', 'parent_run_id' => $run, 'parent_attempt_id' => $attempt, 'created_at' => now()->toIso8601String(),
                'parent_sha256' => hash_file('sha256', $parent.'/logical-snapshot/'.$attempt.'/checkpoint.json'), 'phase' => 'catalog', 'dataset' => 0, 'shard' => 0, 'files_checked' => 0,
                'records' => 0, 'total_records' => $index['exported_records'], 'file_bytes' => [], 'metadata_index' => 0, 'metadata_copy' => null, 'volume_count' => 0, 'volume_descriptors' => [], 'verify_volume' => 1, 'done' => false];
            $this->write($directory.'/parent-index.json', $index);
            $state['frozen_index_sha256'] = hash_file('sha256', $directory.'/parent-index.json');
            unset($index); // Do not hold two large snapshot indexes while reading optional parent metadata.
            $root = $this->load($parent.'/state.json');
            $metadata = array_intersect_key($root, array_flip(['relation_audit', 'consistency_exception']));
            if (is_file($parent.'/migration-manifest.json')) {
                $existing = $this->load($parent.'/migration-manifest.json');
                if (($existing['run_id'] ?? null) === $run && ($existing['snapshot_attempt_id'] ?? null) === $attempt) {
                    unset($existing['snapshot']); // The independently frozen index is authoritative and stored once.
                    $metadata['migration_manifest'] = $existing;
                    foreach (['fields.generated.json', 'statuses.json'] as $file) {
                        if (is_file($parent.'/'.$file)) {
                            $metadata[$file] = $this->load($parent.'/'.$file);
                        }
                    }
                }
            }
            $this->write($directory.'/parent-metadata.json', $metadata);
            $state['metadata_sha256'] = hash_file('sha256', $directory.'/parent-metadata.json');
            $this->append($directory, $state, 'archive-input.jsonl', '');
            $this->append($directory, $state, 'data-only-checksums.jsonl', '');
            foreach (['sold-part-ids.csv', 'parts-to-list-ids.csv'] as $name) {
                $this->append($directory, $state, $name, "\xEF\xBB\xBFpart_id\n");
            }
            $this->write($directory.'/checkpoint.json', $state);

            return $this->reply($directory, $state);
        });
    }

    public function tick(int $owner, string $run, string $attempt): array
    {
        [$parent, $directory] = $this->context($owner, $run, $attempt);

        return $this->locked($directory, function () use ($parent, $directory): array {
            $state = $this->load($directory.'/checkpoint.json');
            if ($state['done']) {
                return $this->reply($directory, $state);
            }
            $this->rollback($directory, $state);
            try {
                if ($state['phase'] === 'catalog') {
                    $this->catalog($parent, $directory, $state);
                } elseif ($state['phase'] === 'metadata') {
                    $this->metadata($parent, $directory, $state);
                } elseif ($state['phase'] === 'archive') {
                    $archive = $this->archives->tick($directory, 'archive-input.jsonl', microtime(true) + 2, options: $this->options($parent));
                    foreach ($archive['volumes'] as $volume) {
                        $state['volume_descriptors'][$volume['key']] ??= hash_file('sha256', $directory.'/'.$volume['descriptor_path']);
                    }
                    $state['volume_count'] = $archive['volume_count'];
                    if ($archive['done']) {
                        $state['phase'] = 'verify_volumes';
                    }
                } elseif ($state['phase'] === 'verify_volumes') {
                    $this->verifyVolume($parent, $directory, $state);
                }
            } catch (MigrationExportException $error) {
                throw $error;
            } catch (\RuntimeException $error) {
                if (preg_match('/\Aarchive_(?:artifact_|source_|repair_|index_|path_|private_)/', $error->getMessage())) {
                    throw new MigrationExportException('data_only_artifact_integrity_failed');
                }
                throw $error;
            }
            $this->write($directory.'/checkpoint.json', $state);

            return $this->reply($directory, $state);
        });
    }

    public function status(int $owner, string $run, string $attempt): array
    {
        [, $directory] = $this->context($owner, $run, $attempt);

        return $this->reply($directory, $this->load($directory.'/checkpoint.json'));
    }

    public function download(int $owner, string $run, string $attempt, string $key): array
    {
        [, $directory] = $this->context($owner, $run, $attempt);
        $state = $this->load($directory.'/checkpoint.json');
        if (! $state['done']) {
            throw new MigrationExportException('data_only_not_ready');
        }
        foreach ($this->downloads($directory, $state) as $artifact) {
            if ($artifact['key'] !== $key) {
                continue;
            }
            $path = $directory.'/'.$artifact['path'];
            if (! is_file($path) || ! hash_equals($artifact['sha256'], hash_file('sha256', $path))) {
                throw new MigrationExportException('data_only_volume_repair_required');
            }

            return ['path' => $path, 'name' => $artifact['name']];
        }
        throw new MigrationExportException('data_only_artifact_not_found', 404);
    }

    private function catalog(string $parent, string $directory, array &$state): void
    {
        $index = $this->load($directory.'/parent-index.json');
        $names = array_keys($index['datasets']);
        $deadline = microtime(true) + 2;
        for ($processed = 0; $processed < 10 && microtime(true) < $deadline; $processed++) {
            $name = $names[$state['dataset']] ?? null;
            if ($name === null) {
                if ($state['records'] !== $state['total_records']) {
                    throw new MigrationExportException('data_only_record_count_mismatch');
                }
                $state['phase'] = 'metadata';

                return;
            }
            $shard = $index['datasets'][$name]['shards'][$state['shard']] ?? null;
            if ($shard === null) {
                $state['dataset']++;
                $state['shard'] = 0;

                continue;
            }
            foreach (['path' => 'sha256', 'csv_path' => 'csv_sha256'] as $pathKey => $shaKey) {
                $relative = $shard[$pathKey] ?? '';
                $prefix = 'logical-snapshot/'.$state['parent_attempt_id'].'/datasets/'.$name.'/';
                if (! str_starts_with($relative, $prefix) || ! preg_match('/\A[0-9a-f]{64}\z/D', $shard[$shaKey] ?? '')) {
                    throw new MigrationExportException('data_only_descriptor_invalid');
                }
                $path = $this->source($parent, $relative);
                if (! hash_equals($shard[$shaKey], hash_file('sha256', $path))) {
                    throw new MigrationExportException('data_only_source_checksum_mismatch');
                }
                $this->addArtifact($directory, $state, $relative, $relative, $shard[$shaKey], filesize($path));
            }
            if ($name === 'parts') {
                $rows = $this->load($this->source($parent, $shard['path']));
                if (count($rows) !== $shard['rows']) {
                    throw new MigrationExportException('data_only_row_count_mismatch');
                }
                $groups = ['sold-part-ids.csv' => '', 'parts-to-list-ids.csv' => ''];
                foreach ($rows as $row) {
                    $id = $this->csvId((string) $row['id']);
                    if (($row['status'] ?? null) === 'sold') {
                        $groups['sold-part-ids.csv'] .= $id;
                    }
                    if (! empty($row['needs_listing'])) {
                        $groups['parts-to-list-ids.csv'] .= $id;
                    }
                }
                foreach ($groups as $file => $bytes) {
                    $this->append($directory, $state, $file, $bytes);
                }
            }
            $state['records'] += $shard['rows'];
            $state['shard']++;
        }
    }

    private function metadata(string $parent, string $directory, array &$state): void
    {
        $file = self::METADATA[$state['metadata_index']] ?? null;
        if ($file === null) {
            $path = $directory.'/data-only-checksums.jsonl';
            // The checksum catalogue describes data and metadata; its own checksum is in the volume map.
            $this->append($directory, $state, 'archive-input.jsonl', $this->json(['path' => 'data-only/'.$state['parent_attempt_id'].'/data-only-checksums.jsonl', 'archive_path' => 'data-only-checksums.jsonl', 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)])."\n");
            $state['phase'] = 'archive';

            return;
        }
        if ($file === 'relation-issues.jsonl') {
            if (! is_file($parent.'/'.$file)) {
                $state['metadata_index']++;

                return;
            }
            $copy = $state['metadata_copy'] ?? ['bytes' => filesize($parent.'/'.$file), 'offset' => 0];
            $stream = fopen($parent.'/'.$file, 'rb');
            fseek($stream, $copy['offset']);
            $bytes = $copy['offset'] < $copy['bytes'] ? fread($stream, min(256 * 1024, $copy['bytes'] - $copy['offset'])) : '';
            fclose($stream);
            if ($bytes === false || ($bytes === '' && $copy['offset'] < $copy['bytes'])) {
                throw new MigrationExportException('data_only_relation_report_truncated');
            }
            $this->append($directory, $state, $file, $bytes);
            $copy['offset'] += strlen($bytes);
            $state['metadata_copy'] = $copy;
            if ($copy['offset'] < $copy['bytes']) {
                return;
            }
        } elseif (! in_array($file, ['sold-part-ids.csv', 'parts-to-list-ids.csv'], true)) {
            $index = $this->load($directory.'/parent-index.json');
            if ($file === 'fields.generated.json') {
                $value = $this->load($directory.'/parent-metadata.json')[$file] ?? ['datasets' => array_map(fn ($d) => array_intersect_key($d, array_flip(['source_table', 'exported_columns', 'csv_columns', 'column_definitions', 'relations', 'omitted_columns'])), $index['datasets']), 'formats' => $this->formats()];
            } elseif ($file === 'statuses.json') {
                $value = $this->load($directory.'/parent-metadata.json')[$file] ?? ['part_statuses' => ['draft' => 'Draft', 'needs_review' => 'Needs review', 'ready' => 'Ready', 'published' => 'Published', 'sold' => 'Sold by the panel status rule', 'archived' => 'Archived'], 'order_statuses' => Order::STATUSES, 'source_counts' => $index['source_counts'], 'group_rules' => ['sold' => "parts.status === 'sold'", 'to_list' => 'parts.needs_listing = true']];
            } else {
                $value = $this->manifest($directory, $state, $index);
                if ($file === 'data-only-package-manifest.json') {
                    unset($value['snapshot']);
                    $value['datasets'] = array_map(fn ($d) => array_intersect_key($d, array_flip(['available', 'source_table', 'processed', 'total', 'dataset_generation', 'dataset_boundary_id'])), $index['datasets']);
                    $value['total_records'] = $state['total_records'];
                    $value['dataset_files_manifest'] = 'migration-manifest.json';
                }
            }
            $this->write($directory.'/'.$file, $value);
        }
        $path = $directory.'/'.$file;
        $this->addArtifact($directory, $state, 'data-only/'.$state['parent_attempt_id'].'/'.$file, $file, hash_file('sha256', $path), filesize($path));
        $state['metadata_artifacts'][$file] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        $state['metadata_index']++;
        $state['metadata_copy'] = null;
    }

    private function manifest(string $directory, array $state, array $index): array
    {
        $metadata = $this->load($directory.'/parent-metadata.json');
        $exception = $index['consistency_exception'] ?? $metadata['consistency_exception'] ?? null;
        $ids = array_values(array_unique(array_map('strval', $exception['recorded_ids'] ?? [])));
        $known = $exception['known_count_from_operator'] ?? null;
        $base = $metadata['migration_manifest'] ?? [];
        foreach (array_keys($base) as $key) {
            if (str_starts_with($key, 'photo') || in_array($key, ['missing_originals', 'package_catalog'], true)) {
                unset($base[$key]);
            }
        }

        return array_replace($base, ['schema_version' => 'gpswiss-wystawczesc-3', 'package_kind' => 'data_only', 'run_id' => $state['parent_run_id'], 'snapshot_attempt_id' => $state['parent_attempt_id'], 'parent_run_id' => $state['parent_run_id'], 'parent_attempt_id' => $state['parent_attempt_id'],
            'created_at' => $state['created_at'], 'parent_checkpoint_sha256' => $state['parent_sha256'], 'snapshot' => $index, 'format' => $this->formats(),
            'consistency_exception' => $exception, 'accepted_parts_drift' => ['accepted' => $exception['accepted_by_operator'] ?? null, 'known_count' => $known, 'recorded_ids' => $ids, 'unknown_count' => $exception['remaining_operator_declared_without_recorded_id'] ?? ($known === null ? null : max(0, $known - count($ids)))],
            'live_source_verification_skipped_by_operator' => $index['operator_finalization']['live_source_verification_skipped'] ?? null, 'database_consistency' => $index['database_consistency'] ?? null,
            'operator_finalization' => $index['operator_finalization'] ?? null, 'relation_audit_at_package_start' => $metadata['relation_audit'] ?? null, 'relation_issues' => 'relation-issues.jsonl if present; existing report only, not rerun',
            'metadata_origin' => 'matching stored metadata preserved when present; otherwise field dictionary/status labels derived from frozen index/exporter constants; group IDs from frozen parts; relation report copied as a bounded prefix',
            'photos_included' => false, 'photos_migrated_separately' => true, 'photo_files_required' => false, 'allegro_parameters_supplement_required' => true,
            'contains_customer_data' => true, 'access' => 'authenticated_owner_only_no_public_url', 'cutover_ready' => false, 'source_remains_independent_copy' => true,
            'automatic_import_allowed' => false, 'automatic_publish' => false, 'provider_calls' => false, 'business_database_reads' => false, 'database_writes' => false, 'marketplace_writes' => false, 'source_file_writes' => false,
            'checksums' => 'data-only-checksums.jsonl', 'archive_manifest' => 'data-only-archive-manifest.json']);
    }

    private function verifyVolume(string $parent, string $directory, array &$state): void
    {
        if ($state['verify_volume'] > $state['volume_count']) {
            $archive = $this->archives->status($directory);
            $volumes = [];
            foreach ($archive['volumes'] as $volume) {
                $number = (int) substr($volume['key'], -6);
                $descriptor = $this->archives->volume($directory, $number);
                $volumes[] = ['key' => $volume['key'], 'file' => $this->volumeName($state, $number), 'bytes' => $volume['bytes'], 'sha256' => $volume['sha256'], 'logical_files' => $descriptor['entries']];
            }
            $this->write($directory.'/data-only-archive-manifest.json', ['format' => 'gpswiss-wystawczesc-data-only-volumes-1', 'parent_run_id' => $state['parent_run_id'], 'parent_attempt_id' => $state['parent_attempt_id'], 'photos_included' => false, 'photos_migrated_separately' => true,
                'volume_count' => count($volumes), 'volumes' => $volumes, 'reassembly' => 'Unfragmented entries use archive_path. Concatenate fragmented zip_entry bytes by artifact_index/offset into archive_path; verify fragment and logical SHA-256. Never concatenate ZIP volumes.', 'contains_customer_data' => true, 'cutover_ready' => false]);
            $state['metadata_artifacts']['data-only-archive-manifest.json'] = ['bytes' => filesize($directory.'/data-only-archive-manifest.json'), 'sha256' => hash_file('sha256', $directory.'/data-only-archive-manifest.json')];
            $state['phase'] = 'completed';
            $state['done'] = true;

            return;
        }
        $number = $state['verify_volume'];
        $key = sprintf('volume-%06d', $number);
        $descriptorPath = $directory.'/archive-volumes/'.$key.'.json';
        if (! is_file($descriptorPath) || ! hash_equals($state['volume_descriptors'][$key], hash_file('sha256', $descriptorPath))) {
            throw new MigrationExportException('data_only_volume_descriptor_invalid');
        }
        $descriptor = $this->archives->volume($directory, $number);
        $expected = $this->archives->status($directory)['volumes'][$number - 1];
        if ($descriptor['sha256'] !== $expected['sha256'] || $descriptor['file'] !== $expected['path']) {
            throw new MigrationExportException('data_only_volume_descriptor_invalid');
        }
        $path = $directory.'/'.$descriptor['file'];
        if (! is_file($path) || ! hash_equals($descriptor['sha256'], hash_file('sha256', $path))) {
            $this->archives->repairVolume($directory, $number, $this->options($parent));
        }
        $state['verify_volume']++;
    }

    private function addArtifact(string $directory, array &$state, string $source, string $logical, string $sha, int $bytes): void
    {
        $this->append($directory, $state, 'archive-input.jsonl', $this->json(['path' => $source, 'archive_path' => $logical, 'sha256' => $sha, 'bytes' => $bytes])."\n");
        $this->append($directory, $state, 'data-only-checksums.jsonl', $this->json(['path' => $logical, 'sha256' => $sha, 'bytes' => $bytes])."\n");
        $state['files_checked']++;
    }

    private function downloads(string $directory, array $state): array
    {
        if (! $state['done']) {
            return [];
        }
        $downloads = [];
        foreach (['data-only-package-manifest.json', 'data-only-archive-manifest.json'] as $name) {
            $downloads[] = ['key' => substr($name, 0, -5), 'path' => $name, 'name' => $name] + $state['metadata_artifacts'][$name];
        }
        foreach ($this->archives->status($directory)['volumes'] as $volume) {
            $downloads[] = array_replace($volume, ['name' => $this->volumeName($state, (int) substr($volume['key'], -6))]);
        }

        return $downloads;
    }

    private function reply(string $directory, array $state): array
    {
        return array_intersect_key($state, array_flip(['format', 'parent_run_id', 'parent_attempt_id', 'phase', 'dataset', 'shard', 'files_checked', 'records', 'total_records', 'volume_count', 'verify_volume', 'done']))
            + ['next_action' => $state['done'] ? 'download' : 'tick', 'photos_included' => false, 'business_database_reads' => false, 'provider_calls' => false, 'downloads' => array_map(fn ($d) => array_diff_key($d, ['path' => true, 'descriptor_path' => true]), $this->downloads($directory, $state))];
    }

    private function context(int $owner, string $run, string $attempt): array
    {
        $parent = $this->parents->dataOnlyParent($owner, $run, $attempt);
        $directory = $parent.'/data-only/'.$attempt;
        if (! is_file($directory.'/checkpoint.json')) {
            throw new MigrationExportException('data_only_not_started', 404);
        }
        $this->checkParent($parent, $this->load($directory.'/checkpoint.json'));

        return [$parent, $directory];
    }

    private function checkParent(string $parent, array $state): void
    {
        $path = $parent.'/logical-snapshot/'.$state['parent_attempt_id'].'/checkpoint.json';
        $directory = $parent.'/data-only/'.$state['parent_attempt_id'];
        if (! is_file($path) || ! hash_equals($state['parent_sha256'], hash_file('sha256', $path))) {
            throw new MigrationExportException('data_only_parent_boundary_changed');
        }
        if (! hash_equals($state['frozen_index_sha256'], hash_file('sha256', $directory.'/parent-index.json'))) {
            throw new MigrationExportException('data_only_frozen_index_invalid');
        }
        if (! hash_equals($state['metadata_sha256'], hash_file('sha256', $directory.'/parent-metadata.json'))) {
            throw new MigrationExportException('data_only_frozen_metadata_invalid');
        }
    }

    private function rollback(string $directory, array $state): void
    {
        foreach ($state['file_bytes'] as $file => $bytes) {
            $path = $directory.'/'.$file;
            if (! is_file($path) || filesize($path) < $bytes) {
                throw new MigrationExportException('data_only_committed_file_missing_or_truncated');
            }
            if (filesize($path) > $bytes) {
                $stream = fopen($path, 'c+b');
                ftruncate($stream, $bytes);
                fclose($stream);
            }
        }
    }

    private function append(string $directory, array &$state, string $file, string $bytes): void
    {
        $stream = fopen($directory.'/'.$file, 'c+b');
        chmod($directory.'/'.$file, 0600);
        try {
            $offset = $state['file_bytes'][$file] ?? 0;
            if (! ftruncate($stream, $offset) || fseek($stream, $offset) !== 0 || ($bytes !== '' && fwrite($stream, $bytes) !== strlen($bytes)) || ! fflush($stream)) {
                throw new MigrationExportException('data_only_write_failed');
            }
            $state['file_bytes'][$file] = $offset + strlen($bytes);
        } finally {
            fclose($stream);
        }
    }

    private function source(string $parent, string $path): string
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new MigrationExportException('data_only_path_invalid');
        }
        $real = realpath($parent.'/'.$path);
        if (! $real || ! str_starts_with($real, realpath($parent).DIRECTORY_SEPARATOR) || ! is_file($real)) {
            throw new MigrationExportException('data_only_source_missing');
        }

        return $real;
    }

    private function locked(string $directory, callable $work): mixed
    {
        $lock = fopen($directory.'/lock', 'c');
        chmod($directory.'/lock', 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new MigrationExportException('data_only_busy');
        }
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(string $path): array
    {
        try {
            return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable) {
            throw new MigrationExportException('data_only_metadata_invalid');
        }
    }

    private function write(string $path, array $data): void
    {
        $bytes = $this->json($data);
        if (file_put_contents($path.'.tmp', $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
            throw new MigrationExportException('data_only_write_failed');
        }
    }

    private function options(string $parent): array
    {
        return ['source_directory' => $parent, 'entry_mtime' => self::MTIME, 'retain_source_paths' => true, 'max_entries' => 512];
    }

    private function volumeName(array $state, int $number): string
    {
        return 'gpswiss-wystawczesc-data-only-'.$state['parent_run_id'].'-part-'.sprintf('%04d', $number).'.zip';
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function formats(): array
    {
        return ['csv' => 'UTF-8 BOM; semicolon; JSON for arrays; \\N means null; literal leading backslash escaped; formula text prefixed with apostrophe', 'ids_and_codes' => 'text; preserve leading zeroes', 'dates' => 'as stored; no inferred timezone'];
    }

    private function csvId(string $id): string
    {
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, [$id], ';', '"', '');
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }
}
