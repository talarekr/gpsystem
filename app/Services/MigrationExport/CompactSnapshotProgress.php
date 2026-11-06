<?php

namespace App\Services\MigrationExport;

use RuntimeException;

/** Operational projection only; never materializes shard/manifests or changes the frozen checkpoint. */
final class CompactSnapshotProgress
{
    private $stream;

    private string $buffer = '';

    private int $position = 0;

    public function read(string $directory, string $attempt): array
    {
        $path = $directory.'/logical-snapshot/'.$attempt.'/checkpoint.json';
        $fingerprint = $this->fingerprint($path);
        $cache = $directory.'/compact-snapshot-progress.json';
        if (is_file($cache) && filesize($cache) <= 8192) {
            try {
                $cached = json_decode(file_get_contents($cache), true, 32, JSON_THROW_ON_ERROR);
                if (($cached['fingerprint'] ?? null) === $fingerprint && ($cached['attempt'] ?? null) === $attempt) {
                    return $cached['progress'];
                }
            } catch (\Throwable) {
                // A derived cache is disposable, never a data checkpoint.
            }
        }
        $this->stream = fopen($path, 'rb');
        $this->buffer = '';
        $this->position = 0;
        try {
            $state = $this->value([]);
        } finally {
            fclose($this->stream);
        }
        if ($fingerprint !== $this->fingerprint($path)) {
            throw new MigrationExportException('status_checkpoint_changed', 503);
        }
        $progress = self::project($state);
        $this->store($directory, $attempt, $progress, $fingerprint);

        return $progress;
    }

    public function publish(string $directory, string $attempt, array $state): void
    {
        $this->store($directory, $attempt, self::project($state), $this->fingerprint($directory.'/logical-snapshot/'.$attempt.'/checkpoint.json'));
    }

    private static function project(array $state): array
    {
        $dataset = $state['dataset_names'][$state['dataset_index']] ?? null;
        $datasets = $state['datasets'] ?? [];
        $verify = ($state['stage'] ?? '') === 'verify';
        $processed = array_sum(array_column($datasets, $verify ? 'verified' : 'processed'));
        $total = array_sum(array_column($datasets, 'total'));

        return ['current_dataset' => $dataset, 'processed' => $processed, 'total' => $total,
            'remaining' => max(0, $total - $processed), 'cursor' => $datasets[$dataset][$verify ? 'verify_cursor' : 'cursor'] ?? null,
            'last_success' => $state['last_success'] ?? null, 'last_error' => $state['last_error'] ?? null];
    }

    private function store(string $directory, string $attempt, array $progress, array $fingerprint): void
    {
        $file = $directory.'/compact-snapshot-progress.json';
        $bytes = json_encode(compact('attempt', 'progress', 'fingerprint'), JSON_THROW_ON_ERROR);
        $temp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        try {
            if (@file_put_contents($temp, $bytes) === strlen($bytes) && chmod($temp, 0600)) {
                rename($temp, $file);
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function fingerprint(string $path): array
    {
        clearstatcache(true, $path);
        $stat = stat($path);
        if (! $stat) {
            throw new RuntimeException('status_checkpoint_missing');
        }

        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    private function char(): string
    {
        if ($this->position >= strlen($this->buffer)) {
            $this->buffer = fread($this->stream, 16384);
            $this->position = 0;
            if ($this->buffer === '') {
                throw new RuntimeException('status_checkpoint_invalid');
            }
        }

        return $this->buffer[$this->position++];
    }

    private function token(): string
    {
        do {
            $c = $this->char();
        } while (ctype_space($c));

        return $c;
    }

    private function string(): string
    {
        $raw = '"';
        $escaped = false;
        while (true) {
            $c = $this->char();
            $raw .= $c;
            if (strlen($raw) > 8192) {
                throw new RuntimeException('status_scalar_too_large');
            }
            if ($c === '"' && ! $escaped) {
                return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            }
            $escaped = $c === '\\' && ! $escaped;
        }
    }

    private function value(array $path): mixed
    {
        $c = $this->token();
        if ($c === '"') {
            return $this->string();
        }
        if ($c === '{' || $c === '[') {
            $object = $c === '{';
            $end = $object ? '}' : ']';
            $out = [];
            $c = $this->token();
            if ($c === $end) {
                return $out;
            }
            $this->position--;
            do {
                if (count($out) > 1000 || count($path) > 5) {
                    throw new RuntimeException('status_projection_too_large');
                }
                $key = count($out);
                if ($object) {
                    if ($this->token() !== '"') {
                        throw new RuntimeException('status_checkpoint_invalid');
                    }
                    $key = $this->string();
                    if ($this->token() !== ':') {
                        throw new RuntimeException('status_checkpoint_invalid');
                    }
                }
                $keep = $path === [] ? in_array($key, ['dataset_names', 'dataset_index', 'stage', 'datasets', 'last_success', 'last_error'], true)
                    : ($path === ['datasets'] || count($path) !== 2 || $path[0] !== 'datasets' || in_array($key, ['processed', 'verified', 'total', 'cursor', 'verify_cursor'], true));
                if ($keep) {
                    $out[$key] = $this->value([...$path, $key]);
                } else {
                    $this->skip();
                }
                $c = $this->token();
            } while ($c === ',');
            if ($c !== $end) {
                throw new RuntimeException('status_checkpoint_invalid');
            }

            return $out;
        }
        $raw = $c;
        while (true) {
            $c = $this->char();
            if (str_contains(',}]', $c) || ctype_space($c)) {
                $this->position--;
                break;
            }
            $raw .= $c;
            if (strlen($raw) > 128) {
                throw new RuntimeException('status_checkpoint_invalid');
            }
        }

        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    private function skip(): void
    {
        $c = $this->token();
        $depth = ($c === '{' || $c === '[') ? 1 : 0;
        $string = $c === '"';
        $escaped = false;
        while (true) {
            $c = $this->char();
            if ($string) {
                if ($c === '"' && ! $escaped) {
                    $string = false;
                }
                $escaped = $c === '\\' && ! $escaped;
            } elseif ($c === '"') {
                $string = true;
            } elseif ($c === '{' || $c === '[') {
                $depth++;
            } elseif ($c === '}' || $c === ']') {
                if ($depth === 0) {
                    $this->position--;

                    return;
                }
                $depth--;
                if ($depth === 0) {
                    return;
                }
            } elseif ($depth === 0 && ($c === ',' || ctype_space($c))) {
                $this->position--;

                return;
            }
        }
    }
}
