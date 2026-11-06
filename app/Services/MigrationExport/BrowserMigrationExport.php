<?php

namespace App\Services\MigrationExport;

use App\Models\Order;
use Illuminate\Support\Str;
use RuntimeException;

/** Browser-only v3 pipeline. The caller has already authorized the private run directory. */
final class BrowserMigrationExport
{
    public function __construct(
        private BrowserMigrationSnapshot $snapshots,
        private MigrationOriginals $originals,
        private BrowserMigrationPhotos $photos,
        private BrowserMigrationArchives $archives,
        private BrowserMigrationRelations $relations,
        private BrowserMigrationSample $sample,
    ) {}

    public function begin(string $dir, array $state): array
    {
        return $this->locked($dir, function () use ($dir, $state): void {
            $current = $this->read($dir.'/state.json');
            if (($current['snapshot_mode'] ?? null) === 'browser') {
                return;
            }
            if ($current['state'] !== 'snapshot_pending' || ($current['snapshot_attempt_id'] ?? null) !== $state['snapshot_attempt_id']) {
                throw new MigrationExportException('snapshot_retry_not_allowed', 409);
            }
            $state = $current;
            $state['schema_version'] = 'gpswiss-wystawczesc-3';
            $state['snapshot_mode'] = 'browser';
            $state['state'] = 'snapshot_pending';
            $state['phase'] = 'snapshot_boundaries';
            $state['snapshot_state'] = 'snapshot_pending';
            $state['processed_images'] = 0;
            $state['photo_results'] = [];
            $state['last_error'] = null;
            unset($state['snapshot_launch_status']);
            $this->write($dir.'/state.json', $state);
            $this->snapshots->begin($dir, $state['snapshot_attempt_id']);
        });
    }

    /** A new logical attempt keeps the UUID and retains the previous technical snapshot. */
    public function restart(string $dir, ?string $expected, string $sourceFingerprint): array
    {
        return $this->locked($dir, function () use ($dir, $expected, $sourceFingerprint): void {
            $state = $this->read($dir.'/state.json');
            $this->attempt($state, $expected);
            if (! in_array($state['state'], ['snapshot_pending', 'snapshot_running', 'snapshot_paused', 'snapshot_failed'], true)
                || ($state['processed_images'] ?? 0) !== 0 || is_dir($dir.'/images') || is_file($dir.'/photos.jsonl') || is_dir($dir.'/archive-volumes')) {
                throw new MigrationExportException('snapshot_retry_not_allowed', 409);
            }
            $history = $dir.'/snapshot-history/'.($state['snapshot_attempt_id'] ?? 'legacy');
            if (! is_dir($history)) {
                mkdir($history, 0700, true);
            }
            $this->write($history.'/state.json', $state);
            foreach (['snapshot.json', 'snapshot.json.tmp', 'photo-tasks.jsonl', 'photo-tasks.json', 'snapshot-ready.json', 'snapshot-heartbeat.json', 'relation-checkpoint.json', 'relation-checkpoint.json.tmp', 'relation-issues.jsonl', 'sold-part-ids.csv', 'parts-to-list-ids.csv'] as $name) {
                if (is_file($dir.'/'.$name) && ! rename($dir.'/'.$name, $history.'/'.$name)) {
                    throw new RuntimeException('snapshot_history_write_failed');
                }
            }
            $state['snapshot_previous_attempt_id'] = $state['snapshot_attempt_id'] ?? null;
            $state['snapshot_attempt_id'] = (string) Str::uuid();
            $state['snapshot_source_fingerprint'] = $sourceFingerprint;
            $state['snapshot_attempts'] = ($state['snapshot_attempts'] ?? 1) + 1;
            $state['snapshot_requested_at'] = now()->toIso8601String();
            $state['state'] = $state['snapshot_state'] = 'snapshot_pending';
            $state['phase'] = 'snapshot_boundaries';
            $state['last_error'] = null;
            unset($state['inventory'], $state['snapshot_paused_from'], $state['source_counts'], $state['total_images'], $state['relation_audit']);
            $this->write($dir.'/state.json', $state);
            $this->snapshots->begin($dir, $state['snapshot_attempt_id']);
        });
    }

    public function snapshotTick(string $dir, ?string $expected): array
    {
        return $this->locked($dir, function () use ($dir, $expected): void {
            $state = $this->read($dir.'/state.json');
            $this->attempt($state, $expected);
            $this->performSnapshotTick($dir, $state);
        });
    }

    /** No status dependency: preflight under the same lease as the ONE bounded unit of work. */
    public function kick(string $dir, string $expected, string $sourceFingerprint): array
    {
        $reason = 'busy';
        $result = $this->locked($dir, function () use ($dir, $expected, $sourceFingerprint, &$reason): void {
            $state = $this->small($dir.'/state.json', 65536);
            if (! $state) {
                $state = $this->small($dir.'/operational-progress.json', 8192);
                if (($state['state'] ?? null) !== 'completed' || ($state['root_fingerprint'] ?? null) !== app(CompactSnapshotProgress::class)->fingerprint($dir.'/state.json')) {
                    throw new MigrationExportException('minimal_root_state_unavailable', 503);
                }
            }
            if (($state['snapshot_attempt_id'] ?? null) !== $expected) {
                throw new MigrationExportException('attempt_mismatch', 409);
            }
            if (($state['run_id'] ?? null) !== basename($dir) || ($state['snapshot_mode'] ?? null) !== 'browser') {
                throw new MigrationExportException('stale_attempt', 409);
            }
            if ($state['state'] === 'snapshot_failed' && ($state['last_error'] ?? null) === 'snapshot_row_updated_after_cutoff') {
                if (($state['snapshot_source_fingerprint'] ?? null) !== $sourceFingerprint) {
                    throw new MigrationExportException('snapshot_source_context_mismatch', 409);
                }
                $result = $this->snapshots->requestDatasetRecovery($dir, $expected);
                $state['state'] = $state['snapshot_state'] = 'snapshot_running';
                $state['phase'] = $result['phase'];
                $state['dataset_recovery'] = $result['dataset_recovery'] ?? null;
                $state['last_error'] = null;
                $this->write($dir.'/state.json', $state);
                $reason = 'recovery_started';

                return;
            }
            $reason = match (true) {
                $state['state'] === 'completed' => 'completed',
                $state['state'] === 'snapshot_paused' => 'paused',
                in_array($state['state'], ['snapshot_failed', 'failed', 'snapshot_aborted'], true) => 'run_failed',
                $state['state'] !== 'snapshot_running' => 'state_not_runnable',
                ! in_array($state['phase'], ['snapshot_boundaries', 'snapshot_export', 'snapshot_verify', 'snapshot_recovery_preflight', 'relation_audit', 'photo_inventory'], true) => 'state_not_runnable',
                default => 'tick_completed',
            };
            if ($reason !== 'tick_completed') {
                return;
            }
            if (($state['snapshot_source_fingerprint'] ?? null) !== $sourceFingerprint) {
                throw new MigrationExportException('snapshot_source_context_mismatch', 409);
            }
            $this->performSnapshotTick($dir, $state);
            if ($state['last_error'] ?? null) {
                $reason = $state['last_error'];
            }
        }, false, true);
        if ($reason === 'tick_completed' && ! empty($result['last_error'])) {
            $reason = $result['last_error'];
        }
        $result['kick_reason'] = $reason;
        $result['blocked_reason'] = in_array($reason, ['tick_completed', 'recovery_started'], true) ? ($result['blocked_reason'] ?? null) : $reason;

        return $result;
    }

