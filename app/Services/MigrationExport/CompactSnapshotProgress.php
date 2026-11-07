<?php

namespace App\Services\MigrationExport;

use RuntimeException;

/** Operational projection only; never materializes shard/manifests or changes the frozen checkpoint. */
final class CompactSnapshotProgress
{
    /** Constant-size read only. Missing/stale projections are rebuilt by the next tick, never GET. */
    public function read(string $directory, string $attempt): ?array
    {
        $cache = $directory.'/compact-snapshot-progress.json';
        clearstatcache(true, $cache);
        if (! is_file($cache) || filesize($cache) > 8192) {
            return null;
        }
        try {
            $cached = json_decode(file_get_contents($cache, false, null, 0, 8193), true, 32, JSON_THROW_ON_ERROR);
            if (($cached['attempt'] ?? null) === $attempt && ($cached['fingerprint'] ?? null) === $this->fingerprint($directory.'/logical-snapshot/'.$attempt.'/checkpoint.json')) {
                return $cached['progress'];
            }
        } catch (\Throwable) {
        }

        return null;
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

        $definition = $datasets[$dataset] ?? [];

        return array_intersect_key($definition, array_flip(['dataset_generation', 'dataset_boundary_id', 'previous_boundary_id', 'boundary_refreshed_at', 'recovery_reason', 'consistency_mode', 'recovery_strategy', 'mutable_round', 'mutable_dirty_rows', 'mutable_dirty_shards', 'mutable_reused_shards']))
            + ['snapshot_stage' => $state['stage'], 'dataset_recovery' => $state['dataset_recovery'] ?? null, 'source_conflict' => $state['source_conflict'] ?? null, 'current_dataset' => $dataset, 'processed' => $processed, 'total' => $total,
                'exported_records' => array_sum(array_column($datasets, 'processed')), 'verification_processed' => array_sum(array_column($datasets, 'verified')),
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

    public function fingerprint(string $path): array
    {
        clearstatcache(true, $path);
        $stat = stat($path);
        if (! $stat) {
            throw new RuntimeException('status_checkpoint_missing');
        }

        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }
}
