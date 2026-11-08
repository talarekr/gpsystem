<?php

namespace App\Services\MigrationExport;

use Illuminate\Support\Str;

/** Derives private CSVs from sealed JSON shards only. Never enters the DB, photo or provider pipelines. */
final class ConsolidatedMigrationCsv
{
    private const FILES = ['czesci.csv', 'sprzedane.csv', 'do_wystawienia.csv', 'moje_samochody.csv', 'zdjecia_czesci.csv', 'zdjecia_czesci_issues.csv'];

    private const PHOTOS = ['part_id', 'image_id', 'original_name', 'source_path', 'sort_order', 'is_primary', 'role', 'source_type', 'mime_type', 'extension', 'roles', 'image_ids', 'source_metadata'];

    private const ISSUES = ['reason_code', 'part_id', 'image_id', 'source_path', 'source_metadata'];

    public function __construct(private GpswissMigrationExport $parents) {}

    public function start(int $owner, string $run, string $attempt): array
    {
        $parent = $this->parents->dataOnlyParent($owner, $run, $attempt);
        $dir = $parent.'/consolidated-csv/'.$attempt;
        $this->mkdir($dir);

        return $this->locked($dir, function () use ($parent, $dir, $run, $attempt) {
            if (is_file($dir.'/checkpoint.json')) {
                $state = $this->context($parent, $dir, $attempt);
                if ($state['done']) {
                    // Reuse valid files; audit in bounded chunks before rebuilding only damaged outputs.
                    $state['done'] = false;
                    $state['phase'] = 'verify_outputs';
                    $state['audit'] = ['file' => 0, 'offset' => 0, 'hash' => SerializableSha256::initial()];
                    $state['rebuild'] = [];
                    $this->write($dir.'/checkpoint.json', $state);
                }

                return $this->reply($state);
            }
            $source = $parent.'/data-only/'.$attempt.'/parent-index.json';
            $package = $this->read($parent.'/data-only/'.$attempt.'/checkpoint.json');
            if (! hash_equals($package['frozen_index_sha256'] ?? '', (string) @hash_file('sha256', $source))) {
                throw new MigrationExportException('csv_frozen_index_invalid');
            }
            $index = $this->read($source);
            if (! ($index['done'] ?? false) || ($index['failed'] ?? false) || ($index['attempt_id'] ?? null) !== $attempt) {
                throw new MigrationExportException('csv_capture_not_sealed');
            }
            foreach (['parts', 'cars', 'part_images'] as $name) {
                $d = $index['datasets'][$name] ?? null;
                if (! is_array($d) || ($d['processed'] ?? null) !== ($d['total'] ?? null)) {
                    throw new MigrationExportException('csv_dataset_incomplete');
                }
            }
            $this->write($dir.'/frozen-index.json', $index);
            $state = ['run_id' => $run, 'attempt_id' => $attempt, 'source_sha256' => hash_file('sha256', $source),
                'index_sha256' => hash_file('sha256', $dir.'/frozen-index.json'), 'done' => false, 'files' => [], 'rebuild' => self::FILES];
            $this->initialize($dir, $state, $index);
            $this->write($dir.'/checkpoint.json', $state);

            return $this->reply($state);
        });
    }

    public function tick(int $owner, string $run, string $attempt): array
    {
        [$parent, $dir] = $this->paths($owner, $run, $attempt);

        return $this->locked($dir, function () use ($parent, $dir, $attempt) {
            $state = $this->context($parent, $dir, $attempt);
            if ($state['done']) {
                return $this->reply($state);
            }
            $index = $this->read($dir.'/frozen-index.json');
            if ($state['phase'] === 'verify_outputs') {
                $this->audit($dir, $state, $index);
            } else {
                $this->rollback($dir, $state);
                if ($state['phase'] === 'photo_metadata') {
                    $this->emitPhotos($dir, $state);
                } else {
                    $this->dataset($parent, $dir, $state, $index);
                }
            }
            $this->write($dir.'/checkpoint.json', $state);

            return $this->reply($state);
        });
    }