    private function performSnapshotTick(string $dir, array &$state): void
    {
        if ($state['state'] === 'snapshot_paused' || $state['state'] === 'snapshot_failed') {
            return;
        }
        if ($state['phase'] === 'relation_audit') {
            $result = $this->relations->tick($dir, $state['snapshot_attempt_id']);
            $state['relation_audit'] = $result;
            $state['last_success'] = $result['last_success'];
            if ($result['done']) {
                $state['phase'] = 'photo_inventory';
            }
        } elseif ($state['phase'] === 'photo_inventory') {
            $this->inventory($dir, $state);
        } elseif (in_array($state['state'], ['snapshot_pending', 'snapshot_running'], true)) {
            $result = $this->snapshots->tick($dir, $state['snapshot_attempt_id']);
            $state['dataset_recovery'] = $result['dataset_recovery'] ?? null;
            $state['last_error'] = $result['last_error'];
            $state['last_success'] = $result['last_success'];
            $state['state'] = $result['failed'] ? 'snapshot_failed' : ($result['done'] ? 'snapshot_completed' : 'snapshot_running');
            $state['snapshot_state'] = $state['state'];
            $state['phase'] = $result['done'] ? 'relation_audit' : $result['phase'];
            if ($result['done']) {
                $state['source_counts'] = $result['source_counts'];
                $state['inventory'] = ['dataset' => 0, 'shard' => 0, 'row' => 0, 'tasks' => 0, 'bytes' => 0, 'chain' => hash('sha256', ''), 'sample_ids' => [], 'sold_bytes' => 0, 'to_list_bytes' => 0];
            }
        }
        $this->write($dir.'/state.json', $state);
    }

    public function pause(string $dir): array
    {
        return $this->locked($dir, function () use ($dir): void {
            $state = $this->read($dir.'/state.json');
            if (in_array($state['state'], ['snapshot_pending', 'snapshot_running', 'snapshot_completed'], true)) {
                $state['snapshot_paused_from'] = $state['state'];
                $state['state'] = $state['snapshot_state'] = 'snapshot_paused';
                $this->write($dir.'/state.json', $state);
            }
        });
    }

    public function resume(string $dir, ?string $expected = null, ?string $sourceFingerprint = null): array
    {
        return $this->locked($dir, function () use ($dir, $expected, $sourceFingerprint): void {
            $state = $this->read($dir.'/state.json');
            if ($state['state'] === 'snapshot_failed') {
                $this->attempt($state, $expected);
                if (($state['snapshot_source_fingerprint'] ?? null) !== $sourceFingerprint) {
                    throw new MigrationExportException('snapshot_source_context_mismatch', 409);
                }
                $result = $this->snapshots->requestDatasetRecovery($dir, $state['snapshot_attempt_id']);
                $state['state'] = $state['snapshot_state'] = 'snapshot_running';
                $state['phase'] = $result['phase'];
                $state['last_error'] = null;
                $state['dataset_recovery'] = $result['dataset_recovery'] ?? null;
            }
            if ($state['state'] === 'snapshot_paused') {
                $state['state'] = $state['snapshot_state'] = $state['snapshot_paused_from'] ?? 'snapshot_running';
                unset($state['snapshot_paused_from']);
            }
            if (isset($state['resume_state'])) {
                $state['state'] = $state['resume_state'];
                $state['last_error'] = null;
                unset($state['resume_state']);
            }
            $this->write($dir.'/state.json', $state);
        });
    }

    public function retryPhotos(string $dir): array
    {
        return $this->locked($dir, function () use ($dir): void {
            $state = $this->read($dir.'/state.json');
            $retryable = array_sum(array_intersect_key($state['photo_results'] ?? [], array_flip(['COPY_FAILED', 'UNREADABLE_ORIGINAL', 'INSUFFICIENT_DISK_SPACE'])));
            if ($retryable === 0 || ! in_array($state['state'], ['photos', 'archive', 'completed'], true) || isset($state['photo_retry'])) {
                throw new MigrationExportException('photo_retry_not_allowed', 409);
            }
            if (! $this->photos->status($dir)['done']) {
                $state['photo_retry_requested'] = true;
            } else {
                $this->startPhotoRetry($dir, $state);
            }
            $this->write($dir.'/state.json', $state);
        });
    }

