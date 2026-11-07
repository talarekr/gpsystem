<?php

namespace App\Services\MigrationExport;

/** Reads local originals only. Never downloads URLs, mutates source bytes or invokes image processors. */
final class MigrationOriginals
{
    public function roots(): array
    {
        $roots = [config('filesystems.disks.public.root'), config('filesystems.served_public_storage_root'), public_path('storage'), dirname(base_path()).'/public_html/storage'];

        return array_values(array_unique(array_filter(array_map(fn ($p) => is_string($p) ? realpath($p) : false, $roots))));
    }

    public function locate(string $raw): array
    {
        $reference = $this->reference($raw);
        if ($reference['status'] !== 'REFERENCE_ONLY') {
            return $reference;
        }
        $relative = $reference['relative_path'];
        $copies = [];
        $errors = false;
        foreach ($this->roots() as $root) {
            $candidate = realpath($root.'/'.$relative);
            if ($candidate === false) {
                continue;
            }
            if (! str_starts_with($candidate, $root.DIRECTORY_SEPARATOR) || ! is_file($candidate) || ! is_readable($candidate)) {
                $errors = true;

                continue;
            }
            $stat = stat($candidate);
            $copies[] = ['path' => $candidate, 'fingerprint' => $this->fingerprint($stat), 'bytes' => $stat['size']];
        }

        return ['status' => $copies ? 'LOCATED' : ($errors ? 'UNREADABLE_OR_UNSAFE' : 'MISSING_ORIGINAL'), 'relative_path' => $relative, 'copies' => $copies];
    }

    private function reference(string $raw): array
    {
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $raw)) {
            return ['status' => 'REMOTE_NOT_FETCHED'];
        }
        $relative = preg_replace('~^/?storage/~', '', trim($raw));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, "\0") || in_array('..', explode('/', $relative), true)) {
            return ['status' => 'UNSAFE_OR_EMPTY_PATH'];
        }

        return ['status' => 'REFERENCE_ONLY', 'relative_path' => $relative];
    }

    public function copy(array $task, string $directory): array
    {
        $base = array_intersect_key($task, array_flip(['image_id', 'part_id', 'role', 'sort_order', 'is_primary', 'alt_text', 'original_filename', 'original_relative_path', 'package_path']));
        $base += ['status' => $task['source']['status'], 'bytes' => null, 'sha256' => null, 'mime' => null];
        if ($task['source']['status'] !== 'LOCATED') {
            return $base;
        }
        if ($task['source']['copies'][0]['bytes'] > 100 * 1024 * 1024) {
            return array_replace($base, ['status' => 'OVERSIZE_ORIGINAL']);
        }
        $hashes = [];
        $selected = null;
        foreach ($task['source']['copies'] as $copy) {
            // Re-resolve containment for every read. Never follow a replaced symlink outside allowed roots.
            $resolved = realpath($copy['path']);
            if ($resolved === false || ! collect($this->roots())->contains(fn ($r) => str_starts_with($resolved, $r.DIRECTORY_SEPARATOR))) {
                return array_replace($base, ['status' => 'SOURCE_CHANGED']);
            }
            clearstatcache(true, $resolved);
            if ($this->fingerprint(@stat($resolved)) !== $copy['fingerprint']) {
                return array_replace($base, ['status' => 'SOURCE_CHANGED']);
            }
            $hash = @hash_file('sha256', $resolved);
            if (! is_string($hash)) {
                return array_replace($base, ['status' => 'UNREADABLE_ORIGINAL']);
            }
            $hashes[] = $hash;
            $selected ??= $resolved;
        }
        if (count(array_unique($hashes)) !== 1) {
            return array_replace($base, ['status' => 'CONFLICTING_COPIES']);
        }
        $target = $directory.'/'.$task['package_path'];
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        if ($task['source']['copies'][0]['bytes'] > 100 * 1024 * 1024) {
            return array_replace($base, ['status' => 'OVERSIZE_ORIGINAL']);
        }
        if (is_file($target) && filesize($target) === $task['source']['copies'][0]['bytes'] && hash_equals($hashes[0], hash_file('sha256', $target))) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($target);
            if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) && @getimagesize($target) !== false) {
                return array_replace($base, ['status' => 'COPIED_VERIFIED', 'bytes' => filesize($target), 'sha256' => $hashes[0], 'mime' => $mime, 'copied_at' => date('c', filemtime($target))]);
            }
        }
        if (disk_free_space($directory) < $task['source']['copies'][0]['bytes'] * 2 + 64 * 1024 * 1024) {
            return array_replace($base, ['status' => 'INSUFFICIENT_DISK_SPACE']);
        }
        $input = @fopen($selected, 'rb');
        $output = @fopen($target.'.partial', 'wb');
        if (! $input || ! $output) {
            if (is_resource($input)) {
                fclose($input);
            } if (is_resource($output)) {
                fclose($output);
            }

            return array_replace($base, ['status' => 'COPY_FAILED']);
        }
        try {
            $before = fstat($input);
            $bytes = stream_copy_to_stream($input, $output);
            fflush($output);
            $after = fstat($input);
        } finally {
            fclose($input);
            fclose($output);
        }
        clearstatcache(true, $selected);
        if ($bytes !== $task['source']['copies'][0]['bytes'] || $this->fingerprint($before) !== $this->fingerprint($after) || $this->fingerprint(@stat($selected)) !== $task['source']['copies'][0]['fingerprint'] || hash_file('sha256', $target.'.partial') !== $hashes[0]) {
            @unlink($target.'.partial');

            return array_replace($base, ['status' => 'SOURCE_CHANGED']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($target.'.partial');
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || $bytes === 0 || @getimagesize($target.'.partial') === false) {
            @unlink($target.'.partial');

            return array_replace($base, ['status' => 'INVALID_IMAGE']);
        }
        rename($target.'.partial', $target);
        chmod($target, 0600);

        return array_replace($base, ['status' => 'COPIED_VERIFIED', 'bytes' => $bytes, 'sha256' => $hashes[0], 'mime' => $mime, 'copied_at' => now()->toIso8601String()]);
    }

    public function task(array $image, array $part, bool $inspectOriginals = true): array
    {
        $source = $inspectOriginals ? $this->locate((string) ($image['path'] ?? '')) : $this->reference((string) ($image['path'] ?? ''));
        $relative = $source['relative_path'] ?? '';
        $imageId = (string) $image['id'];
        $filename = $relative === '' ? 'unavailable-'.$imageId : MigrationDataPolicy::clean(basename($relative), 'filename');
        if ($relative !== '' && $filename !== basename($relative)) {
            $source = ['status' => 'SENSITIVE_FILENAME_BLOCKED'];
            $filename = 'redacted-'.$imageId;
        }

        return ['image_id' => $imageId, 'part_id' => (string) $image['part_id'], 'role' => $image['role'] ?? 'gallery',
            'sort_order' => (int) ($image['sort_order'] ?? 0), 'is_primary' => (bool) ($image['is_primary'] ?? false),
            'alt_text' => GpswissSnapshot::clean($image['alt_text'] ?? null), 'original_filename' => $relative === '' ? null : MigrationDataPolicy::clean(basename($relative), 'filename'),
            'original_relative_path' => $relative === '' ? null : MigrationDataPolicy::clean($relative, 'original_relative_path'),
            'package_path' => 'images/'.$image['part_id'].'/'.$filename, 'source' => $source];
    }

    private function fingerprint(array|false $stat): ?array
    {
        return $stat === false ? null : array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }
}
