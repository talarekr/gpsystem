<?php

namespace App\Services\MigrationExport;

/** Small, private request lifecycle; never reads source records or exception bodies. */
final class OperatorFinalizationDiagnostics
{
    private const FILE = 'operator-finalize-last-request.json';

    public static function active(array $state): bool
    {
        return ($state['consistency_exception']['accepted_by_operator'] ?? false) === true
            && ($state['run_id'] ?? null) === BrowserMigrationSnapshot::DRIFT_RUN
            && ($state['snapshot_attempt_id'] ?? null) === BrowserMigrationSnapshot::DRIFT_ATTEMPT;
    }

    public static function decorate(array $data): array
    {
        if (! self::active($data)) {
            return $data;
        }
        $phase = $data['phase'] ?? '';
        $work = $data['operator_finalization'] ?? [];
        $stage = match (true) {
            $phase === 'relation_audit' => 'relations',
            $phase === 'photo_inventory' => 'photo_prepare',
            str_starts_with($phase, 'archive') => 'package_prepare',
            str_starts_with($phase, 'photos') => 'photos',
            $phase === 'completed' => 'completed',
            ($data['progress_unavailable'] ?? false) => 'checkpoint_load',
            ($data['snapshot_stage'] ?? null) === 'completed', ($work['artifact_integrity_complete'] ?? false) => 'snapshot_seal',
            default => $data['operator_finalize_stage'] ?? 'artifact_integrity',
        };
        $data['operator_finalize_stage'] = $stage;
        if (in_array($stage, ['artifact_integrity', 'snapshot_seal', 'checkpoint_load'], true)) {
            $data['current_dataset'] ??= 'operator_finalize:'.$stage;
            $data['cursor'] = array_intersect_key($work, array_flip(['dataset_index', 'shard', 'artifacts_checked', 'artifact_integrity_complete']));
        }
        $data['snapshot_retry_allowed'] = false;

        return $data;
    }

    public static function controlledReason(string $value): ?string
    {
        return in_array($value, ['snapshot_shard_checksum_mismatch', 'snapshot_shard_size_mismatch', 'snapshot_shard_format_invalid',
            'snapshot_shard_row_count_mismatch', 'snapshot_shard_identity_mismatch', 'snapshot_schema_changed', 'snapshot_chunk_corrupted',
            'operator_finalization_identity_mismatch', 'operator_finalization_incomplete', 'operator_finalization_stage_mismatch', 'operator_finalization_invalid_json',
            'relation_shard_missing_or_changed', 'relation_shard_not_bounded', 'relation_snapshot_not_completed',
            'relation_stale_snapshot_attempt', 'relation_journal_truncated', 'package_checksum_index_changed'], true) ? $value : null;
    }

    public function begin(string $directory, array $root, string $action = 'snapshot_tick'): ?string
    {
        if (! self::active($root) || ! in_array($root['phase'] ?? '', ['snapshot_operator_finalize', 'relation_audit', 'photo_inventory'], true)) {
            return null;
        }
        $context = $this->context($directory, $root);
        $id = bin2hex(random_bytes(12));
        $this->write($directory, ['request_id' => $id, 'action' => $action === 'kick' ? 'kick' : 'snapshot_tick', 'reason_code' => 'request_running', 'request_phase' => 'work_or_response_pending',
            'started_at' => microtime(true), 'duration_ms' => null, 'backend_exception_class' => null, 'safe_exception_category' => null,
            'operator_finalize_stage' => $context['operator_finalize_stage'], 'stage_before' => $context['operator_finalize_stage'],
            'dataset_index' => $context['artifact_offset']['dataset_index'] ?? null, 'artifact_offset_before' => $context['artifact_offset'],
            'artifact_offset_after' => null, 'relation_offset_before' => $context['relation_offset'], 'relation_offset_after' => null,
            'manifest_package_stage' => $root['package']['stage'] ?? null]);

        return $id;
    }

    public function finish(string $directory, array $root, ?string $id, string $reason, ?string $exception = null, ?string $category = null): ?array
    {
        $trace = $this->read($directory);
        if (! $id || ($trace['request_id'] ?? null) !== $id) {
            return null;
        }
        $context = $this->context($directory, $root);
        $trace['reason_code'] = $reason;
        $trace['request_phase'] = $reason === 'response_serialized' ? 'response_serialized' : 'failed';
        $trace['stage_after'] = $context['operator_finalize_stage'];
        $trace['artifact_offset_after'] = $context['artifact_offset'];
        $trace['relation_offset_after'] = $context['relation_offset'];
        $trace['backend_exception_class'] = $exception;
        $trace['safe_exception_category'] = $category;
        $trace['duration_ms'] = (int) ((microtime(true) - $trace['started_at']) * 1000);
        $this->write($directory, $trace);

        return $trace;
    }

    /** Includes failures after last_tick already says tick_completed. */
    public function fatal(string $directory, array $root, string $reason): void
    {
        if (! self::active($root)) {
            return;
        }
        $trace = $this->read($directory);
        if (($trace['reason_code'] ?? null) !== 'request_running') {
            return;
        }
        $safe = in_array($reason, ['memory_exhaustion', 'backend_timeout', 'php_fatal_error'], true) ? $reason : 'php_fatal_error';
        $this->finish($directory, $root, $trace['request_id'], $safe, 'PHPFatalError', $safe);
    }

    public function read(string $directory): ?array
    {
        return $this->small($directory.'/'.self::FILE);
    }

    private function context(string $directory, array $root): array
    {
        $progress = app(CompactSnapshotProgress::class)->read($directory, $root['snapshot_attempt_id']) ?? [];
        $data = self::decorate(array_replace($root, $progress));
        if ($progress === [] && ($root['phase'] ?? null) === 'snapshot_operator_finalize') {
            $data['operator_finalize_stage'] = 'checkpoint_load';
        }
        $relation = $this->small($directory.'/relation-checkpoint.json') ?? ['dataset_index' => 0, 'shard_index' => 0, 'row_index' => 0, 'column_index' => 0, 'processed' => 0, 'probes' => 0, 'done' => false];

        return ['operator_finalize_stage' => $data['operator_finalize_stage'] ?? 'unknown',
            'artifact_offset' => array_intersect_key($data['operator_finalization'] ?? [], array_flip(['dataset_index', 'shard', 'artifacts_checked', 'artifact_integrity_complete'])),
            'relation_offset' => array_intersect_key($relation, array_flip(['dataset_index', 'shard_index', 'row_index', 'column_index', 'processed', 'probes', 'done']))];
    }

    private function small(string $path): ?array
    {
        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) > 8192) {
            return null;
        }
        try {
            return json_decode(file_get_contents($path, false, null, 0, 8193), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    private function write(string $directory, array $trace): void
    {
        try {
            $bytes = json_encode($trace, JSON_THROW_ON_ERROR);
            if (strlen($bytes) > 8192) {
                return;
            }
            $file = $directory.'/'.self::FILE;
            $temp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
            if (file_put_contents($temp, $bytes) === strlen($bytes) && chmod($temp, 0600)) {
                rename($temp, $file);
            }
            if (is_file($temp)) {
                unlink($temp);
            }
        } catch (\Throwable) { /* Diagnostics never replace the canonical checkpoint. */
        }
    }
}