    public function tick(string $dir): array
    {
        return $this->locked($dir, function () use ($dir): void {
            $state = $this->read($dir.'/state.json');
            if ($state['state'] === 'photos' && $state['phase'] === 'photos_retry_setup') {
                $this->finishPhotoRetrySetup($dir, $state);
            } elseif ($state['state'] === 'photos' && $state['phase'] === 'photos_retry_inventory') {
                $this->retryInventory($dir, $state);
            } elseif ($state['state'] === 'photos' && $state['phase'] === 'photos_retry') {
                $deadline = microtime(true) + 1.5;
                for ($n = 0; $n < 4 && ($n === 0 || microtime(true) < $deadline); $n++) {
                    $result = $this->photos->tick($dir, $state['photo_retry']['tasks_file'], $deadline, $state['photo_retry']['generation']);
                    if ($result['done']) {
                        break;
                    }
                }
                $state['last_success'] = $result['last_success'];
                if ($result['done']) {
                    $state['photo_retry_generations'][] = ['generation' => $state['photo_retry']['generation'], 'planned' => $state['photo_retry']['count'], 'results' => $result['photo_results']];
                    unset($state['photo_retry']);
                    $this->startPackaging($state);
                }
            } elseif ($state['state'] === 'photos') {
                $deadline = microtime(true) + 1.5;
                for ($n = 0; $n < 4 && ($n === 0 || microtime(true) < $deadline); $n++) {
                    $result = $this->photos->tick($dir, 'photo-tasks.jsonl', $deadline);
                    if ($result['done']) {
                        break;
                    }
                }
                $state['processed_images'] = $result['processed'];
                $state['photo_results'] = $result['photo_results'];
                $state['last_success'] = $result['last_success'];
                if ($result['done']) {
                    if ($state['photo_retry_requested'] ?? false) {
                        $this->startPhotoRetry($dir, $state);
                    } else {
                        $this->startPackaging($state);
                    }
                }
            } elseif ($state['state'] === 'archive' && $state['phase'] === 'archive_index') {
                $this->packageIndex($dir, $state);
            } elseif ($state['state'] === 'archive' && $state['phase'] === 'archive_sample') {
                $result = $this->sample->tick($dir, $state['snapshot_attempt_id']);
                $state['last_success'] = $result['last_success'];
                if ($result['done']) {
                    $state['sample_ids'] = $result['sample_ids'];
                    $state['phase'] = 'archive_sample_volumes';
                }
            } elseif ($state['state'] === 'archive' && $state['phase'] === 'archive_sample_volumes') {
                $result = $this->archives->tick($dir, 'sample-archive-input.jsonl', null, 'sample');
                $state['last_success'] = $result['last_success'] ?? null;
                if ($result['done']) {
                    $state['sample_download_artifacts'] = $this->downloads($dir, $result, 'sample');
                    $state['phase'] = 'archive_index';
                }
            } elseif ($state['state'] === 'archive') {
                $result = $this->archives->tick($dir, 'archive-input.jsonl');
                $state['last_success'] = $result['last_success'] ?? null;
                if ($result['done']) {
                    $state['state'] = $state['phase'] = 'completed';
                    $state['download_artifacts'] = [...($state['sample_download_artifacts'] ?? []), ...$this->downloads($dir, $result)];
                }
            }
            $this->write($dir.'/state.json', $state);
        }, true);
    }

    public function status(string $dir, ?array $state = null, ?bool $busy = null): array
    {
        $state ??= $this->read($dir.'/state.json');
        if ($busy === null) {
            $probe = fopen($dir.'/lock', 'c');
            $busy = ! flock($probe, LOCK_EX | LOCK_NB);
            if (! $busy) {
                flock($probe, LOCK_UN);
            }
            fclose($probe);
        }
        unset($state['owner_id'], $state['inventory'], $state['package']);
        if (is_file($dir.'/last-tick.json')) {
            try {
                $state['last_tick'] = $this->read($dir.'/last-tick.json');
            } catch (\Throwable) {
            }
        }
        try {
            $progress = $this->snapshots->status($dir, $state['snapshot_attempt_id']);
        } catch (\Throwable) {
            $progress = ['phase' => 'snapshot_failed', 'current_dataset' => null, 'cursor' => null, 'processed' => 0, 'total' => 0, 'remaining' => 0, 'last_error' => 'snapshot_checkpoint_missing'];
        }
        $snapshotActive = in_array($state['state'], ['snapshot_pending', 'snapshot_running', 'snapshot_completed', 'snapshot_paused', 'snapshot_failed'], true);
        $next = match (true) {
            $busy => 'wait', $state['state'] === 'completed' => 'download',
            $state['state'] === 'snapshot_paused', isset($state['resume_state']) => 'resume',
            $this->datasetRecoveryAllowed(array_replace($state, $progress ?? [])) => 'resume',
            in_array($state['state'], ['snapshot_failed', 'snapshot_aborted', 'failed'], true) => 'blocked',
            $snapshotActive => 'snapshot_tick', default => 'tick',
        };
        $photo = $this->photos->status($dir);
        $archive = $this->archives->status($dir, $state['phase'] === 'archive_sample_volumes' ? 'sample' : 'full');
        $sampleProgress = $this->sample->status($dir, $state['snapshot_attempt_id']);
        $checkpoint = $this->read($dir.'/state.json');
        $retryProgress = isset($checkpoint['photo_retry']) ? $this->photos->status($dir, $checkpoint['photo_retry']['generation']) : null;
        $current = $snapshotActive && ! in_array($state['phase'], ['photo_inventory', 'relation_audit'], true) ? $progress : [
            'current_dataset' => $state['phase'], 'cursor' => $checkpoint['inventory']['row'] ?? $checkpoint['package']['cursor'] ?? $photo['task_offset'] ?? 0,
            'processed' => $state['phase'] === 'photo_inventory' ? ($checkpoint['inventory']['tasks'] ?? 0) : ($state['processed_images'] ?? 0),
            'total' => $state['phase'] === 'photo_inventory' ? null : ($state['total_images'] ?? 0),
        ];
        if ($state['phase'] === 'relation_audit') {
            $audit = $this->relations->status($dir, $state['snapshot_attempt_id']);
            $current = ['current_dataset' => 'relation_audit', 'processed' => $audit['processed'], 'total' => $progress['total'], 'cursor' => $audit['probes']];
        }
        if (($progress['phase'] ?? null) === 'snapshot_verify' && $snapshotActive) {
            $current['processed'] = $progress['verified'];
        }
        if ($state['state'] === 'archive') {
            $current = match ($state['phase']) {
                'archive_sample' => ['current_dataset' => 'sample_'.$sampleProgress['stage'], 'processed' => $sampleProgress['processed'], 'total' => $sampleProgress['total'], 'cursor' => $sampleProgress['processed']],
                'archive_index' => ['current_dataset' => 'package_'.$checkpoint['package']['stage'], 'processed' => $checkpoint['package']['cursor'], 'total' => null, 'cursor' => $checkpoint['package']['photos_offset']],
                default => ['current_dataset' => $state['phase'], 'processed' => $archive['artifact_count'], 'total' => $state['phase'] === 'archive_sample_volumes' ? ($sampleProgress['file_count'] ?? null) : ($checkpoint['package']['artifact_count'] ?? null), 'cursor' => $archive['index_offset']],
            };
        }
        if (isset($checkpoint['photo_retry'])) {
            $current = ['current_dataset' => $state['phase'], 'processed' => $retryProgress['processed'], 'total' => $checkpoint['photo_retry']['count'], 'cursor' => $state['phase'] === 'photos_retry_inventory' ? $checkpoint['photo_retry']['tasks_offset'] : $retryProgress['task_offset']];
        }

        return array_replace($state, $current, [
            'dataset_recovery_allowed' => $this->datasetRecoveryAllowed(array_replace($state, $progress)),
            'next_action' => $next, 'busy' => $busy, 'resume_allowed' => ! in_array($next, ['blocked', 'download'], true),
            'blocked_reason' => $next === 'blocked' ? ($state['last_error'] ?? $state['state']) : null,
            'snapshot_mode' => 'browser', 'snapshot_process_active' => false, 'snapshot_tick_active' => $busy,
            'snapshot_retry_allowed' => ! $this->datasetRecoveryAllowed(array_replace($state, $progress)) && ! $busy && in_array($state['state'], ['snapshot_pending', 'snapshot_running', 'snapshot_paused', 'snapshot_failed'], true) && ! is_dir($dir.'/images'),
            'snapshot_abort_allowed' => false, 'snapshot_interrupted' => false,
            'snapshot_progress' => $progress, 'photo_progress' => $photo, 'archive_progress' => $archive,
            'sample_progress' => $sampleProgress,
            'photo_retry_progress' => $retryProgress,
            'photo_retry_allowed' => ! $busy && ! isset($checkpoint['photo_retry']) && in_array($state['state'], ['photos', 'archive', 'completed'], true)
                && array_sum(array_intersect_key($state['photo_results'] ?? [], array_flip(['COPY_FAILED', 'UNREADABLE_ORIGINAL', 'INSUFFICIENT_DISK_SPACE']))) > 0,
            'remaining' => $snapshotActive ? ($progress['remaining'] ?? 0) : max(0, ($state['total_images'] ?? 0) - ($state['processed_images'] ?? 0)),
            'snapshot_file_exists' => is_file($dir.'/snapshot.json'), 'snapshot_tasks_file_exists' => is_file($dir.'/photo-tasks.jsonl'),
            'artifacts_present' => is_file($dir.'/snapshot.json'), 'owner_match' => true,
            'database_writes' => false, 'source_file_writes' => false, 'provider_calls' => false, 'allegro_writes' => false,
            'marketplace_writes' => false, 'automatic_publish' => false, 'import_performed' => false, 'export_file_writes' => true,
        ]);
    }

