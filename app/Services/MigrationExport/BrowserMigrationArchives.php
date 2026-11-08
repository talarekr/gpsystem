<?php

namespace App\Services\MigrationExport;

use RuntimeException;
use ZipArchive;

/** One small STORE ZIP per HTTP tick. Large logical files are losslessly fragmented, never renamed at source. */
final class BrowserMigrationArchives
{
    public const MAX_PAYLOAD = 8 * 1024 * 1024;

    public const MAX_ENTRIES = 20;

    /** Index is immutable JSONL: path(relative private file), archive_path, bytes, sha256(optional known whole-file hash). */
    public function tick(string $directory, string $indexFile, ?float $deadline = null, string $namespace = 'full', bool $containsCustomerData = true): array
    {
        if (! in_array($namespace, ['full', 'sample'], true)) {
            throw new RuntimeException('archive_namespace_invalid');
        }
        $prefix = $namespace === 'sample' ? 'sample-' : '';
        $directory = realpath($directory) ?: throw new RuntimeException('archive_directory_missing');
        $index = $this->source($directory, $indexFile);
        $fingerprint = $this->fingerprint($index);
        $checkpointFile = $directory.'/'.$prefix.'archive-checkpoint.json';
        $state = is_file($checkpointFile) ? $this->read($checkpointFile) : ['index_fingerprint' => $fingerprint, 'index_offset' => 0, 'artifact_count' => 0, 'volume_count' => 0, 'total_bytes' => 0, 'current' => null, 'done' => false, 'volumes' => [], 'last_success' => null, 'namespace' => $namespace];
        if ($state['index_fingerprint'] !== $fingerprint) {
            throw new RuntimeException('archive_index_changed');
        }
        if ($state['done']) {
            return $this->result($state);
        }
        $deadline ??= microtime(true) + 2.5;
        $payloadLimit = max(1024, min(self::MAX_PAYLOAD, (int) config('migration_export.archive_payload_bytes', self::MAX_PAYLOAD)));
        $volume = $state['volume_count'] + 1;
        $name = $prefix.sprintf('volume-%06d', $volume);
        $volumeRelative = $prefix.'archive-volumes';
        $volumes = $directory.'/'.$volumeRelative;
        if (! is_dir($volumes)) {
            mkdir($volumes, 0700, true);
        }
        $zipPath = $volumes.'/'.$name.'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath.'.partial', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('archive_open_failed');
        }
        $input = fopen($index, 'rb');
        fseek($input, $state['index_offset']);
        $entries = [];
        $temporaries = [];
        $bytes = 0;
        $eof = false;
        try {
            while (count($entries) < self::MAX_ENTRIES - 1 && $bytes < $payloadLimit && microtime(true) < $deadline) {
                if ($state['current'] === null) {
                    $line = fgets($input, 1024 * 1024);
                    if ($line === false) {
                        $eof = true;
                        break;
                    }
                    if (! str_ends_with($line, "\n")) {
                        throw new RuntimeException('archive_index_line_too_large_or_incomplete');
                    }
                    $entry = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                    $path = $this->source($directory, $entry['path'] ?? '');
                    $this->archivePath($entry['archive_path'] ?? '');
                    if (! isset($entry['bytes']) || (int) $entry['bytes'] !== filesize($path)) {
                        throw new RuntimeException('archive_artifact_size_changed');
                    }
                    $state['current'] = $entry + ['offset' => 0, 'fragment' => 0, 'hash_context' => SerializableSha256::initial(), 'fingerprint' => $this->fingerprint($path)];
                    $state['index_offset'] = ftell($input);
                }
                $entry = $state['current'];
                $path = $this->source($directory, $entry['path']);
                if ($this->fingerprint($path) !== $entry['fingerprint']) {
                    throw new RuntimeException('archive_artifact_changed');
                }
                $remaining = (int) $entry['bytes'] - (int) $entry['offset'];
                $split = (int) $entry['bytes'] > min($payloadLimit, 2 * 1024 * 1024);
                if (! $split && $remaining > $payloadLimit - $bytes) {
                    break;
                }
                $take = min($remaining, $payloadLimit - $bytes, $split ? 512 * 1024 : $remaining);
                $fragmentName = $split ? '__gpswiss_fragments/'.sprintf('%08d', $state['artifact_count'] + 1).'/'.sprintf('%06d', $entry['fragment'] + 1).'.bin' : $entry['archive_path'];
                $temporary = $volumes.'/'.$name.'-'.count($entries).'.fragment';
                $sha = $this->copyFragment($path, $temporary, (int) $entry['offset'], $take);
                $temporaries[] = $temporary;
                if (! $split && isset($entry['sha256']) && ! hash_equals((string) $entry['sha256'], $sha)) {
                    throw new RuntimeException('archive_artifact_hash_mismatch');
                }
                if ($this->fingerprint($path) !== $entry['fingerprint']) {
                    throw new RuntimeException('archive_artifact_changed');
                }
                if (! $zip->addFile($temporary, $fragmentName)) {
                    throw new RuntimeException('archive_add_fragment_failed');
                }
                $zip->setCompressionName($fragmentName, ZipArchive::CM_STORE);
                $entries[] = ['artifact_index' => $state['artifact_count'] + 1, 'archive_path' => $entry['archive_path'], 'logical_bytes' => (int) $entry['bytes'], 'logical_sha256' => $entry['sha256'] ?? null,
                    'zip_entry' => $fragmentName, 'fragment_index' => $entry['fragment'] + 1, 'offset' => (int) $entry['offset'], 'bytes' => $take, 'sha256' => $sha, 'fragmented' => $split];
                $bytes += $take;
                if ($split) {
                    $state['current']['hash_context'] = SerializableSha256::update($state['current']['hash_context'], file_get_contents($temporary));
                }
                $state['current']['offset'] += $take;
                $state['current']['fragment']++;
                if ($state['current']['offset'] === (int) $entry['bytes']) {
                    if ($split && isset($entry['sha256']) && ! hash_equals((string) $entry['sha256'], SerializableSha256::digest($state['current']['hash_context']))) {
                        throw new RuntimeException('archive_artifact_hash_mismatch');
                    }
                    $state['current'] = null;
                    $state['artifact_count']++;
                }
            }
            if ($entries !== []) {
                $zip->addFromString('__gpswiss_volume_manifest.json', json_encode(['format' => 'gpswiss-browser-zip-volume-1', 'volume' => $volume, 'entries' => $entries], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
            if (! $zip->close()) {
                throw new RuntimeException('archive_close_failed');
            }
            if ($entries !== []) {
                if (! chmod($zipPath.'.partial', 0600) || ! rename($zipPath.'.partial', $zipPath)) {
                    throw new RuntimeException('archive_publish_failed');
                }
                $descriptor = ['format' => 'gpswiss-browser-zip-volume-1', 'volume' => $volume, 'file' => $volumeRelative.'/'.$name.'.zip', 'bytes' => filesize($zipPath), 'sha256' => hash_file('sha256', $zipPath), 'payload_bytes' => $bytes, 'entries' => $entries];
                $this->write($volumes.'/'.$name.'.json', $descriptor);
                $state['volume_count']++;
                $state['total_bytes'] += $bytes;
                $state['last_success'] = now()->toIso8601String();
                $state['volumes'][] = ['key' => $name, 'path' => $volumeRelative.'/'.$name.'.zip', 'name' => $name.'.zip', 'bytes' => $descriptor['bytes'], 'sha256' => $descriptor['sha256'], 'descriptor_path' => $volumeRelative.'/'.$name.'.json'];
            } else {
                @unlink($zipPath.'.partial');
            }
            if ($eof) {
                $state['done'] = true;
                $this->write($directory.'/'.$prefix.'archive-manifest.json', ['format' => 'gpswiss-browser-zip-volumes-1', 'volume_count' => $state['volume_count'], 'artifact_count' => $state['artifact_count'], 'payload_bytes' => $state['total_bytes'],
                    'embedded_volume_map' => '__gpswiss_volume_manifest.json inside every ZIP', 'volume_descriptor_pattern' => $volumeRelative.'/'.$prefix.'volume-NNNNNN.json', 'whole_file_reassembly' => 'For fragmented entries concatenate bytes by artifact_index and offset, restore archive_path, verify logical_sha256 when present. Fragment SHA-256 and volume SHA-256 must always match.',
                    'volumes' => $state['volumes'], 'max_volume_payload_bytes' => $payloadLimit, 'max_entries_per_volume' => self::MAX_ENTRIES, 'automatic_import_allowed' => false, 'automatic_publish' => false, 'contains_customer_data' => $containsCustomerData]);
            }
            $this->write($checkpointFile, $state);
        } catch (\Throwable $e) {
            try {
                $zip->close();
            } catch (\Throwable) {
            }
            @unlink($zipPath.'.partial');
            throw $e;
        } finally {
            fclose($input);
            foreach ($temporaries as $temporary) {
                @unlink($temporary);
            }
        }

        return $this->result($state);
    }

    public function status(string $directory, string $namespace = 'full'): array
    {
        if (! in_array($namespace, ['full', 'sample'], true)) {
            throw new RuntimeException('archive_namespace_invalid');
        }
        $file = $directory.'/'.($namespace === 'sample' ? 'sample-' : '').'archive-checkpoint.json';
        if (! is_file($file)) {
            return ['done' => false, 'volume_count' => 0, 'artifact_count' => 0, 'payload_bytes' => 0, 'index_offset' => 0, 'current_fragment_offset' => null, 'last_success' => null, 'volumes' => [], 'manifest' => null];
        }

        return $this->result($this->read($file));
    }

    /** Bounded individual descriptor; callers can page volume numbers instead of loading all descriptors. */
    public function volume(string $directory, int $number, string $namespace = 'full'): array
    {
        if (! in_array($namespace, ['full', 'sample'], true)) {
            throw new RuntimeException('archive_namespace_invalid');
        }
        if ($number < 1) {
            throw new RuntimeException('archive_volume_invalid');
        }

        $prefix = $namespace === 'sample' ? 'sample-' : '';

        return $this->read($directory.'/'.$prefix.'archive-volumes/'.$prefix.sprintf('volume-%06d.json', $number));
    }

    private function result(array $state): array
    {
        return ['done' => $state['done'], 'volume_count' => $state['volume_count'], 'artifact_count' => $state['artifact_count'], 'payload_bytes' => $state['total_bytes'], 'index_offset' => $state['index_offset'], 'current_fragment_offset' => $state['current']['offset'] ?? null, 'last_success' => $state['last_success'] ?? null,
            'volumes' => $state['volumes'], 'manifest' => $state['done'] ? (($state['namespace'] ?? 'full') === 'sample' ? 'sample-' : '').'archive-manifest.json' : null];
    }

    private function source(string $directory, string $relative): string
    {
        $this->archivePath($relative);
        $real = realpath($directory.'/'.$relative);
        if (! $real || ! str_starts_with($real, $directory.DIRECTORY_SEPARATOR) || ! is_file($real) || ! is_readable($real)) {
            throw new RuntimeException('archive_source_missing_or_unsafe');
        }
        if ((! str_starts_with($relative, 'images/') && (preg_match('/^(?:state|owner|snapshot|photo-tasks|panel-photo-tasks|photo-checkpoint|(?:sample-)?archive-checkpoint|snapshot-heartbeat|snapshot-ready)\.(?:json|jsonl)$/i', basename($relative)) || preg_match('#^(?:photo-receipts|photo-initial-results|photo-result-overrides|photo-retries|archive-volumes|sample-archive-volumes)/#', $relative))) || str_starts_with(basename($relative), '.')) {
            throw new RuntimeException('archive_private_checkpoint_forbidden');
        }

        return $real;
    }

    private function archivePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0") || in_array('..', explode('/', $path), true) || preg_match('/^[a-z]+:/i', $path)) {
            throw new RuntimeException('archive_path_invalid');
        }
    }

    private function copyFragment(string $source, string $target, int $offset, int $length): string
    {
        $input = fopen($source, 'rb');
        $output = fopen($target, 'wb');
        if (! chmod($target, 0600) || fseek($input, $offset) !== 0) {
            fclose($input);
            fclose($output);
            throw new RuntimeException('archive_fragment_initialization_failed');
        }
        $hash = hash_init('sha256');
        $left = $length;
        try {
            while ($left > 0) {
                $chunk = fread($input, min(256 * 1024, $left));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('archive_source_truncated');
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('archive_fragment_write_failed');
                }
                hash_update($hash, $chunk);
                $left -= strlen($chunk);
            }
            if (! fflush($output)) {
                throw new RuntimeException('archive_fragment_flush_failed');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        return hash_final($hash);
    }

    private function fingerprint(string $path): array
    {
        clearstatcache(true, $path);
        $stat = stat($path);

        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    private function read(string $path): array
    {
        return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    private function write(string $path, array $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($path.'.tmp', $json, LOCK_EX) !== strlen($json) || ! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
            @unlink($path.'.tmp');
            throw new RuntimeException('archive_checkpoint_write_failed');
        }
    }
}