    public function status(int $owner, string $run, string $attempt): array
    {
        [$parent, $dir] = $this->paths($owner, $run, $attempt);

        return $this->reply($this->context($parent, $dir, $attempt));
    }

    public function download(int $owner, string $run, string $attempt, string $file): array
    {
        [$parent, $dir] = $this->paths($owner, $run, $attempt);
        $state = $this->context($parent, $dir, $attempt);
        if (! $state['done'] || ! in_array($file, self::FILES, true)) {
            throw new MigrationExportException('csv_not_ready');
        }
        if (! is_file($dir.'/'.$file) || ! hash_equals($state['files'][$file]['sha256'], (string) hash_file('sha256', $dir.'/'.$file))) {
            throw new MigrationExportException('csv_output_repair_required');
        }

        return ['path' => $dir.'/'.$file, 'name' => $file];
    }

    private function initialize(string $dir, array &$state, array $index): void
    {
        $state['phase'] = 'parts';
        $state['shard'] = $state['row'] = $state['processed'] = 0;
        $state['source_rows'] = ['parts' => 0, 'cars' => 0, 'part_images' => 0];
        $state['offsets'] = [];
        $state['work'] = 'work-'.Str::uuid();
        $this->mkdir($dir.'/'.$state['work'].'/ids');
        $this->mkdir($dir.'/'.$state['work'].'/buckets');
        $state['build_photos'] = in_array('zdjecia_czesci.csv', $state['rebuild'], true) || in_array('zdjecia_czesci_issues.csv', $state['rebuild'], true);
        foreach ($state['rebuild'] as $file) {
            $columns = match ($file) {
                'moje_samochody.csv' => $this->columns($index['datasets']['cars']),
                'zdjecia_czesci.csv' => self::PHOTOS,
                'zdjecia_czesci_issues.csv' => self::ISSUES,
                default => $this->columns($index['datasets']['parts']),
            };
            $state['files'][$file] = ['rows' => 0, 'bytes' => 0, 'hash' => SerializableSha256::initial()];
            $this->append($dir, $state, $file, "\xEF\xBB\xBF".$this->csv($columns));
        }
    }

    private function columns(array $definition): array
    {
        $columns = $definition['csv_columns'] ?? $definition['exported_columns'] ?? [];
        if (! $columns || count(array_unique($columns)) !== count($columns)) {
            throw new MigrationExportException('csv_schema_invalid');
        }

        return $columns;
    }

    private function dataset(string $parent, string $dir, array &$state, array $index): void
    {
        $name = $state['phase'];
        $definition = $index['datasets'][$name];
        $shard = $definition['shards'][$state['shard']] ?? null;
        if ($shard === null) {
            if ($state['source_rows'][$name] !== $definition['total']) {
                throw new MigrationExportException('csv_source_count_mismatch');
            }
            $state['phase'] = match ($name) {
                'parts' => 'cars', 'cars' => 'part_images', default => 'photo_metadata'
            };
            $state['shard'] = $state['row'] = 0;
            $state['bucket'] = 0;

            return;
        }
        $path = $this->source($parent, $shard['path'], $state['attempt_id'], $name);
        if (filesize($path) > 16 * 1024 * 1024 || ! hash_equals($shard['sha256'], hash_file('sha256', $path))) {
            throw new MigrationExportException('csv_shard_integrity_failed');
        }
        $rows = $this->read($path);
        if (! array_is_list($rows) || count($rows) !== $shard['rows']) {
            throw new MigrationExportException('csv_shard_row_count_mismatch');
        }
        $deadline = microtime(true) + 1.5;
        for ($n = 0; $n < 50 && isset($rows[$state['row']]) && microtime(true) < $deadline; $n++, $state['row']++) {
            $row = $rows[$state['row']];
            // Fail closed on schema drift rather than silently dropping any stored business field.
            if (array_diff(array_keys($row), $this->columns($definition))) {
                throw new MigrationExportException('csv_schema_columns_missing');
            }
            if ($name === 'parts') {
                $this->output($dir, $state, 'czesci.csv', $row, $this->columns($definition));
                if (($row['status'] ?? null) === 'sold') {
                    $this->output($dir, $state, 'sprzedane.csv', $row, $this->columns($definition));
                }
                // SQL booleans in old frozen shards can be 0/1; never infer from stock or listings.
                if (in_array($row['needs_listing'] ?? null, [true, 1, '1'], true)) {
                    $this->output($dir, $state, 'do_wystawienia.csv', $row, $this->columns($definition));
                }
                if ($state['build_photos']) {
                    $id = (string) ($row['id'] ?? '');
                    if ($id === '') {
                        throw new MigrationExportException('csv_part_identity_missing');
                    }
                    $this->write($dir.'/'.$state['work'].'/ids/'.hash('sha256', $id).'.json', ['id' => $id]);
                    foreach (['code_photo_path' => 'code_photo', 'main_photo_path' => 'main_photo'] as $field => $role) {
                        if (is_string($row[$field] ?? null) && $row[$field] !== '') {
                            $this->candidate($dir, $state, ['part_id' => $id, 'id' => $role === 'code_photo' ? 'code' : 'main', 'path' => $row[$field], 'role' => $role, 'is_primary' => $role === 'main_photo'], alias: true);
                        }
                    }
                }
            } elseif ($name === 'cars') {
                $this->output($dir, $state, 'moje_samochody.csv', $row, $this->columns($definition));
            } elseif ($state['build_photos']) {
                $this->candidate($dir, $state, $row);
            }
            $state['source_rows'][$name]++;
            $state['processed']++;
        }
        if ($state['row'] === count($rows)) {
            $state['shard']++;
            $state['row'] = 0;
        }
    }

