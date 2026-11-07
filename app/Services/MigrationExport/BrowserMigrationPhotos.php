<?php

namespace App\Services\MigrationExport;

use Illuminate\Support\Str;
use RuntimeException;

/** Resumable local copies: at most 2 MiB per copy/mirror step, no shell or provider. */
final class BrowserMigrationPhotos
{
    private const MAX_FILE = 100 * 1024 * 1024;

    public function __construct(private MigrationOriginals $originals) {}

    /** Tasks JSONL must be immutable, private and already contain frozen source fingerprints. */
    public function tick(string $directory, string $tasksFile, ?float $deadline = null, string $generation = 'initial'): array
    {
        $directory = realpath($directory) ?: throw new RuntimeException('photo_directory_missing');
        $work = $this->workDirectory($directory, $generation, true);
        $tasksPath = $this->inside($directory, $tasksFile);
        $fingerprint = $this->fingerprint($tasksPath);
        $checkpoint = $work.'/photo-checkpoint.json';
        $state = is_file($checkpoint) ? $this->read($checkpoint) : ['tasks_fingerprint' => $fingerprint, 'task_offset' => 0, 'processed' => 0, 'journal_bytes' => 0, 'current' => null, 'status_counts' => [], 'done' => false, 'last_success' => null, 'tasks_file' => $tasksFile, 'generation' => $generation, 'results_file' => ($generation === 'initial' ? 'photos.jsonl' : 'photo-retries/'.substr($generation, 6).'/photos.jsonl')];
        if ($state['tasks_fingerprint'] !== $fingerprint) {
            throw new RuntimeException('photo_tasks_changed');
        }
        if ($state['done']) {
            return $this->result($state);
        }
        if ($deadline !== null && microtime(true) >= $deadline) {
            return $this->result($state);
        }
        $journal = $directory.'/'.$state['results_file'];
        if (! is_file($journal)) {
            touch($journal);
            chmod($journal, 0600);
        }
        $journalHandle = fopen($journal, 'c+b');
        try {
            if (fstat($journalHandle)['size'] < $state['journal_bytes']) {
                throw new RuntimeException('photo_journal_truncated');
            }
            // Recover an interrupted append whose checkpoint was never committed.
            if (! ftruncate($journalHandle, $state['journal_bytes']) || fseek($journalHandle, $state['journal_bytes']) !== 0) {
                throw new RuntimeException('photo_journal_repair_failed');
            }
            if ($state['current'] === null) {
                $stream = fopen($tasksPath, 'rb');
                fseek($stream, $state['task_offset']);
                $line = fgets($stream, 1024 * 1024);
                $next = ftell($stream);
                fclose($stream);
                if ($line === false) {
                    $state['done'] = true;
                    $this->write($checkpoint, $state);

                    return $this->result($state);
                }
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('photo_task_line_too_large_or_incomplete');
                }
                $task = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                $this->relative($task['package_path'] ?? '');
                if (! str_starts_with($task['package_path'], 'images/')) {
                    throw new RuntimeException('photo_package_path_unsafe');
                }
                $state['current'] = ['task' => $task, 'next_offset' => $next, 'copy_index' => 0, 'offset' => 0];
            }
            $receiptDir = $work.'/photo-receipts';
            if (! is_dir($receiptDir)) {
                mkdir($receiptDir, 0700, true);
            }
            $receipt = $receiptDir.'/'.sprintf('%08d.json', $state['processed'] + 1);
            $task = $state['current']['task'];
            $key = $this->resultKey($task);
            $initialRecord = $directory.'/photo-initial-results/'.$key.'.json';
            $outcome = is_file($receipt) ? $this->read($receipt) : null;
            if ($outcome === null && $generation !== 'initial') {
                if (! is_file($initialRecord)) {
                    throw new RuntimeException('photo_retry_initial_result_missing');
                }
                $initial = $this->read($initialRecord);
                if (! hash_equals($initial['source_fingerprint'], hash('sha256', json_encode($task['source'], JSON_THROW_ON_ERROR)))) {
                    throw new RuntimeException('photo_retry_frozen_source_changed');
                }
                $prior = $this->latestResult($directory, $initial['result']);
                if ($prior['status'] === 'COPIED_VERIFIED' || ! $this->retryable($prior)) {
                    $outcome = $prior;
                } else {
                    $state['current']['allow_target_repair'] = true;
                }
            }
            $outcome ??= $this->step($directory, $state['current']);
            if ($outcome !== null) {
                $outcome = $this->describeOutcome($outcome);
                $this->write($receipt, $outcome);
                $resultDirectory = $directory.'/'.($generation === 'initial' ? 'photo-initial-results' : 'photo-result-overrides');
                if (! is_dir($resultDirectory) && ! mkdir($resultDirectory, 0700, true) && ! is_dir($resultDirectory)) {
                    throw new RuntimeException('photo_result_directory_failed');
                }
                $this->write($resultDirectory.'/'.$key.'.json', $generation === 'initial' ? ['source_fingerprint' => hash('sha256', json_encode($task['source'], JSON_THROW_ON_ERROR)), 'result' => $outcome] : $outcome);
                $encoded = json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                if (fwrite($journalHandle, $encoded) !== strlen($encoded)) {
                    throw new RuntimeException('photo_journal_write_failed');
                }
                if (! fflush($journalHandle)) {
                    throw new RuntimeException('photo_journal_flush_failed');
                }
                $state['journal_bytes'] += strlen($encoded);
                $state['task_offset'] = $state['current']['next_offset'];
                $state['current'] = null;
                $state['processed']++;
                if ($outcome['status'] === 'COPIED_VERIFIED') {
                    $state['last_success'] = now()->toIso8601String();
                }
                $state['status_counts'][$outcome['status']] = ($state['status_counts'][$outcome['status']] ?? 0) + 1;
            }
            $this->write($checkpoint, $state);
        } finally {
            fclose($journalHandle);
        }