    /** O(1) polling: only a bounded operational file and small diagnostics, never the export index. */
    public function compact(string $dir): array
    {
        $probe = fopen($dir.'/lock', 'c');
        $busy = ! flock($probe, LOCK_EX | LOCK_NB);
        try {
            $path = $dir.'/operational-progress.json';
            $data = $this->small($path, 8192);
            $rootFingerprint = app(CompactSnapshotProgress::class)->fingerprint($dir.'/state.json');
            $checkpoint = $data ? $dir.'/logical-snapshot/'.($data['attempt_id'] ?? '').'/checkpoint.json' : null;
            $checkpointChanged = $checkpoint && is_file($checkpoint) && ($data['checkpoint_fingerprint'] ?? null) !== app(CompactSnapshotProgress::class)->fingerprint($checkpoint);
            if (! $data || ($data['root_fingerprint'] ?? null) !== $rootFingerprint || $checkpointChanged) {
                if ($busy) {
                    return array_replace($this->filterCompact($data ?? $this->small($dir.'/state.json', 65536) ?? []), ['run_id' => basename($dir), 'busy' => true, 'next_action' => 'wait', 'blocked_reason' => 'busy']);
                }
                // One-time legacy bootstrap from small root/cache. No checkpoint scan, even on cache miss.
                $state = $this->small($dir.'/state.json', 65536);
                if (! $state) {
                    throw new MigrationExportException('operational_progress_missing', 503);
                }
                $this->publishOperational($dir, $state);
                $data = $this->small($path, 8192);
                if (! $data) {
                    throw new MigrationExportException('operational_progress_missing', 503);
                }
            }
            if ($this->datasetRecoveryAllowed($data)) {
                $data['dataset_recovery_allowed'] = true;
                $data['recovery_reason_code'] = 'recoverable_dataset_boundary_conflict';
                $data['next_action'] = 'resume';
                $data['blocked_reason'] = null;
                $data['resume_allowed'] = true;
                $data['snapshot_retry_allowed'] = false;
            }
            $data['busy'] = $busy;
            if ($busy) {
                $data['next_action'] = 'wait';
            }
            foreach (['last-tick.json' => 'last_tick', 'last-status-error.json' => 'last_status_error'] as $file => $key) {
                if ($diagnostic = $this->small($dir.'/'.$file, 4096)) {
                    $data[$key] = $diagnostic;
                }
            }

            return $this->filterCompact($data);
        } finally {
            if (! $busy) {
                flock($probe, LOCK_UN);
            }
            fclose($probe);
        }
    }