    private function candidate(string $dir, array &$state, array $row, bool $alias = false): void
    {
        $part = $row['part_id'] ?? null;
        $path = $row['path'] ?? $row['source_path'] ?? null;
        if (! is_scalar($part) || (string) $part === '' || ! is_file($dir.'/'.$state['work'].'/ids/'.hash('sha256', (string) $part).'.json')) {
            $this->issue($dir, $state, 'orphan_image', $row, $path);

            return;
        }
        if (! is_string($path) || $path === '') {
            $this->issue($dir, $state, 'missing_source_path', $row, $path);

            return;
        }
        // Exact stored part/path pair. Never fetch, normalize or inspect the physical original.
        $key = hash('sha256', json_encode([(string) $part, $path], JSON_THROW_ON_ERROR));
        $file = $state['work'].'/buckets/'.substr($key, 0, 2).'.jsonl';
        $this->append($dir, $state, $file, json_encode(['key' => $key, 'row' => $row, 'alias' => $alias], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    private function issue(string $dir, array &$state, string $reason, array $row, mixed $path): void
    {
        $this->output($dir, $state, 'zdjecia_czesci_issues.csv', ['reason_code' => $reason, 'part_id' => $row['part_id'] ?? null, 'image_id' => $row['id'] ?? null, 'source_path' => $path, 'source_metadata' => $row], self::ISSUES);
    }

    private function emitPhotos(string $dir, array &$state): void
    {
        while ($state['bucket'] < 256 && ! isset($state['offsets'][$state['work'].'/buckets/'.sprintf('%02x', $state['bucket']).'.jsonl'])) {
            $state['bucket']++;
        }
        if (! $state['build_photos'] || $state['bucket'] >= 256) {
            foreach ($state['files'] as &$file) {
                if (isset($file['hash'])) {
                    $file['sha256'] = SerializableSha256::digest($file['hash']);
                    unset($file['hash']);
                }
            }
            unset($file);
            $state['done'] = true;
            $state['phase'] = 'completed';

            return;
        }
        $path = $state['work'].'/buckets/'.sprintf('%02x', $state['bucket']).'.jsonl';
        $merged = [];
        if (isset($state['offsets'][$path])) {
            if ($state['offsets'][$path] > 16 * 1024 * 1024) {
                throw new MigrationExportException('csv_photo_bucket_budget_exceeded');
            }
            $stream = fopen($dir.'/'.$path, 'rb');
            try {
                while (($line = fgets($stream)) !== false) {
                    $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    $key = $entry['key'];
                    $row = $entry['row'];
                    $previous = $merged[$key] ?? null;
                    $role = $row['role'] ?? 'gallery';
                    $primary = (bool) ($row['is_primary'] ?? false) || ($previous['is_primary'] ?? false);
                    // Gallery metadata takes precedence over a main/code alias; preserve every raw before-image.
                    $base = $previous === null || ! $entry['alias'] ? $row : $previous['_base'];
                    $merged[$key] = ['part_id' => (string) $row['part_id'], 'image_id' => $base['id'] ?? null,
                        'original_name' => $base['original_name'] ?? $base['original_filename'] ?? $base['file_name'] ?? basename($base['path'] ?? $base['source_path']),
                        'source_path' => $row['path'] ?? $row['source_path'], 'sort_order' => $base['sort_order'] ?? null, 'is_primary' => $primary,
                        'role' => $base['role'] ?? 'gallery', 'source_type' => $base['source_type'] ?? null, 'mime_type' => $base['mime_type'] ?? $base['mime'] ?? null, 'extension' => $base['extension'] ?? null,
                        'roles' => array_values(array_unique([...($previous['roles'] ?? []), $role])),
                        'image_ids' => array_values(array_unique([...($previous['image_ids'] ?? []), (string) ($row['id'] ?? '')])),
                        'source_metadata' => array_values(array_unique([...($previous['source_metadata'] ?? []), $row], SORT_REGULAR)), '_base' => $base];
                }
            } finally {
                fclose($stream);
            }
        }
        $rows = array_values($merged);
        $deadline = microtime(true) + 1.5;
        for ($n = 0; $n < 50 && isset($rows[$state['row']]) && microtime(true) < $deadline; $n++, $state['row']++) {
            $this->output($dir, $state, 'zdjecia_czesci.csv', $rows[$state['row']], self::PHOTOS);
        }
        if ($state['row'] === count($rows)) {
            $state['row'] = 0;
            $state['bucket']++;
        }
    }

    private function audit(string $dir, array &$state, array $index): void
    {
        $name = self::FILES[$state['audit']['file']] ?? null;
        if ($name === null) {
            if ($state['rebuild'] === []) {
                $state['phase'] = 'completed';
                $state['done'] = true;
            } else {
                $this->initialize($dir, $state, $index);
            }

            return;
        }
        $file = $dir.'/'.$name;
        $expected = $state['files'][$name];
        if (! is_file($file) || filesize($file) !== $expected['bytes']) {
            $state['rebuild'][] = $name;
        } else {
            $stream = fopen($file, 'rb');
            try {
                fseek($stream, $state['audit']['offset']);
                $bytes = fread($stream, 256 * 1024);
            } finally {
                fclose($stream);
            }
            $state['audit']['hash'] = SerializableSha256::update($state['audit']['hash'], $bytes);
            $state['audit']['offset'] += strlen($bytes);
            if ($state['audit']['offset'] < $expected['bytes']) {
                return;
            }
            if (! hash_equals($expected['sha256'], SerializableSha256::digest($state['audit']['hash']))) {
                $state['rebuild'][] = $name;
            }
        }
        $state['audit'] = ['file' => $state['audit']['file'] + 1, 'offset' => 0, 'hash' => SerializableSha256::initial()];
    }

    private function output(string $dir, array &$state, string $name, array $row, array $columns): void
    {
        if (! in_array($name, $state['rebuild'], true)) {
            return;
        }
        $this->append($dir, $state, $name, $this->csv(array_map(fn ($key) => $row[$key] ?? null, $columns)));
        $state['files'][$name]['rows']++;
    }

    private function csv(array $values): string
    {
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, array_map(function ($value) {
            if ($value === null) {
                return '\\N';
            }
            $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            if (str_starts_with($value, '\\')) {
                $value = '\\'.$value;
            }

            return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
        }, $values), ';', '"', '');
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    private function append(string $dir, array &$state, string $name, string $bytes): void
    {
        $stream = fopen($dir.'/'.$name, 'c+b');
        chmod($dir.'/'.$name, 0600);
        try {
            $offset = $state['offsets'][$name] ?? 0;
            if (! ftruncate($stream, $offset) || fseek($stream, $offset) !== 0 || fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream)) {
                throw new MigrationExportException('csv_write_failed');
            }
            $state['offsets'][$name] = $offset + strlen($bytes);
        } finally {
            fclose($stream);
        }
        if (isset($state['files'][$name])) {
            $state['files'][$name]['bytes'] = $offset + strlen($bytes);
            $state['files'][$name]['hash'] = SerializableSha256::update($state['files'][$name]['hash'], $bytes);
        }
    }

    private function rollback(string $dir, array $state): void
    {
        foreach ($state['offsets'] as $name => $bytes) {
            $path = $dir.'/'.$name;
            clearstatcache(true, $path);
            if (! is_file($path) || filesize($path) < $bytes) {
                throw new MigrationExportException('csv_committed_file_truncated');
            }
            if (filesize($path) > $bytes) {
                $stream = fopen($path, 'c+b');
                try {
                    if (! ftruncate($stream, $bytes)) {
                        throw new MigrationExportException('csv_rollback_failed');
                    }
                } finally {
                    fclose($stream);
                }
            }
        }
    }

    private function paths(int $owner, string $run, string $attempt): array
    {
        $parent = $this->parents->dataOnlyParent($owner, $run, $attempt);

        return [$parent, $parent.'/consolidated-csv/'.$attempt];
    }

    private function context(string $parent, string $dir, string $attempt): array
    {
        if (! is_file($dir.'/checkpoint.json')) {
            throw new MigrationExportException('csv_not_started', 404);
        }
        $state = $this->read($dir.'/checkpoint.json');
        if ($state['attempt_id'] !== $attempt || ! hash_equals($state['source_sha256'], (string) @hash_file('sha256', $parent.'/data-only/'.$attempt.'/parent-index.json')) || ! hash_equals($state['index_sha256'], (string) @hash_file('sha256', $dir.'/frozen-index.json'))) {
            throw new MigrationExportException('csv_source_identity_mismatch');
        }

        return $state;
    }

    private function source(string $parent, string $path, string $attempt, string $dataset): string
    {
        $prefix = 'logical-snapshot/'.$attempt.'/datasets/'.$dataset.'/';
        if (! str_starts_with($path, $prefix) || str_contains($path, '\\') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new MigrationExportException('csv_source_path_invalid');
        }
        $real = realpath($parent.'/'.$path);
        if (! $real || ! str_starts_with($real, realpath($parent).'/'.$prefix) || ! is_file($real)) {
            throw new MigrationExportException('csv_source_missing');
        }

        return $real;
    }

    private function reply(array $state): array
    {
        return array_intersect_key($state, array_flip(['run_id', 'attempt_id', 'phase', 'done', 'processed', 'shard', 'row', 'bucket'])) + [
            'next_action' => $state['done'] ? 'download' : 'tick',
            'downloads' => $state['done'] ? array_map(fn ($name, $file) => ['key' => $name, 'name' => $name, 'rows' => $file['rows'], 'bytes' => $file['bytes'], 'sha256' => $file['sha256']], array_keys($state['files']), array_values($state['files'])) : [],
            'business_database_reads' => false, 'database_writes' => false, 'provider_calls' => false, 'marketplace_writes' => false, 'source_file_writes' => false, 'export_file_writes' => true];
    }

    private function locked(string $dir, callable $work): mixed
    {
        $stream = fopen($dir.'/lock', 'c');
        chmod($dir.'/lock', 0600);
        if (! flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);
            throw new MigrationExportException('csv_busy');
        }
        try {
            return $work();
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function mkdir(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new MigrationExportException('csv_directory_unavailable');
        }
    }

    private function read(string $path): array
    {
        try {
            $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

            return is_array($value) ? $value : throw new \RuntimeException;
        } catch (\Throwable) {
            throw new MigrationExportException('csv_artifact_invalid');
        }
    }

    private function write(string $path, array $value): void
    {
        $pending = $path.'.pending';
        $bytes = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($pending, $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($pending, 0600) || ! rename($pending, $path)) {
            throw new MigrationExportException('csv_checkpoint_write_failed');
        }
    }
}