        return $this->result($state);
    }

    public function status(string $directory, string $generation = 'initial'): array
    {
        $work = $this->workDirectory($directory, $generation, false);
        if (! is_file($work.'/photo-checkpoint.json')) {
            return ['done' => false, 'processed' => 0, 'results_count' => 0, 'result_counts' => [], 'photo_results' => [], 'last_success' => null, 'current_image_offset' => null, 'task_offset' => 0, 'results_file' => $generation === 'initial' ? 'photos.jsonl' : 'photo-retries/'.substr($generation, 6).'/photos.jsonl'];
        }

        return $this->result($this->read($work.'/photo-checkpoint.json'));
    }

    /** Apply a bounded per-image retry overlay; caller streams the initial canonical journal once. */
    public function latestResult(string $directory, array $row): array
    {
        $file = $directory.'/photo-result-overrides/'.$this->resultKey($row).'.json';

        return is_file($file) ? $this->read($file) : $row;
    }

    public function retryable(array $row): bool
    {
        return in_array($row['status'] ?? '', ['COPY_FAILED', 'UNREADABLE_ORIGINAL', 'INSUFFICIENT_DISK_SPACE'], true);
    }

    private function resultKey(array $row): string
    {
        if (($row['entity_type'] ?? null) === 'car') {
            return hash('sha256', 'car:'.($row['car_id'] ?? '').':'.($row['image_id'] ?? ''));
        }

        return hash('sha256', (string) ($row['part_id'] ?? '').':'.(string) ($row['image_id'] ?? ''));
    }

    private function workDirectory(string $directory, string $generation, bool $create): string
    {
        if ($generation === 'initial') {
            return $directory;
        }
        if (! str_starts_with($generation, 'retry-') || ! Str::isUuid(substr($generation, 6))) {
            throw new RuntimeException('photo_generation_invalid');
        }
        $work = $directory.'/photo-retries/'.substr($generation, 6);
        if ($create && ! is_dir($work) && ! mkdir($work, 0700, true) && ! is_dir($work)) {
            throw new RuntimeException('photo_retry_directory_failed');
        }

        return $work;
    }

    private function step(string $directory, array &$current): ?array
    {
        $task = $current['task'];
        $base = array_intersect_key($task, array_flip(['entity_type', 'entity_id', 'car_id', 'image_id', 'part_id', 'role', 'sort_order', 'is_primary', 'alt_text', 'original_filename', 'original_relative_path', 'package_path']));
        $base += ['status' => $task['source']['status'], 'bytes' => null, 'sha256' => null, 'mime' => null];
        if ($task['source']['status'] !== 'LOCATED') {
            return $base;
        }
        $copies = $task['source']['copies'] ?? [];
        $length = (int) ($copies[0]['bytes'] ?? 0);
        if ($length <= 0) {
            return array_replace($base, ['status' => 'INVALID_IMAGE']);
        }
        if ($length > self::MAX_FILE) {
            return array_replace($base, ['status' => 'OVERSIZE_ORIGINAL']);
        }
        $current['phase'] ??= 'source';
        if ($current['phase'] === 'source') {
            foreach ($copies as $copy) {
                $source = realpath($copy['path']);
                if (! $source || ! collect($this->originals->roots())->contains(fn ($r) => str_starts_with($source, $r.DIRECTORY_SEPARATOR)) || $this->fingerprint($source) !== $copy['fingerprint']) {
                    return array_replace($base, ['status' => 'SOURCE_CHANGED']);
                }
            }
        }
        $target = $directory.'/'.$task['package_path'];
        $partial = $target.'.partial';
        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException('photo_target_directory_failed');
        }
        $parent = realpath(dirname($target));
        if (! $parent || ! str_starts_with($parent, $directory.DIRECTORY_SEPARATOR) || is_link($target) || is_link($partial)) {
            throw new RuntimeException('photo_target_path_unsafe');
        }
        $chunkSize = max(64 * 1024, min(2 * 1024 * 1024, (int) config('migration_export.photo_chunk_bytes', 512 * 1024)));
        $current['phase'] ??= 'source';
        $current['original_context'] ??= SerializableSha256::initial();
        $current['reuse_existing'] ??= ! ($current['allow_target_repair'] ?? false) && is_file($target) && filesize($target) === $length;
        $working = $current['reuse_existing'] ? $target : $partial;
        if ($current['phase'] === 'source' && $current['copy_index'] < count($copies)) {
            $copy = $copies[$current['copy_index']];
            if ((int) $copy['bytes'] !== $length) {
                return array_replace($base, ['status' => 'CONFLICTING_COPIES']);
            }
            $input = @fopen($copy['path'], 'rb');
            if (! $input) {
                return array_replace($base, ['status' => 'UNREADABLE_ORIGINAL']);
            }
            fseek($input, $current['offset']);
            $take = min($chunkSize, $length - $current['offset']);
            try {
                $chunk = fread($input, $take);
            } finally {
                fclose($input);
            }
            if (! is_string($chunk) || strlen($chunk) !== $take || $this->fingerprint($copy['path']) !== $copy['fingerprint']) {
                return array_replace($base, ['status' => 'SOURCE_CHANGED']);
            }
            if ($current['copy_index'] === 0 && ! $current['reuse_existing']) {
                if (disk_free_space($directory) < strlen($chunk) + 64 * 1024 * 1024) {
                    return array_replace($base, ['status' => 'INSUFFICIENT_DISK_SPACE']);
                }
                $output = fopen($partial, 'c+b');
                if (! $output) {
                    throw new RuntimeException('photo_partial_open_failed');
                }
                if (! chmod($partial, 0600)) {
                    fclose($output);
                    throw new RuntimeException('photo_partial_permissions_failed');
                }
                if ($current['offset'] === 0 && ! ftruncate($output, 0)) {
                    fclose($output);
                    throw new RuntimeException('photo_partial_truncate_failed');
                }
                if (fseek($output, $current['offset']) !== 0) {
                    fclose($output);
                    throw new RuntimeException('photo_partial_seek_failed');
                }
                try {
                    if (fwrite($output, $chunk) !== strlen($chunk) || ! fflush($output)) {
                        throw new RuntimeException('photo_partial_write_failed');
                    }
                } finally {
                    fclose($output);
                }
            } else {
                $existing = @fopen($working, 'rb');
                if (! $existing) {
                    return array_replace($base, ['status' => 'COPY_FAILED']);
                }
                fseek($existing, $current['offset']);
                try {
                    $reference = fread($existing, $take);
                } finally {
                    fclose($existing);
                }
                if (! is_string($reference) || ! hash_equals(hash('sha256', $reference), hash('sha256', $chunk))) {
                    return array_replace($base, ['status' => $current['copy_index'] === 0 ? 'COPY_FAILED' : 'CONFLICTING_COPIES']);
                }
            }
            if ($current['copy_index'] === 0) {
                $current['original_context'] = SerializableSha256::update($current['original_context'], $chunk);
            }
            $current['offset'] += strlen($chunk);
            if ($current['offset'] === $length) {
                $current['copy_index']++;
                $current['offset'] = 0;
                if ($current['copy_index'] === 1) {
                    $current['original_sha256'] = SerializableSha256::digest($current['original_context']);
                    $current['working_fingerprint'] = $this->fingerprint($working);
                }
                if ($current['copy_index'] === count($copies)) {
                    $current['phase'] = 'verify';
                    $current['verification_context'] = SerializableSha256::initial();
                }
            }

            return null;
        }
        if ($current['phase'] === 'source') {
            $current['phase'] = 'verify';
            $current['offset'] = 0;
            $current['verification_context'] = SerializableSha256::initial();
        }
        if (! is_file($working) && ! $current['reuse_existing'] && is_file($target)) {
            $working = $target;
            $actual = $this->fingerprint($working);
            $expected = $current['working_fingerprint'];
            unset($actual['ctime'], $expected['ctime']);
            if ($actual !== $expected) {
                return array_replace($base, ['status' => 'COPY_FAILED']);
            }
            $current['working_fingerprint'] = $this->fingerprint($working);
        } // rename-before-checkpoint recovery
        if ($this->fingerprint($working) !== $current['working_fingerprint']) {
            return array_replace($base, ['status' => 'COPY_FAILED']);
        }
        $input = @fopen($working, 'rb');
        if (! $input || fseek($input, $current['offset']) !== 0) {
            if (is_resource($input)) {
                fclose($input);
            }

            return array_replace($base, ['status' => 'COPY_FAILED']);
        }
        $take = min($chunkSize, $length - $current['offset']);
        try {
            $chunk = fread($input, $take);
        } finally {
            fclose($input);
        }
        if (! is_string($chunk) || strlen($chunk) !== $take) {
            return array_replace($base, ['status' => 'COPY_FAILED']);
        }
        $current['verification_context'] = SerializableSha256::update($current['verification_context'], $chunk);
        $current['offset'] += strlen($chunk);
        if ($current['offset'] !== $length) {
            return null;
        }
        $sha = SerializableSha256::digest($current['verification_context']);
        if (! hash_equals($current['original_sha256'], $sha)) {
            return array_replace($base, ['status' => 'COPY_FAILED', 'failure_reason' => 'checksum_mismatch']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($working);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || @getimagesize($working) === false) {
            return array_replace($base, ['status' => 'INVALID_IMAGE']);
        }
        if ($working === $partial && ! rename($partial, $target)) {
            throw new RuntimeException('photo_publish_failed');
        }
        if (! chmod($target, 0600)) {
            throw new RuntimeException('photo_publish_permissions_failed');
        }

        return array_replace($base, ['status' => 'COPIED_VERIFIED', 'bytes' => $length, 'sha256' => $sha, 'mime' => $mime, 'copied_at' => now()->toIso8601String()]);
    }

    private function describeOutcome(array $row): array
    {
        $status = $row['status'];
        $row['extension'] = strtolower(pathinfo($row['original_filename'] ?? '', PATHINFO_EXTENSION)) ?: null;
        $row['export_status'] = match (true) {
            $status === 'COPIED_VERIFIED' => 'copied',
            $status === 'MISSING_ORIGINAL' => 'missing',
            in_array($status, ['UNREADABLE_ORIGINAL', 'UNREADABLE_OR_UNSAFE'], true) => 'unreadable',
            ($row['failure_reason'] ?? null) === 'checksum_mismatch' => 'checksum_failed',
            default => 'skipped',
        };
        $row['skipped_reason'] = $status === 'COPIED_VERIFIED' ? null : ($row['failure_reason'] ?? strtolower($status));

        return $row;
    }

    private function result(array $state): array
    {
        return ['done' => $state['done'], 'processed' => $state['processed'], 'task_offset' => $state['task_offset'], 'current_image_phase' => $state['current']['phase'] ?? null, 'current_image_offset' => $state['current']['offset'] ?? null, 'current_copy_index' => $state['current']['copy_index'] ?? null, 'photo_results' => $state['status_counts'], 'result_counts' => $state['status_counts'], 'results_count' => $state['processed'], 'last_success' => $state['last_success'] ?? null, 'tasks_jsonl' => $state['tasks_file'] ?? null, 'generation' => $state['generation'] ?? 'initial', 'results_file' => $state['results_file'] ?? 'photos.jsonl'];
    }

    private function inside(string $directory, string $relative): string
    {
        $this->relative($relative);
        $path = realpath($directory.'/'.$relative);
        if (! $path || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('photo_tasks_path_unsafe');
        }

        return $path;
    }

    private function relative(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0") || in_array('..', explode('/', $path), true) || preg_match('/^[a-z]+:/i', $path)) {
            throw new RuntimeException('photo_package_path_unsafe');
        }
    }

    private function fingerprint(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? [] : array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    private function read(string $file): array
    {
        return json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    }

    private function write(string $file, array $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($file.'.tmp', $json, LOCK_EX) !== strlen($json) || ! chmod($file.'.tmp', 0600) || ! rename($file.'.tmp', $file)) {
            @unlink($file.'.tmp');
            throw new RuntimeException('photo_checkpoint_write_failed');
        }
    }
}