    private function small(string $path, int $limit): ?array
    {
        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) > $limit) {
            return null;
        }
        try {
            return json_decode(file_get_contents($path, false, null, 0, $limit + 1), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    private function publishOperational(string $dir, array $state): void
    {
        $snapshot = in_array($state['state'], ['snapshot_pending', 'snapshot_running', 'snapshot_paused', 'snapshot_failed', 'snapshot_completed'], true);
        $logical = $snapshot && ! in_array($state['phase'], ['photo_inventory', 'relation_audit'], true);
        $progress = $logical ? app(CompactSnapshotProgress::class)->read($dir, $state['snapshot_attempt_id']) : [
            'processed' => $state['phase'] === 'photo_inventory' ? ($state['inventory']['tasks'] ?? 0) : ($state['processed_images'] ?? 0),
            'total' => $state['phase'] === 'photo_inventory' ? null : ($state['total_images'] ?? null),
            'cursor' => $state['inventory']['row'] ?? $state['package']['cursor'] ?? null, 'current_dataset' => $state['phase'],
        ];
        $next = match (true) {
            $state['state'] === 'completed' => 'download',
            $state['state'] === 'snapshot_paused', isset($state['resume_state']) => 'resume',
            $this->datasetRecoveryAllowed(array_replace($state, $progress ?? [])) => 'resume',
            in_array($state['state'], ['snapshot_failed', 'snapshot_aborted', 'failed'], true) => 'blocked',
            $snapshot => 'snapshot_tick', default => 'tick',
        };
        $data = $this->filterCompact(array_replace($state, $progress ?? ['processed' => null, 'total' => null, 'remaining' => null, 'cursor' => null, 'current_dataset' => null], [
            'next_action' => $next, 'busy' => false, 'resume_allowed' => ! in_array($next, ['blocked', 'download'], true),
            'blocked_reason' => $next === 'blocked' ? ($state['last_error'] ?? $state['state']) : null,
            'snapshot_retry_allowed' => ! $this->datasetRecoveryAllowed(array_replace($state, $progress ?? [])) && in_array($state['state'], ['snapshot_pending', 'snapshot_running', 'snapshot_paused', 'snapshot_failed'], true) && ! is_dir($dir.'/images'),
            'snapshot_abort_allowed' => false, 'photo_retry_allowed' => ! isset($state['photo_retry']) && array_sum(array_intersect_key($state['photo_results'] ?? [], array_flip(['COPY_FAILED', 'UNREADABLE_ORIGINAL', 'INSUFFICIENT_DISK_SPACE']))) > 0,
        ]));
        $data['remaining'] ??= isset($data['total'], $data['processed']) ? max(0, $data['total'] - $data['processed']) : null;
        $data['dataset_recovery_allowed'] = $this->datasetRecoveryAllowed($data);
        $data['last_success'] ??= null;
        $data['attempt_id'] = $state['snapshot_attempt_id'];
        $data['last_tick_reason'] = $this->small($dir.'/last-tick.json', 4096)['reason_code'] ?? null;
        $data['progress_unavailable'] = $logical && $progress === null;
        $data['root_fingerprint'] = app(CompactSnapshotProgress::class)->fingerprint($dir.'/state.json');
        $checkpoint = $dir.'/logical-snapshot/'.$state['snapshot_attempt_id'].'/checkpoint.json';
        $data['checkpoint_fingerprint'] = is_file($checkpoint) ? app(CompactSnapshotProgress::class)->fingerprint($checkpoint) : null;
        unset($data['last_tick'], $data['last_status_error']);
        $bytes = json_encode($data, JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 8192) {
            throw new MigrationExportException('operational_progress_budget_exceeded', 500);
        }
        $this->write($dir.'/operational-progress.json', $data);
    }

    private function datasetRecoveryAllowed(array $data): bool
    {
        return ($data['state'] ?? null) === 'snapshot_failed' && ($data['last_error'] ?? null) === 'snapshot_row_updated_after_cutoff'
            && BrowserMigrationSnapshot::mutableDatasetAllowed($data['current_dataset'] ?? null);
    }

    public function datasetConflict(string $dir, string $attempt): array
    {
        $lock = fopen($dir.'/lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new MigrationExportException('busy', 503);
        }
        try {
            return $this->snapshots->diagnoseDatasetConflict($dir, $attempt);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function filterCompact(array $data): array
    {
        return array_intersect_key($data, array_flip(['run_id', 'schema_version', 'snapshot_attempt_id', 'snapshot_mode', 'state', 'phase',
            'current_dataset', 'processed', 'total', 'remaining', 'cursor', 'next_action', 'busy', 'resume_allowed', 'blocked_reason',
            'last_success', 'last_error', 'snapshot_state', 'snapshot_retry_allowed', 'snapshot_abort_allowed', 'photo_retry_allowed', 'last_tick', 'last_status_error', 'attempt_id', 'last_tick_reason', 'checkpoint_fingerprint', 'progress_unavailable', 'dataset_generation', 'dataset_boundary_id', 'previous_boundary_id', 'boundary_refreshed_at', 'recovery_reason', 'consistency_mode', 'dataset_recovery', 'dataset_recovery_allowed', 'recovery_reason_code', 'source_conflict', 'recovery_strategy', 'mutable_round', 'mutable_dirty_rows', 'mutable_dirty_shards', 'mutable_reused_shards', 'exported_records', 'verification_processed']));
    }

    public function download(string $dir, string $key): string
    {
        $state = $this->read($dir.'/state.json');
        if ($state['state'] !== 'completed') {
            throw new MigrationExportException('artifact_not_found', 409);
        }
        foreach ($state['download_artifacts'] ?? [] as $artifact) {
            if ($artifact['key'] === $key && is_file($dir.'/'.$artifact['path'])) {
                return $dir.'/'.$artifact['path'];
            }
        }
        throw new MigrationExportException('artifact_not_found', 404);
    }

    private function inventory(string $dir, array &$state): void
    {
        $index = $this->snapshots->index($dir, $state['snapshot_attempt_id']);
        $names = ['part_images', 'parts'];
        $work = &$state['inventory'];
        $name = $names[$work['dataset']] ?? null;
        if ($name === null) {
            $this->truncate($dir.'/photo-tasks.jsonl', $work['bytes']);
            $state['total_images'] = $work['tasks'];
            $state['photo_tasks_checksum_chain'] = $work['chain'];
            $state['sample_ids'] = array_values($work['sample_ids']);
            $state['state'] = $state['phase'] = 'photos';
            $state['snapshot_state'] = 'snapshot_completed';
            $state['last_error'] = null;

            return;
        }
        $descriptor = $index['datasets'][$name]['shards'][$work['shard']] ?? null;
        if ($descriptor === null) {
            $work['dataset']++;
            $work['shard'] = $work['row'] = 0;

            return;
        }
        $rows = $this->verifiedRows($dir, $descriptor);
        $tasks = $sold = $toList = '';
        for ($n = 0; $n < 20 && isset($rows[$work['row']]); $n++, $work['row']++) {
            $row = $rows[$work['row']];
            if ($name === 'parts' && count($work['sample_ids']) < 5) {
                $work['sample_ids'][(string) $row['id']] = (string) $row['id'];
            }
            if ($name === 'parts') {
                if (($row['status'] ?? null) === 'sold') {
                    $sold .= $this->csvRow([(string) $row['id']]);
                }
                if ((bool) ($row['needs_listing'] ?? false)) {
                    $toList .= $this->csvRow([(string) $row['id']]);
                }
            }
            if ($name === 'part_images') {
                $task = $this->originals->task($row, ['id' => $row['part_id']]);
            } elseif (! empty($row['code_photo_path'])) {
                $task = $this->originals->task(['id' => 'code', 'part_id' => $row['id'], 'path' => $row['code_photo_path'], 'role' => 'code_photo'], $row);
            } else {
                continue;
            }
            // Deterministic per-image subdirectory preserves original names and prevents collisions.
            $task['package_path'] = 'images/'.$task['part_id'].'/image-'.$task['image_id'].'/'.basename($task['package_path']);
            $line = json_encode($task, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
            $tasks .= $line;
            $work['chain'] = hash('sha256', $work['chain'].$line);
            $work['tasks']++;
        }
        $this->append($dir.'/photo-tasks.jsonl', $work['bytes'], $tasks);
        $work['bytes'] += strlen($tasks);
        if ($name === 'parts') {
            foreach (['sold' => $sold, 'to_list' => $toList] as $group => $lines) {
                if (($work[$group.'_bytes'] ?? 0) === 0) {
                    $lines = "\xEF\xBB\xBF".$this->csvRow(['part_id']).$lines;
                }
                $path = $group === 'sold' ? 'sold-part-ids.csv' : 'parts-to-list-ids.csv';
                $this->append($dir.'/'.$path, $work[$group.'_bytes'] ?? 0, $lines);
                $work[$group.'_bytes'] = ($work[$group.'_bytes'] ?? 0) + strlen($lines);
            }
        }
        if ($work['row'] >= count($rows)) {
            $work['shard']++;
            $work['row'] = 0;
        }
        $state['last_success'] = now()->toIso8601String();
    }

    private function startPhotoRetry(string $dir, array &$state): void
    {
        $id = (string) Str::uuid();
        $history = $dir.'/package-history/'.$id;
        mkdir($history, 0700, true);
        $this->write($history.'/state.json', $state);
        $folder = 'photo-retries/'.$id;
        mkdir($dir.'/'.$folder, 0700, true);
        $state['photo_retry'] = ['generation' => 'retry-'.$id, 'history' => 'package-history/'.$id, 'tasks_file' => $folder.'/tasks.jsonl', 'tasks_offset' => 0, 'results_offset' => 0, 'bytes' => 0, 'count' => 0];
        $state['state'] = 'photos';
        $state['phase'] = 'photos_retry_setup';
        $state['last_error'] = null;
        unset($state['photo_retry_requested'], $state['download_artifacts'], $state['sample_download_artifacts'], $state['package']);
        // Persist the intent before the first rename. Interrupted setup resumes idempotently.
        $this->write($dir.'/state.json', $state);
    }

    private function finishPhotoRetrySetup(string $dir, array &$state): void
    {
        $history = $dir.'/'.$state['photo_retry']['history'];
        foreach (['archive-input.jsonl', 'archive-checkpoint.json', 'archive-manifest.json', 'archive-volumes', 'migration-manifest.json', 'sample', 'sample-work', 'sample-archive-input.jsonl', 'sample-archive-checkpoint.json', 'sample-archive-manifest.json', 'sample-archive-volumes', 'photo-manifest.csv', 'photo-manifest.jsonl', 'missing-originals.jsonl', 'fields.generated.json', 'statuses.json', 'sample-product-ids.json'] as $name) {
            if (file_exists($dir.'/'.$name) && (file_exists($history.'/'.$name) || ! rename($dir.'/'.$name, $history.'/'.$name))) {
                throw new RuntimeException('photo_retry_history_failed');
            }
        }
        $state['phase'] = 'photos_retry_inventory';
    }

    private function retryInventory(string $dir, array &$state): void
    {
        $work = &$state['photo_retry'];
        $tasks = fopen($dir.'/photo-tasks.jsonl', 'rb');
        $results = fopen($dir.'/photos.jsonl', 'rb');
        fseek($tasks, $work['tasks_offset']);
        fseek($results, $work['results_offset']);
        $lines = '';
        try {
            for ($n = 0; $n < 20 && ($taskLine = fgets($tasks)) !== false; $n++) {
                $resultLine = fgets($results);
                if ($resultLine === false) {
                    throw new RuntimeException('photo_retry_journal_mismatch');
                }
                $task = json_decode($taskLine, true, 32, JSON_THROW_ON_ERROR);
                $row = $this->photos->latestResult($dir, json_decode($resultLine, true, 32, JSON_THROW_ON_ERROR));
                if ((string) $task['part_id'] !== (string) $row['part_id'] || (string) $task['image_id'] !== (string) $row['image_id']) {
                    throw new RuntimeException('photo_retry_journal_mismatch');
                }
                if ($this->photos->retryable($row) && $task['source']['status'] === 'LOCATED') {
                    $lines .= $taskLine;
                    $work['count']++;
                }
            }
            $work['tasks_offset'] = ftell($tasks);
            $work['results_offset'] = ftell($results);
            $done = feof($tasks);
        } finally {
            fclose($tasks);
            fclose($results);
        }
        $this->append($dir.'/'.$work['tasks_file'], $work['bytes'], $lines);
        $work['bytes'] += strlen($lines);
        if ($done) {
            $state['phase'] = 'photos_retry';
        }
    }

    private function startPackaging(array &$state): void
    {
        $state['state'] = 'archive';
        $state['phase'] = 'archive_index';
        $state['package'] = ['stage' => 'photos', 'cursor' => 0, 'photos_offset' => 0, 'artifact_bytes' => 0, 'manifest_bytes' => 0, 'missing_bytes' => 0, 'manifest_csv_bytes' => 0];
        $state['photo_results'] = [];
    }

    private function packageIndex(string $dir, array &$state): void
    {
        $work = &$state['package'];
        $index = $this->snapshots->index($dir, $state['snapshot_attempt_id']);
        $artifacts = '';
        if ($work['stage'] === 'datasets') {
            $files = $this->snapshots->files($dir, $state['snapshot_attempt_id']);
            for ($n = 0; $n < 20 && isset($files[$work['cursor']]); $n++, $work['cursor']++) {
                $file = $files[$work['cursor']];
                $artifacts .= $this->artifact($dir, $file['path'], $file['archive_path'] ?? $file['path'], $file['sha256']);
            }
            if ($work['cursor'] >= count($files)) {
                $work['stage'] = 'metadata';
            }
        } elseif ($work['stage'] === 'photos') {
            $stream = fopen($dir.'/photos.jsonl', 'rb');
            fseek($stream, $work['photos_offset']);
            $manifest = $missing = $csv = '';
            if ($work['photos_offset'] === 0) {
                $csv = "\xEF\xBB\xBF".$this->csvRow(['part_id', 'image_id', 'original_filename', 'package_path', 'sort_order', 'is_primary', 'bytes', 'sha256', 'status']);
            }
            for ($n = 0; $n < 20 && ($line = fgets($stream)) !== false; $n++) {
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                $row = $this->photos->latestResult($dir, $row);
                $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                $manifest .= $line;
                $state['photo_results'][$row['status']] = ($state['photo_results'][$row['status']] ?? 0) + 1;
                $csv .= $this->csvRow(array_map(fn ($key) => $row[$key] ?? null, ['part_id', 'image_id', 'original_filename', 'package_path', 'sort_order', 'is_primary', 'bytes', 'sha256', 'status']));
                if ($row['status'] === 'COPIED_VERIFIED') {
                    $artifacts .= $this->artifact($dir, $row['package_path'], $row['package_path'], $row['sha256'], $row['bytes']);
                } else {
                    $missing .= $line;
                }
            }
            $work['photos_offset'] = ftell($stream);
            $atEnd = feof($stream);
            fclose($stream);
            $this->append($dir.'/photo-manifest.jsonl', $work['manifest_bytes'], $manifest);
            $this->append($dir.'/missing-originals.jsonl', $work['missing_bytes'], $missing);
            $this->append($dir.'/photo-manifest.csv', $work['manifest_csv_bytes'], $csv);
            foreach (['manifest_hash' => $manifest, 'missing_hash' => $missing, 'manifest_csv_hash' => $csv] as $key => $bytes) {
                $work[$key] = SerializableSha256::update($work[$key] ?? SerializableSha256::initial(), $bytes);
            }
            $work['manifest_bytes'] += strlen($manifest);
            $work['missing_bytes'] += strlen($missing);
            $work['manifest_csv_bytes'] += strlen($csv);
            if ($atEnd) {
                $work['stage'] = 'datasets';
                $state['phase'] = 'archive_sample';
            }
        } elseif ($work['stage'] === 'metadata') {
            $manifest = $this->manifest($index, $state);
            $this->write($dir.'/migration-manifest.json', $manifest);
            $this->write($dir.'/sample-product-ids.json', $state['sample_ids'] ?? []);
            $this->write($dir.'/fields.generated.json', ['datasets' => array_map(fn ($d) => array_intersect_key($d, array_flip(['source_table', 'exported_columns', 'csv_columns', 'column_definitions', 'relations', 'omitted_columns'])), $index['datasets']), 'formats' => $manifest['format']]);
            $this->write($dir.'/statuses.json', ['part_statuses' => ['draft' => 'Draft', 'needs_review' => 'Needs review', 'ready' => 'Ready', 'published' => 'Published', 'sold' => 'Sold by the panel status rule', 'archived' => 'Archived'], 'order_statuses' => Order::STATUSES, 'source_counts' => $index['source_counts'], 'group_rules' => $manifest['groups']]);
            foreach (['README.md' => 'gpswiss-browser-export.md', 'integration-stop-procedure.md' => 'gpswiss-export-and-sync-stop.md'] as $target => $source) {
                $bytes = file_get_contents(base_path('docs/migration/'.$source));
                if (@file_put_contents($dir.'/'.$target.'.tmp', $bytes) !== strlen($bytes) || ! chmod($dir.'/'.$target.'.tmp', 0600) || ! rename($dir.'/'.$target.'.tmp', $dir.'/'.$target)) {
                    throw new RuntimeException('export_instructions_write_failed');
                }
            }
            foreach (['sold-part-ids.csv', 'parts-to-list-ids.csv'] as $group) {
                if (! is_file($dir.'/'.$group)) {
                    $this->append($dir.'/'.$group, 0, "\xEF\xBB\xBF".$this->csvRow(['part_id']));
                }
            }
            foreach (['migration-manifest.json', 'sample-product-ids.json', 'fields.generated.json', 'statuses.json', 'sold-part-ids.csv', 'parts-to-list-ids.csv', 'relation-issues.jsonl', 'photo-manifest.jsonl', 'photo-manifest.csv', 'missing-originals.jsonl', 'README.md', 'integration-stop-procedure.md'] as $name) {
                $sha = match ($name) {
                    'photo-manifest.jsonl' => SerializableSha256::digest($work['manifest_hash']),
                    'photo-manifest.csv' => SerializableSha256::digest($work['manifest_csv_hash']),
                    'missing-originals.jsonl' => SerializableSha256::digest($work['missing_hash']),
                    'relation-issues.jsonl' => $state['relation_audit']['journal_sha256'],
                    default => null,
                };
                $artifacts .= $this->artifact($dir, $name, $name, $sha);
            }
            $work['stage'] = 'done';
            $state['phase'] = 'archive_volumes';
        }
        $this->append($dir.'/archive-input.jsonl', $work['artifact_bytes'], $artifacts);
        $work['artifact_bytes'] += strlen($artifacts);
        $work['artifact_count'] = ($work['artifact_count'] ?? 0) + substr_count($artifacts, "\n");
        $state['last_success'] = now()->toIso8601String();
    }

    private function manifest(array $index, array $state): array
    {
        return ['schema_version' => 'gpswiss-wystawczesc-3', 'run_id' => $state['run_id'], 'snapshot_attempt_id' => $state['snapshot_attempt_id'],
            'snapshot' => $index, 'format' => ['csv' => 'UTF-8 BOM; semicolon; JSON for arrays; \\N means null; leading backslash escaped; formula text prefixed with apostrophe',
                'ids_and_codes' => 'text, preserve leading zeros', 'dates' => 'as stored; no inferred timezone', 'relations' => 'separate datasets linked by retained source IDs'],
            'source_instance' => 'gpswiss.pl', 'contains_customer_data' => true, 'access' => 'authenticated_owner_only_no_public_url',
            'photo_manifest' => 'photo-manifest.csv', 'photo_jsonl' => 'photo-manifest.jsonl', 'missing_originals' => 'missing-originals.jsonl',
            'photo_consistency' => 'frozen DB metadata; originals fingerprinted during inventory and verified during bounded copy; no coordinated filesystem point-in-time snapshot',
            'groups' => ['sold' => "parts.status === 'sold'", 'to_list' => 'parts.needs_listing = true; never infer from zero stock or absent offer URL'],
            'relation_audit' => $state['relation_audit'] ?? null, 'relation_issues' => 'relation-issues.jsonl',
            'allegro_mapping_policy' => 'historical_only_never_activate', 'ebay_ovoko_mapping_policy' => 'retain IDs, URLs, market/account identity; explicit adoption required',
            'sample_source_ids' => $state['sample_ids'] ?? [], 'photo_status_counts' => $state['photo_results'] ?? [],
            'photo_retry_generations' => $state['photo_retry_generations'] ?? [],
            'source_remains_independent_copy' => true, 'cutover_ready' => false, 'automatic_import_allowed' => false, 'automatic_publish' => false,
            'synchronization_enabled' => false, 'provider_calls' => false, 'database_writes' => false, 'source_file_writes' => false];
    }

    private function downloads(string $dir, array $result, string $namespace = 'full'): array
    {
        $downloads = array_map(fn ($v) => ['key' => $v['key'], 'path' => $v['path'], 'name' => $v['name'], 'bytes' => $v['bytes'], 'sha256' => $v['sha256'], 'kind' => 'volume'], $result['volumes']);
        $manifests = $namespace === 'sample' ? ['sample-archive-manifest.json' => 'sample-archive-manifest', 'sample/manifest.json' => 'sample-migration-manifest']
            : ['archive-manifest.json' => 'archive-manifest', 'migration-manifest.json' => 'migration-manifest'];
        foreach ($manifests as $path => $key) {
            $downloads[] = ['key' => $key, 'path' => $path, 'name' => $path, 'bytes' => filesize($dir.'/'.$path), 'sha256' => hash_file('sha256', $dir.'/'.$path), 'kind' => 'manifest'];
        }

        return $downloads;
    }

    private function artifact(string $dir, string $path, string $archivePath, ?string $sha = null, ?int $bytes = null): string
    {
        return json_encode(['path' => $path, 'archive_path' => $archivePath, 'bytes' => $bytes ?? filesize($dir.'/'.$path), 'sha256' => $sha ?? hash_file('sha256', $dir.'/'.$path)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function verifiedRows(string $dir, array $shard): array
    {
        $file = $dir.'/'.$shard['path'];
        if (! hash_equals($shard['sha256'], hash_file('sha256', $file))) {
            throw new RuntimeException('snapshot_chunk_corrupted');
        }

        return $this->read($file);
    }

    private function attempt(array $state, ?string $attempt): void
    {
        if (($state['snapshot_attempt_id'] ?? null) !== $attempt) {
            throw new MigrationExportException('stale_snapshot_attempt', 409);
        }
    }

    private function locked(string $dir, callable $operation, bool $recoverable = false, bool $compact = false): array
    {
        $lock = fopen($dir.'/lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return $compact ? $this->compact($dir) : $this->status($dir, null, true);
        }
        try {
            $operation();
        } catch (MigrationExportException $error) {
            throw $error;
        } catch (\Throwable) {
            $state = $this->read($dir.'/state.json');
            if ($recoverable) {
                $state['resume_state'] = $state['state'];
                $state['state'] = 'failed';
            } else {
                $state['state'] = $state['snapshot_state'] = 'snapshot_failed';
            }
            $state['last_error'] = 'browser_export_tick_failed';
            $this->write($dir.'/state.json', $state);
        } finally {
            try {
                $root = $compact ? $this->small($dir.'/state.json', 65536) : $this->read($dir.'/state.json');
                if ($root) {
                    $this->publishOperational($dir, $root);
                }
            } catch (\Throwable) { // Derived progress must never invalidate a committed data checkpoint.
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $compact ? $this->compact($dir) : $this->status($dir);
    }

    private function append(string $path, int $offset, string $bytes): void
    {
        $file = fopen($path, 'c+b');
        chmod($path, 0600);
        try {
            if (fstat($file)['size'] < $offset || ! ftruncate($file, $offset) || fseek($file, $offset) !== 0
                || fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file)) {
                throw new RuntimeException('export_append_failed');
            }
        } finally {
            fclose($file);
        }
    }

    private function truncate(string $path, int $bytes): void
    {
        $this->append($path, $bytes, '');
    }

    private function csvRow(array $values): string
    {
        $file = fopen('php://temp', 'w+b');
        fputcsv($file, array_map(fn ($v) => $v === null ? '\\N' : (is_bool($v) ? ($v ? '1' : '0') : (preg_match('/^[\s\x00-\x1f]*[=+@-]/u', (string) $v) ? "'".$v : (string) $v)), $values), ';', '"', '');
        rewind($file);
        $line = stream_get_contents($file);
        fclose($file);

        return $line;
    }

    private function read(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function write(string $path, array $data): void
    {
        $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($path.'.tmp', $bytes) !== strlen($bytes) || ! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
            throw new RuntimeException('export_checkpoint_write_failed');
        }
    }
}
