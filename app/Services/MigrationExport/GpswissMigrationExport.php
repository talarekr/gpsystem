<?php

namespace App\Services\MigrationExport;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/** Private, resumable filesystem artifacts only. No queue, DB writes, source mutations, API or importer. */
final class GpswissMigrationExport
{
    private array $snapshotLocks = [];

    public function __construct(private GpswissSnapshot $snapshot, private MigrationOriginals $originals, private BrowserMigrationExport $browser) {}

    /** The panel never launches a shell, subprocess, scheduler or queue. */
    public function prepareBrowser(int $owner, ?string $id = null): array
    {
        $id ??= (string) Str::uuid();
        $this->validateId($id);
        $existing = is_file($this->root().'/'.$id.'/state.json');
        $state = $this->prepare($owner, $id);
        if ($existing) {
            return $state;
        }

        return $this->browser->begin($this->directory($owner, $id), $this->read($this->directory($owner, $id).'/state.json'));
    }

    public function retryBrowserSnapshot(int $owner, string $id, ?string $expectedAttempt): array
    {
        $dir = $this->directory($owner, $id);
        $state = $this->read($dir.'/state.json');
        if (($state['snapshot_mode'] ?? null) === 'browser') {
            return $this->browser->restart($dir, $expectedAttempt, $this->snapshotSourceFingerprint());
        }
        // A legacy pending attempt has no frozen data to carry over. Explicit retry fences its old CLI token.
        $this->retrySnapshot($owner, $id, $expectedAttempt);

        return $this->browser->begin($dir, $this->read($dir.'/state.json'));
    }

    public function browserSnapshotTick(int $owner, string $id, ?string $expectedAttempt): array
    {
        $dir = $this->directory($owner, $id);
        $state = $this->read($dir.'/state.json');
        if (($state['snapshot_mode'] ?? null) !== 'browser') {
            throw new MigrationExportException('panel_snapshot_retry_required', 409);
        }
        if (($state['snapshot_source_fingerprint'] ?? null) !== $this->snapshotSourceFingerprint()) {
            throw new MigrationExportException('snapshot_source_context_mismatch', 409);
        }

        return $this->browser->snapshotTick($dir, $expectedAttempt);
    }

    public function kickBrowserSnapshot(int $owner, string $id, string $expectedAttempt): array
    {
        return $this->browser->kick($this->directory($owner, $id, true), $expectedAttempt, $this->snapshotSourceFingerprint());
    }

    public function pauseBrowserSnapshot(int $owner, string $id): array
    {
        $dir = $this->directory($owner, $id);
        if (($this->read($dir.'/state.json')['snapshot_mode'] ?? null) !== 'browser') {
            throw new MigrationExportException('panel_snapshot_retry_required', 409);
        }

        return $this->browser->pause($dir);
    }

    public function retryBrowserPhotos(int $owner, string $id): array
    {
        $dir = $this->directory($owner, $id);
        if (($this->read($dir.'/state.json')['snapshot_mode'] ?? null) !== 'browser') {
            throw new MigrationExportException('panel_snapshot_retry_required', 409);
        }

        return $this->browser->retryPhotos($dir);
    }

    public function markTickFatal(int $owner, string $id, string $reason): void
    {
        $dir = $this->directory($owner, $id);
        if (! is_file($dir.'/last-tick.json')) {
            return;
        }
        $trace = $this->read($dir.'/last-tick.json');
        if (($trace['reason_code'] ?? null) !== 'tick_running') {
            return;
        }
        $trace['reason_code'] = $reason === 'backend_timeout' ? 'backend_timeout' : 'backend_fatal_error';
        $trace['backend_exception_class'] = 'PHPFatalError';
        $trace['duration_ms'] = isset($trace['started_at']) ? (int) ((microtime(true) - $trace['started_at']) * 1000) : null;
        $this->write($dir.'/last-tick.json', $trace);
    }

    public function markStatusFatal(int $owner, string $id, string $reason): void
    {
        if (! in_array($reason, ['backend_timeout', 'memory_exhaustion', 'php_fatal_error'], true)) {
            $reason = 'php_fatal_error';
        }
        $dir = $this->directory($owner, $id);
        $this->write($dir.'/last-status-error.json', ['action' => 'compact_status', 'reason_code' => $reason, 'backend_exception_class' => 'PHPFatalError', 'recorded_at' => now()->toIso8601String()]);
    }

    /** Explicit synchronous entry point for the dedicated CLI and existing local callers. */
    public function start(int $owner, ?string $id = null): array
    {
        $state = $this->prepare($owner, $id);

        return $state['state'] === 'snapshot_pending'
            ? $this->runSnapshot($owner, $state['run_id'], $state['snapshot_attempt_id']) : $state;
    }

    /** HTTP only prepares a private filesystem request; it never captures business data. */
    public function prepare(int $owner, ?string $id = null): array
    {
        $id ??= (string) Str::uuid();
        $this->validateId($id);
        $lock = fopen($this->root().'/owner-'.$owner.'.lock', 'c');
        chmod($this->root().'/owner-'.$owner.'.lock', 0600);
        flock($lock, LOCK_EX);
        try {
            if (is_file($this->root().'/'.$id.'/state.json')) {
                return $this->status($owner, $id);
            }
            $existing = $this->discover($owner);
            if ($existing['existing_run_found']) {
                throw new MigrationExportException('existing_export_unfinished', 409, ['run_id' => $existing['run_id']]);
            }

            return $this->prepareRun($owner, $id);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function prepareRun(int $owner, string $id): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('zip_extension_required');
        }
        $this->validateId($id);
        $dir = $this->root().'/'.$id;
        if (is_file($dir.'/state.json')) {
            return $this->status($owner, $id);
        }
        if (is_dir($dir)) {
            throw new MigrationExportException('checkpoint_missing', 404);
        }
        mkdir($dir, 0700, true);
        $startLock = fopen($dir.'/lock', 'c');
        chmod($dir.'/lock', 0600);
        flock($startLock, LOCK_EX);
        if (is_file($dir.'/state.json')) {
            flock($startLock, LOCK_UN);
            fclose($startLock);

            return $this->status($owner, $id);
        }
        try {
            $state = ['schema_version' => 'gpswiss-wystawczesc-2', 'contains_customer_data' => true, 'run_id' => $id, 'owner_id' => $owner,
                'state' => 'snapshot_pending', 'snapshot_state' => 'snapshot_pending', 'snapshot_attempt_id' => (string) Str::uuid(),
                'snapshot_source_fingerprint' => $this->snapshotSourceFingerprint(),
                'snapshot_requested_at' => now()->toIso8601String(), 'processed_images' => 0, 'photo_results' => [], 'last_error' => null];
            $this->write($dir.'/owner.json', ['owner_id' => $owner, 'run_id' => $id]);
            $this->write($dir.'/state.json', $state);
        } finally {
            flock($startLock, LOCK_UN);
            fclose($startLock);
        }

        return $this->status($owner, $id);
    }

    /** One worker owns the entire transaction and publication; no cross-request DB snapshot continuation. */
    public function runSnapshot(int $owner, string $id, string $attempt): array
    {
        $this->validateId($attempt);
        $dir = $this->directory($owner, $id);
        $lock = fopen($dir.'/lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return $this->status($owner, $id);
        }
        $this->snapshotLocks[$id] = $lock;
        try {
            $state = $this->read($dir.'/state.json');
            if (($state['snapshot_mode'] ?? null) === 'browser') {
                throw new MigrationExportException('panel_snapshot_tick_required', 409);
            }
            if (($state['snapshot_attempt_id'] ?? null) !== $attempt) {
                throw new MigrationExportException('stale_snapshot_attempt', 409);
            }
            if ($state['state'] !== 'snapshot_pending') {
                return $this->status($owner, $id);
            }
            if ($this->snapshotArtifactsExist($dir, $state)) {
                throw new MigrationExportException('snapshot_retry_not_allowed', 409);
            }
            $state['state'] = $state['snapshot_state'] = 'snapshot_running';
            $state['snapshot_started_at'] = now()->toIso8601String();
            $state['last_error'] = null;
            $this->write($dir.'/state.json', $state);
            if (($state['snapshot_source_fingerprint'] ?? null) !== $this->snapshotSourceFingerprint()) {
                throw new RuntimeException('snapshot_source_context_mismatch');
            }
            $progress = function (array $value) use ($dir, $attempt): void {
                $this->write($dir.'/snapshot-heartbeat.json', ['attempt_id' => $attempt, 'at' => now()->toIso8601String()] + $value);
            };
            $progress(['stage' => 'database_snapshot']);
            $data = $this->snapshot->capture($progress);
            $progress(['stage' => 'photo_tasks', 'processed' => 0]);
            $parts = array_column($data['products'], null, 'id');
            $tasks = [];
            $orphans = [];
            foreach ($data['images'] as $image) {
                if (! isset($parts[$image['part_id']])) {
                    $orphans[] = $image['id'];

                    continue;
                }
                $tasks[] = $this->originals->task($image, $parts[$image['part_id']]);
                if (count($tasks) % 100 === 0) {
                    $progress(['stage' => 'photo_tasks', 'processed' => count($tasks)]);
                }
            }
            foreach ($data['products'] as &$part) {
                if (! empty($part['code_photo_path'])) {
                    $tasks[] = $this->originals->task(['id' => 'code', 'part_id' => $part['id'], 'path' => $part['code_photo_path'], 'role' => 'code_photo'], $part);
                }
                unset($part['code_photo_path']);
            }
            unset($part, $data['images']);
            $usedPaths = [];
            foreach ($tasks as &$task) {
                $key = mb_strtolower($task['package_path']);
                if (isset($usedPaths[$key])) {
                    $task['package_path'] = dirname($task['package_path']).'/image-'.$task['image_id'].'/'.basename($task['package_path']);
                }
                $usedPaths[mb_strtolower($task['package_path'])] = true;
            }
            unset($task);
            $data['orphan_image_ids'] = $orphans;
            $progress(['stage' => 'publishing_snapshot']);
            $this->write($dir.'/snapshot.json', $data);
            $this->write($dir.'/photo-tasks.json', $tasks);
            $receipt = ['attempt_id' => $attempt, 'snapshot_sha256' => hash_file('sha256', $dir.'/snapshot.json'),
                'photo_tasks_sha256' => hash_file('sha256', $dir.'/photo-tasks.json'), 'snapshot_at' => $data['snapshot_at'],
                'source_counts' => $data['source_counts'], 'total_images' => count($tasks),
                'files_snapshot_note' => 'DB snapshot is consistent. File fingerprints captured afterwards; global filesystem freeze is not asserted.'];
            $this->write($dir.'/snapshot-ready.json', $receipt);
            $state = $this->snapshotCompletedState($state, $receipt);
            $state['state'] = 'snapshot_completed';
            $this->write($dir.'/state.json', $state);
            $state['state'] = 'photos';
            $this->write($dir.'/state.json', $state);
            $progress(['stage' => 'snapshot_completed']);
        } catch (MigrationExportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $state['state'] = $state['snapshot_state'] = 'snapshot_failed';
            $state['last_error'] = in_array($e->getMessage(), ['snapshot_requires_innodb', 'snapshot_driver_not_supported', 'existing_transaction_not_supported', 'snapshot_source_context_mismatch'], true) ? $e->getMessage() : 'snapshot_capture_failed';
            $this->write($dir.'/state.json', $state);
        } finally {
            unset($this->snapshotLocks[$id]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $this->status($owner, $id);
    }

    public function retrySnapshot(int $owner, string $id, ?string $expectedAttempt): array
    {
        return $this->changeSnapshotAttempt($owner, $id, $expectedAttempt, false);
    }

    public function abortSnapshot(int $owner, string $id, ?string $expectedAttempt): array
    {
        return $this->changeSnapshotAttempt($owner, $id, $expectedAttempt, true);
    }

    private function changeSnapshotAttempt(int $owner, string $id, ?string $expectedAttempt, bool $abort): array
    {
        $dir = $this->directory($owner, $id);
        $lock = fopen($dir.'/lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new MigrationExportException($abort ? 'snapshot_abort_not_allowed' : 'snapshot_retry_not_allowed', 409);
        }
        try {
            $state = $this->read($dir.'/state.json');
            if (($state['snapshot_attempt_id'] ?? null) !== $expectedAttempt) {
                throw new MigrationExportException('stale_snapshot_attempt', 409);
            }
            if (! $this->snapshotPhase($state) || ($state['schema_version'] ?? null) !== 'gpswiss-wystawczesc-2'
                || $this->readySnapshot($dir, $state) || (! $abort && $this->snapshotArtifactsExist($dir, $state))) {
                throw new MigrationExportException($abort ? 'snapshot_abort_not_allowed' : 'snapshot_retry_not_allowed', 409);
            }
            if ($abort) {
                $state['state'] = $state['snapshot_state'] = 'snapshot_aborted';
                $state['snapshot_aborted_at'] = now()->toIso8601String();
                $state['last_error'] = 'ABORTED_SNAPSHOT';
            } else {
                foreach (['snapshot.json.tmp', 'photo-tasks.json.tmp', 'snapshot-ready.json.tmp', 'snapshot-heartbeat.json.tmp', 'state.json.tmp'] as $temporary) {
                    if (is_file($dir.'/'.$temporary) && ! unlink($dir.'/'.$temporary)) {
                        throw new MigrationExportException('snapshot_temp_cleanup_failed', 409);
                    }
                }
                $state['snapshot_previous_attempt_id'] = $state['snapshot_attempt_id'] ?? null;
                $state['snapshot_attempt_id'] = (string) Str::uuid();
                $state['snapshot_source_fingerprint'] = $this->snapshotSourceFingerprint();
                $state['snapshot_attempts'] = ($state['snapshot_attempts'] ?? 1) + 1;
                $state['snapshot_requested_at'] = now()->toIso8601String();
                $state['state'] = $state['snapshot_state'] = 'snapshot_pending';
                $state['last_error'] = null;
                unset($state['snapshot_started_at'], $state['snapshot_launch_status']);
            }
            $this->write($dir.'/state.json', $state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $this->status($owner, $id);
    }

    public function recordSnapshotLaunch(int $owner, string $id, string $attempt, string $status): void
    {
        if (! in_array($status, ['worker_launch_requested', 'worker_launch_unavailable', 'worker_launch_failed'], true)) {
            return;
        }
        $dir = $this->directory($owner, $id);
        $lock = fopen($dir.'/lock', 'c');
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            try {
                $state = $this->read($dir.'/state.json');
                if (($state['snapshot_mode'] ?? null) !== 'browser' && ($state['snapshot_attempt_id'] ?? null) === $attempt && $state['state'] === 'snapshot_pending') {
                    $state['snapshot_launch_status'] = $status;
                    $this->write($dir.'/state.json', $state);
                }
            } finally {
                flock($lock, LOCK_UN);
            }
        }
        fclose($lock);
    }

    /** Best effort for fatal shutdown; an unlocked running checkpoint is also classified interrupted. */
    public function snapshotFailure(int $owner, string $id, string $attempt, string $reason): void
    {
        if (! in_array($reason, ['snapshot_fatal_error', 'snapshot_timeout', 'snapshot_memory_exhausted', 'snapshot_worker_failed'], true)) {
            $reason = 'snapshot_worker_failed';
        }
        $dir = $this->directory($owner, $id);
        $ownedLock = isset($this->snapshotLocks[$id]) && is_resource($this->snapshotLocks[$id]);
        $lock = $ownedLock ? $this->snapshotLocks[$id] : fopen($dir.'/lock', 'c');
        if (! $ownedLock && ! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }
        try {
            $state = $this->read($dir.'/state.json');
            if (($state['snapshot_mode'] ?? null) !== 'browser' && ($state['snapshot_attempt_id'] ?? null) === $attempt && $state['state'] === 'snapshot_running') {
                $state['state'] = $state['snapshot_state'] = 'snapshot_failed';
                $state['last_error'] = $reason;
                $this->write($dir.'/state.json', $state);
            }
        } finally {
            if (! $ownedLock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function snapshotPhase(array $state): bool
    {
        return in_array($state['state'], ['snapshot', 'snapshot_pending', 'snapshot_running', 'snapshot_completed', 'snapshot_failed'], true)
            || ($state['state'] === 'failed' && str_starts_with((string) ($state['last_error'] ?? ''), 'snapshot'));
    }

    private function snapshotSourceFingerprint(): string
    {
        // Resolve URL overrides through the connection factory, but never retain credentials or URLs.
        $identity = array_intersect_key(DB::connection()->getConfig(),
            array_flip(['driver', 'host', 'port', 'database', 'unix_socket', 'prefix']));
        ksort($identity);

        return hash('sha256', json_encode(['environment' => app()->environment(), 'connection' => $identity], JSON_THROW_ON_ERROR));
    }

    private function snapshotArtifactsExist(string $dir, array $state): bool
    {
        if (($state['processed_images'] ?? 0) !== 0) {
            return true;
        }
        $technical = ['owner.json', 'state.json', 'lock', 'snapshot-heartbeat.json', 'snapshot.json.tmp', 'photo-tasks.json.tmp', 'snapshot-ready.json.tmp', 'snapshot-heartbeat.json.tmp', 'state.json.tmp'];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && ! in_array($name, $technical, true)) {
                return true;
            }
        }

        return false;
    }

    private function readySnapshot(string $dir, array $state): ?array
    {
        if (! is_file($dir.'/snapshot-ready.json') || ! is_file($dir.'/snapshot.json') || ! is_file($dir.'/photo-tasks.json')) {
            return null;
        }
        try {
            $receipt = $this->read($dir.'/snapshot-ready.json');
            if (($receipt['attempt_id'] ?? null) !== ($state['snapshot_attempt_id'] ?? null)
                || ! isset($receipt['snapshot_at'], $receipt['source_counts'], $receipt['total_images'])
                || ! hash_equals((string) ($receipt['snapshot_sha256'] ?? ''), hash_file('sha256', $dir.'/snapshot.json'))
                || ! hash_equals((string) ($receipt['photo_tasks_sha256'] ?? ''), hash_file('sha256', $dir.'/photo-tasks.json'))) {
                return null;
            }

            return $receipt;
        } catch (\Throwable) {
            return null;
        }
    }

    private function snapshotCompletedState(array $state, array $receipt): array
    {
        return array_replace($state, array_intersect_key($receipt, array_flip(['snapshot_at', 'source_counts', 'total_images', 'files_snapshot_note'])),
            ['snapshot_state' => 'snapshot_completed', 'snapshot_completed_at' => now()->toIso8601String(), 'last_error' => null]);
    }

    public function tick(int $owner, string $id): array
    {
        $directory = $this->directory($owner, $id);
        if (($this->read($directory.'/state.json')['snapshot_mode'] ?? null) === 'browser') {
            return $this->browser->tick($directory);
        }
        $before = $this->status($owner, $id);
        if (! $before['resume_allowed']) {
            if ($before['state'] === 'completed') {
                return $before;
            }
            throw new MigrationExportException($before['blocked_reason'] === 'artifact_not_found' ? 'artifact_not_found' : 'other', 409);
        }
        $dir = $this->directory($owner, $id);
        $lock = fopen($dir.'/lock', 'c');
        chmod($dir.'/lock', 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return $this->status($owner, $id) + ['busy' => true];
        }
        try {
            $state = $this->read($dir.'/state.json');
            if ($this->snapshotPhase($state)) {
                $receipt = $this->readySnapshot($dir, $state);
                if (! $receipt) {
                    throw new RuntimeException('snapshot_not_ready');
                }
                $state = $this->snapshotCompletedState($state, $receipt);
                $state['state'] = 'photos';
                $this->write($dir.'/state.json', $state);
            }
            $tasks = $this->read($dir.'/photo-tasks.json');
            $records = $this->photoRecords($dir);
            if (isset($state['verification_cursor'])) {
                $deadline = microtime(true) + 3;
                for ($n = 0; $n < 20 && $state['verification_cursor'] < $state['processed_images'] && microtime(true) < $deadline; $n++) {
                    $index = $state['verification_cursor'];
                    $task = $tasks[$index];
                    $record = $records[$task['part_id'].':'.$task['image_id']] ?? null;
                    if (! $record || ($record['status'] === 'COPIED_VERIFIED' && ! $this->verifiedPhoto($dir, $record))) {
                        $state['repair_queue'][] = $index;
                    }
                    $state['verification_cursor']++;
                    $this->write($dir.'/state.json', $state);
                }
                $state['repair_queue'] = array_values(array_unique($state['repair_queue'] ?? []));
                if ($state['verification_cursor'] >= $state['processed_images']) {
                    unset($state['verification_cursor']);
                    $state['state'] = $state['repair_queue'] || $state['processed_images'] < count($tasks) ? 'photos' : 'archive';
                }
            } elseif ($state['state'] === 'photos') {
                $deadline = microtime(true) + 3;
                for ($n = 0; $n < 20 && microtime(true) < $deadline; $n++) {
                    $repairing = ! empty($state['repair_queue']);
                    $index = $repairing ? $state['repair_queue'][0] : $state['processed_images'];
                    if (! isset($tasks[$index])) {
                        break;
                    }
                    $task = $tasks[$index];
                    $key = $task['part_id'].':'.$task['image_id'];
                    $record = $records[$key] ?? null;
                    if ($record && $record['status'] === 'COPIED_VERIFIED' && $this->verifiedPhoto($dir, $record)) {
                        $result = $record;
                    } else {
                        $result = $this->originals->copy($task, $dir);
                        $this->appendPhotoRecord($dir, $result);
                        $records[$key] = $result;
                    }
                    if ($repairing) {
                        array_shift($state['repair_queue']);
                    } else {
                        $state['processed_images']++;
                    }
                    $state['last_success'] = ['part_id' => $task['part_id'], 'image_id' => $task['image_id'], 'result' => $result['status'], 'at' => now()->toIso8601String()];
                    $state['photo_results'] = array_count_values(array_column($records, 'status'));
                    $this->write($dir.'/state.json', $state);
                }
                if ($state['processed_images'] === count($tasks) && empty($state['repair_queue'])) {
                    $state['state'] = 'archive';
                    $state['verification_cursor'] = 0;
                }
            } elseif ($state['state'] === 'archive') {
                $snapshot = $this->read($dir.'/snapshot.json');
                $photos = array_values($records);
                $sample = $this->sampleIds($snapshot['products'], $photos);
                $this->package($dir, 'sample', $snapshot, $photos, $sample);
                $this->package($dir, 'full', $snapshot, $photos, array_column($snapshot['products'], 'id'));
                $state['state'] = 'completed';
                $state['sample_ids'] = $sample;
                $state['archives'] = [];
                foreach (['sample', 'full'] as $kind) {
                    $state['archives'][$kind] = ['bytes' => filesize($dir.'/'.$kind.'.zip'), 'sha256' => hash_file('sha256', $dir.'/'.$kind.'.zip')];
                }
                $state['last_success'] = ['phase' => 'archive', 'at' => now()->toIso8601String()];
            }
            $state['last_error'] = null;
            $this->write($dir.'/state.json', $state);
        } catch (\Throwable) {
            $state['last_error'] = 'export_tick_failed_resume_same_run';
            $this->write($dir.'/state.json', $state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $this->status($owner, $id);
    }

    private function appendPhotoRecord(string $dir, array $record): void
    {
        $file = $dir.'/photos.jsonl';
        $stream = fopen($file, 'a+');
        chmod($file, 0600);
        try {
            $stat = fstat($stream);
            $prefix = '';
            if ($stat['size'] > 0) {
                fseek($stream, -1, SEEK_END);
                if (fread($stream, 1) !== "\n") {
                    $prefix = "\n";
                }
            }
            fseek($stream, 0, SEEK_END);
            $line = $prefix.json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
            if (fwrite($stream, $line) !== strlen($line) || ! fflush($stream)) {
                throw new RuntimeException('photo_journal_write_failed');
            }
        } finally {
            fclose($stream);
        }
    }

    private function photoRecords(string $dir): array
    {
        $records = [];
        foreach ((is_file($dir.'/photos.jsonl') ? file($dir.'/photos.jsonl', FILE_IGNORE_NEW_LINES) : []) as $line) {
            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            } // a torn final append can be repeated from durable cursor
            if (isset($record['part_id'], $record['image_id'], $record['status'])) {
                $records[$record['part_id'].':'.$record['image_id']] = $record;
            }
        }

        return $records;
    }

    private function verifiedPhoto(string $dir, array $photo): bool
    {
        $relative = $photo['package_path'] ?? '';
        if (! str_starts_with($relative, 'images/') || in_array('..', explode('/', $relative), true)) {
            return false;
        }
        $path = realpath($dir.'/'.$relative);
        if (! $path || ! str_starts_with($path, realpath($dir).DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return false;
        }

        return filesize($path) === $photo['bytes'] && is_string($photo['sha256'] ?? null) && hash_equals($photo['sha256'], hash_file('sha256', $path));
    }

    public function discover(int $owner, bool $compact = false): array
    {
        $candidates = [];
        foreach (glob($this->root().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (! Str::isUuid(basename($dir))) {
                continue;
            }
            $state = null;
            foreach (['state.json', 'owner.json'] as $name) {
                $checkpoint = $dir.'/'.$name;
                if (! is_file($checkpoint)) {
                    continue;
                }
                try {
                    $candidate = $this->read($checkpoint);
                    if (isset($candidate['owner_id'])) {
                        $state = $candidate;
                        break;
                    }
                } catch (\Throwable) {
                    // Recover only ownership evidence, never reconstruct a broken snapshot.
                }
            }
            if (($state['owner_id'] ?? null) !== $owner || in_array($state['state'] ?? '', ['completed', 'snapshot_aborted'], true)) {
                continue;
            }
            $candidates[] = ['id' => basename($dir), 'time' => filemtime($checkpoint)];
        }
        usort($candidates, fn ($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['id'], $a['id']));
        if (! $candidates) {
            return ['existing_run_found' => false, 'run_id' => null, 'run' => null, 'database_writes' => false, 'provider_calls' => false];
        }
        try {
            $status = $compact ? $this->compactStatus($owner, $candidates[0]['id']) : $this->status($owner, $candidates[0]['id']);
        } catch (MigrationExportException $e) {
            if ($compact && $e->httpStatus >= 500) {
                throw $e;
            }
            $status = ['run_id' => $candidates[0]['id'], 'state' => 'blocked', 'phase' => 'unknown',
                'processed' => null, 'remaining' => null, 'current_item' => null, 'last_success' => null, 'last_error' => $e->reasonCode,
                'resume_allowed' => false, 'blocked_reason' => $e->reasonCode, 'reason_code' => $e->reasonCode,
                'owner_match' => true, 'artifacts_present' => false, 'database_writes' => false, 'provider_calls' => false] + $e->details;
        }

        return $compact ? ['existing_run_found' => true, 'run' => $status] : ['existing_run_found' => true, 'run' => $status] + $status;
    }

    public function compactStatus(int $owner, string $id): array
    {
        $dir = $this->directory($owner, $id, true);
        if (! is_file($dir.'/operational-progress.json') && filesize($dir.'/state.json') <= 65536
            && ($this->read($dir.'/state.json')['snapshot_mode'] ?? null) !== 'browser') {
            // Preserve the existing legacy v2 diagnostics; only browser v3 uses this projection.
            return $this->browser->filterCompact($this->status($owner, $id));
        }

        return $this->browser->compact($dir);
    }

    public function datasetConflict(int $owner, string $id): array
    {
        $dir = $this->directory($owner, $id);
        $state = $this->read($dir.'/state.json');
        if (($state['snapshot_mode'] ?? null) !== 'browser' || ($state['snapshot_source_fingerprint'] ?? null) !== $this->snapshotSourceFingerprint()) {
            throw new MigrationExportException('snapshot_source_context_mismatch', 409);
        }

        return $this->browser->datasetConflict($dir, $state['snapshot_attempt_id']);
    }

    public function artifacts(int $owner, string $id): array
    {
        $state = $this->read($this->directory($owner, $id).'/state.json');
        if ($state['state'] !== 'completed') {
            throw new MigrationExportException('artifact_not_found', 409);
        }

        return ['run_id' => $id, 'state' => 'completed', 'download_artifacts' => $state['download_artifacts'] ?? []];
    }

    public function status(int $owner, string $id): array
    {
        $dir = $this->directory($owner, $id);
        $state = $this->read($dir.'/state.json');
        if (($state['snapshot_mode'] ?? null) === 'browser') {
            return $this->browser->status($dir, $state);
        }
        unset($state['owner_id']);
        $required = ['checkpoint' => is_file($dir.'/state.json'), 'snapshot' => is_file($dir.'/snapshot.json'), 'photo_tasks' => is_file($dir.'/photo-tasks.json')];
        if ($state['state'] === 'completed') {
            foreach (['sample', 'full'] as $kind) {
                $required[$kind.'_archive'] = is_file($dir.'/'.$kind.'.zip');
            }
        }
        $present = ! in_array(false, $required, true);
        $busy = false;
        $lock = fopen($dir.'/lock', 'c');
        chmod($dir.'/lock', 0600);
        if ($lock) {
            $busy = ! flock($lock, LOCK_EX | LOCK_NB);
            if (! $busy) {
                flock($lock, LOCK_UN);
            } fclose($lock);
        }
        $snapshotPhase = $this->snapshotPhase($state);
        $aborted = $state['state'] === 'snapshot_aborted';
        $ready = $snapshotPhase ? $this->readySnapshot($dir, $state) : null;
        $interrupted = in_array($state['state'], ['snapshot', 'snapshot_running'], true) && ! $busy && ! $ready;
        $snapshotState = match (true) {
            $aborted => 'snapshot_aborted',
            $ready !== null => 'snapshot_completed',
            $snapshotPhase && $busy => 'snapshot_running',
            $state['state'] === 'snapshot_pending' => 'snapshot_pending',
            $snapshotPhase => 'snapshot_failed',
            default => 'snapshot_completed',
        };
        $blocked = match (true) {
            $state['state'] === 'completed' => $present ? 'export_already_completed' : 'artifact_not_found',
            ($state['schema_version'] ?? null) !== 'gpswiss-wystawczesc-2' => 'unsupported_export_schema',
            $aborted => 'snapshot_aborted',
            $snapshotPhase && $ready && ! $busy => null,
            $snapshotPhase && $busy => 'snapshot_running',
            $snapshotPhase => $snapshotState === 'snapshot_pending' ? ($state['snapshot_launch_status'] ?? 'snapshot_worker_pending') : ($state['last_error'] ?? 'snapshot_process_interrupted'),
            $state['state'] === 'failed' => $state['last_error'] ?? 'other',
            ! $present => 'artifact_not_found',
            ! in_array($state['state'], ['photos', 'archive'], true) => 'other',
            default => null,
        };
        $retry = $snapshotPhase && ! $busy && ! $ready && ($state['schema_version'] ?? null) === 'gpswiss-wystawczesc-2' && ! $this->snapshotArtifactsExist($dir, $state);
        $heartbeat = null;
        try {
            if (is_file($dir.'/snapshot-heartbeat.json')) {
                $value = $this->read($dir.'/snapshot-heartbeat.json');
                if (($value['attempt_id'] ?? null) === ($state['snapshot_attempt_id'] ?? null)) {
                    $heartbeat = array_intersect_key($value, array_flip(['at', 'stage', 'table', 'processed', 'tables_processed']));
                }
            }
        } catch (\Throwable) {
            // An incomplete progress file never makes an otherwise valid snapshot unusable.
        }
        $item = null;
        if (($state['state'] === 'photos' || isset($state['verification_cursor'])) && $present) {
            try {
                $tasks = $this->read($dir.'/photo-tasks.json');
                $next = $state['verification_cursor'] ?? $state['repair_queue'][0] ?? $state['processed_images'];
                if (isset($tasks[$next])) {
                    $item = array_intersect_key($tasks[$next], array_flip(['part_id', 'image_id', 'role']));
                }
            } catch (\Throwable) {
                $blocked = 'artifact_not_found';
                $present = false;
            }
        }

        return array_replace($state, ['state' => $snapshotPhase || $aborted ? $snapshotState : $state['state'],
            'phase' => $snapshotPhase || $aborted ? $snapshotState : (isset($state['verification_cursor']) ? 'verification' : $state['state']),
            'processed' => (int) $state['processed_images'],
            'remaining' => max(0, ($state['total_images'] ?? 0) - $state['processed_images']) + count($state['repair_queue'] ?? []),
            'verification_remaining' => isset($state['verification_cursor']) ? max(0, $state['processed_images'] - $state['verification_cursor']) : 0,
            'last_error' => $interrupted ? 'snapshot_process_interrupted' : ($state['last_error'] ?? null),
            'snapshot_state' => $snapshotState, 'snapshot_attempt_id' => $state['snapshot_attempt_id'] ?? null,
            'launcher_status' => $state['snapshot_launch_status'] ?? null,
            'snapshot_process_active' => $snapshotPhase && $busy, 'snapshot_interrupted' => $interrupted,
            'snapshot_file_exists' => $required['snapshot'], 'snapshot_tasks_file_exists' => $required['photo_tasks'],
            'snapshot_ready' => $snapshotPhase ? $ready !== null : ($required['snapshot'] && $required['photo_tasks']),
            'snapshot_retry_allowed' => $retry, 'snapshot_abort_allowed' => $snapshotPhase && ! $busy && ! $ready && ($state['schema_version'] ?? null) === 'gpswiss-wystawczesc-2',
            'snapshot_retry_blocked_reason' => $retry ? null : ($busy ? 'snapshot_process_active' : 'snapshot_artifacts_present'),
            'snapshot_heartbeat' => $heartbeat,
            'snapshot_manual_command' => isset($state['snapshot_attempt_id']) ? 'php artisan gps:migration-snapshot --owner-id='.$owner.' --run='.$id.' --attempt='.$state['snapshot_attempt_id'] : null,
            'current_item' => $item, 'last_success' => $state['last_success'] ?? null, 'resume_allowed' => $blocked === null,
            'blocked_reason' => $blocked, 'storage_root_fingerprint' => substr(hash('sha256', realpath($this->root())), 0, 16), 'owner_match' => true, 'artifacts_present' => $present, 'artifacts' => $required, 'busy' => $busy,
            'database_writes' => false, 'source_file_writes' => false, 'export_file_writes' => true, 'provider_calls' => false,
            'marketplace_writes' => false, 'automatic_publish' => false, 'import_performed' => false]);
    }

    public function resume(int $owner, string $id, ?string $expectedAttempt = null): array
    {
        $directory = $this->directory($owner, $id);
        if (($this->read($directory.'/state.json')['snapshot_mode'] ?? null) === 'browser') {
            return $this->browser->resume($directory, $expectedAttempt, $this->snapshotSourceFingerprint());
        }
        $status = $this->status($owner, $id);
        if (! $status['resume_allowed'] || $status['busy']) {
            return $status;
        }
        $dir = $this->directory($owner, $id);
        $lock = fopen($dir.'/lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return $this->status($owner, $id);
        }
        try {
            $state = $this->read($dir.'/state.json');
            $receipt = $this->snapshotPhase($state) ? $this->readySnapshot($dir, $state) : null;
            if ($receipt) {
                $state = $this->snapshotCompletedState($state, $receipt);
                $state['state'] = 'photos';
                $this->write($dir.'/state.json', $state);
            } elseif (in_array($state['state'], ['photos', 'archive'], true) && ($state['schema_version'] ?? null) === 'gpswiss-wystawczesc-2') {
                if ($state['processed_images'] > 0 && ! isset($state['verification_cursor'])) {
                    $state['verification_cursor'] = 0;
                }
                $this->write($dir.'/state.json', $state);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $this->status($owner, $id);
    }

    public function download(int $owner, string $id, string $kind, ?string $artifact = null): string
    {
        if ($kind === 'artifact') {
            return $this->browser->download($this->directory($owner, $id), $artifact ?? '');
        }
        if (! in_array($kind, ['sample', 'full'], true)) {
            throw new MigrationExportException('other', 422);
        }
        $dir = $this->directory($owner, $id);
        if ($this->read($dir.'/state.json')['state'] !== 'completed') {
            throw new MigrationExportException('artifact_not_found', 409);
        }
        if (! is_file($dir.'/'.$kind.'.zip')) {
            throw new MigrationExportException('artifact_not_found', 404);
        }

        return $dir.'/'.$kind.'.zip';
    }

    private function package(string $dir, string $kind, array $snapshot, array $photos, array $ids): void
    {
        if ($this->verifiedArchive($dir.'/'.$kind.'.zip', $dir, $kind, $snapshot, $photos, $ids)) {
            return;
        }
        $selected = array_fill_keys($ids, true);
        $products = array_values(array_filter($snapshot['products'], fn ($p) => isset($selected[$p['id']])));
        $photos = array_values(array_filter($photos, fn ($p) => isset($selected[$p['part_id']])));
        $photosByPart = [];
        foreach ($photos as $photo) {
            $photosByPart[$photo['part_id']][] = $photo;
        }
        $cars = array_column($snapshot['cars'], null, 'id');
        $categories = array_column($snapshot['categories'], null, 'id');
        $locations = array_column($snapshot['storage_locations'], null, 'id');
        $categoryIds = array_values(array_unique(array_filter(array_column($products, 'category_id'))));
        $issues = [];
        for ($i = 0; $i < count($categoryIds); $i++) {
            $category = $categories[$categoryIds[$i]] ?? null;
            if (! $category) {
                $issues[] = ['reason' => 'missing_category', 'source_id' => $categoryIds[$i]];

                continue;
            }
            $parent = $category['parent_id'] ?? null;
            if ($parent && ! in_array($parent, $categoryIds, true)) {
                $categoryIds[] = $parent;
            }
        }
        foreach ($categoryIds as $categoryId) {
            $seen = [];
            $cursor = $categoryId;
            while ($cursor && isset($categories[$cursor])) {
                if (isset($seen[$cursor])) {
                    $issues[] = ['reason' => 'category_cycle', 'source_id' => $cursor];
                    break;
                }
                $seen[$cursor] = true;
                $cursor = $categories[$cursor]['parent_id'] ?? null;
            }
        }
        foreach ($products as &$part) {
            $part['local_car_id'] = $part['car_id'] ?? null;
            $part['ovoko_car_id'] = $cars[$part['car_id']]['ovoko_car_id'] ?? null;
            $part['category_name'] = $categories[$part['category_id']]['name'] ?? null;
            $part['storage_location_name'] = $locations[$part['storage_location_id']]['name'] ?? null;
            $part['quality'] = $part['condition_notes'] ?? null;
            $part['images'] = $photosByPart[$part['id']] ?? [];
            if ($part['car_id'] && ! isset($cars[$part['car_id']])) {
                $issues[] = ['reason' => 'missing_car', 'part_id' => $part['id']];
            }
            if ($part['storage_location_id'] && ! isset($locations[$part['storage_location_id']])) {
                $issues[] = ['reason' => 'missing_storage_location', 'part_id' => $part['id']];
            }
        }
        unset($part);
        $base = $dir.'/'.$kind;
        if (! is_dir($base)) {
            mkdir($base, 0700);
        }
        $this->write($base.'/products.json', $products);
        $this->write($base.'/categories.json', $kind === 'full' ? array_values($categories) : array_values(array_intersect_key($categories, array_flip($categoryIds))));
        $this->write($base.'/cars.json', $kind === 'full' ? array_values($cars) : array_values(array_intersect_key($cars, array_flip(array_filter(array_column($products, 'car_id'))))));
        $this->write($base.'/storage-locations.json', $kind === 'full' ? array_values($locations) : array_values(array_intersect_key($locations, array_flip(array_filter(array_column($products, 'storage_location_id'))))));
        $this->write($base.'/photos.json', $photos);
        $this->write($base.'/marketplace-category-mappings.json', array_values(array_filter($snapshot['marketplace_category_mappings'], fn ($m) => in_array($m['local_category_id'], $categoryIds, true))));
        $this->csv($base.'/products.csv', $products, ['id', 'sku', 'name', 'part_number', 'additional_part_codes', 'category_id', 'category_name', 'quality', 'part_position', 'storage_location_id', 'storage_location_name', 'description', 'local_car_id', 'ovoko_car_id', 'price', 'currency', 'allegro_price', 'ovoko_price', 'ebay_price', 'allegro_shipping_rate_name', 'allegro_parameters', 'offers', 'status', 'quantity', 'images']);
        $this->csv($base.'/photos.csv', $photos, ['part_id', 'image_id', 'role', 'original_filename', 'original_relative_path', 'package_path', 'sort_order', 'is_primary', 'status', 'bytes', 'sha256', 'mime']);
        $datasets = MigrationPackageData::select($snapshot, $kind, $products, $categoryIds);
        foreach (['products' => $products, 'cars' => $kind === 'full' ? array_values($cars) : array_values(array_intersect_key($cars, array_flip(array_filter(array_column($products, 'car_id'))))), 'categories' => $kind === 'full' ? array_values($categories) : array_values(array_intersect_key($categories, array_flip($categoryIds))), 'storage-locations' => $kind === 'full' ? array_values($locations) : array_values(array_intersect_key($locations, array_flip(array_filter(array_column($products, 'storage_location_id')))))] as $name => $rows) {
            $this->csv($base.'/'.$name.'.csv', $rows, $this->fields($rows));
        }
        if (! is_dir($base.'/datasets')) {
            mkdir($base.'/datasets', 0700);
        }
        foreach ($datasets as $table => $rows) {
            $this->write($base.'/datasets/'.$table.'.json', $rows);
            $this->csv($base.'/datasets/'.$table.'.csv', $rows, $this->fields($rows, $snapshot['schema_inventory'][$table]['exported_columns'] ?? []));
        }
        $this->write($base.'/fields.generated.json', MigrationPackageData::dictionary($snapshot, array_merge($datasets, ['parts' => $products, 'cars' => $kind === 'full' ? array_values($cars) : array_values(array_intersect_key($cars, array_flip(array_filter(array_column($products, 'car_id'))))), 'part_categories' => array_values(array_intersect_key($categories, array_flip($categoryIds))), 'storage_locations' => array_values($locations), 'part_images' => $photos])));
        $this->write($base.'/statuses.json', ['part_statuses' => ['draft', 'needs_review', 'ready', 'published', 'sold', 'archived'], 'order_statuses' => Order::STATUSES, 'group_rules' => $snapshot['group_rules'] ?? [], 'source_status_counts' => $snapshot['source_counts'], 'source_dates' => 'preserved as stored; no inferred timezone conversion']);
        foreach (['sold-part-ids' => 'sold_part_ids', 'parts-to-list-ids' => 'parts_to_list_ids'] as $name => $group) {
            $groupIds = array_values(array_filter($snapshot['groups'][$group] ?? [], fn ($id) => isset($selected[(string) $id])));
            $this->write($base.'/'.$name.'.json', $groupIds);
            $this->csv($base.'/'.$name.'.csv', array_map(fn ($id) => ['part_id' => (string) $id], $groupIds), ['part_id']);
        }
        $missing = array_values(array_filter($photos, fn ($p) => $p['status'] !== 'COPIED_VERIFIED'));
        $this->write($base.'/missing-originals.json', $missing);
        $sourceSets = array_merge($snapshot['datasets'], ['parts' => $snapshot['products'], 'cars' => $snapshot['cars'], 'part_categories' => $snapshot['categories'], 'storage_locations' => $snapshot['storage_locations']]);
        $sourceIds = [];
        foreach ($sourceSets as $table => $rows) {
            $sourceIds[$table] = array_fill_keys(array_map('strval', array_column($rows, 'id')), true);
        }
        foreach ($datasets as $table => $rows) {
            foreach ($snapshot['relations'][$table] ?? [] as $column => $target) {
                if (! is_string($target) || ! isset($sourceIds[$target])) {
                    continue;
                }
                foreach ($rows as $row) {
                    $foreign = $row[$column] ?? null;
                    if ($foreign !== null && $foreign !== '' && ! isset($sourceIds[$target][(string) $foreign])) {
                        $issues[] = ['reason' => 'missing_source_reference', 'table' => $table, 'row_id' => $row['id'] ?? null, 'column' => $column, 'target_table' => $target, 'target_id' => (string) $foreign];
                    }
                }
            }
        }
        $this->write($base.'/dependency-issues.json', $issues);
        foreach (['schema.json' => resource_path('migration-export.schema.json'), 'READ-ME.md' => base_path('docs/migration/gpswiss-export-and-sync-stop.md')] as $name => $source) {
            if ((! is_file($base.'/'.$name) || ! hash_equals(hash_file('sha256', $source), hash_file('sha256', $base.'/'.$name))) && ! copy($source, $base.'/'.$name)) {
                throw new RuntimeException('artifact_copy_failed');
            }
        }
        $hashes = [];
        foreach ($this->packageFiles($base) as $relative => $file) {
            if ($relative !== 'manifest.json') {
                $hashes[$relative] = hash_file('sha256', $file);
            }
        }
        $manifest = ['schema_version' => 'gpswiss-wystawczesc-2', 'run_id' => basename($dir), 'package_kind' => $kind, 'source_instance' => 'gpswiss.pl', 'source_environment' => $snapshot['source_environment'], 'source_host' => $snapshot['source_host'], 'privacy_review_required' => true, 'contains_customer_data' => true, 'access' => 'authenticated_owner_only_no_public_url', 'allegro_mapping_policy' => 'historical_only_never_activate', 'ebay_ovoko_mapping_policy' => 'preserve_exact_market_and_account_identity_no_execution',
            'snapshot_at' => $snapshot['snapshot_at'], 'snapshot_finished_at' => $snapshot['snapshot_finished_at'], 'database_consistency' => $snapshot['database_consistency'],
            'file_consistency' => 'fingerprints_captured_after_db_snapshot_verified_during_copy_not_global_fs_snapshot',
            'sample_case_coverage' => $this->caseCoverage($snapshot, $products), 'datasets_counts' => array_map('count', $datasets), 'schema_inventory' => $snapshot['schema_inventory'] ?? [], 'optional_tables_absent' => $snapshot['optional_tables_absent'] ?? [], 'relations' => $snapshot['relations'] ?? [], 'source_counts' => $snapshot['source_counts'], 'products_count' => count($products), 'sample_source_ids' => $kind === 'sample' ? $ids : [],
            'photo_associations' => count($photos), 'verified_originals' => count($photos) - count($missing), 'missing_or_blocked_originals' => count($missing), 'photo_status_counts' => array_count_values(array_column($photos, 'status')),
            'warnings' => $snapshot['warnings'], 'orphan_image_ids' => $snapshot['orphan_image_ids'], 'file_sha256' => $hashes,
            'automatic_import_allowed' => false, 'automatic_publish' => false, 'synchronization_enabled' => false, 'provider_calls' => false, 'database_writes' => false, 'source_file_writes' => false,
            'cutover_ready' => false, 'cutover_requires' => ['operator_review', 'global_filesystem_snapshot_or_coordinated_freeze', 'target_tenant_uuid', 'id_and_sku_collision_plan'],
            'source_remains_independent_copy' => true];
        $this->write($base.'/manifest.json', $manifest);
        $zip = new ZipArchive;
        if ($zip->open($dir.'/'.$kind.'.partial.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('archive_open_failed');
        }
        try {
            foreach ($this->packageFiles($base) as $relative => $file) {
                if (! $zip->addFile($file, $relative)) {
                    throw new RuntimeException('archive_add_failed');
                }
            }
            foreach ($photos as $photo) {
                if ($photo['status'] !== 'COPIED_VERIFIED') {
                    continue;
                }
                if (! $zip->addFile($dir.'/'.$photo['package_path'], $photo['package_path'])) {
                    throw new RuntimeException('archive_photo_failed');
                }
                $zip->setCompressionName($photo['package_path'], ZipArchive::CM_STORE); // Originals are not re-encoded/recompressed.
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('archive_close_failed');
            }
        }
        chmod($dir.'/'.$kind.'.partial.zip', 0600);
        rename($dir.'/'.$kind.'.partial.zip', $dir.'/'.$kind.'.zip');
    }

    private function sampleIds(array $parts, array $photos): array
    {
        $counts = array_count_values(array_column(array_filter($photos, fn ($p) => $p['role'] === 'gallery'), 'part_id'));
        $bad = array_column(array_filter($photos, fn ($p) => $p['status'] !== 'COPIED_VERIFIED'), 'part_id');
        $has = static fn ($p, $channel) => collect($p['offers'] ?? [])->contains(fn ($o) => str_starts_with((string) ($o['marketplace'] ?? ''), $channel));
        $ids = [];
        foreach ([fn ($p) => $p['status'] === 'sold', fn ($p) => ! empty($p['needs_listing']), fn ($p) => $has($p, 'ebay'), fn ($p) => $has($p, 'ovoko'), fn ($p) => ($counts[$p['id']] ?? 0) > 1, fn ($p) => in_array($p['id'], $bad, true)] as $predicate) {
            if (count($ids) >= 5) {
                break;
            }
            foreach ($parts as $part) {
                if (! in_array($part['id'], $ids, true) && $predicate($part)) {
                    $ids[] = (string) $part['id'];
                    break;
                }
            }
        }
        foreach ($parts as $part) {
            if (count($ids) < 5 && ! in_array($part['id'], $ids, true)) {
                $ids[] = (string) $part['id'];
            }
        }

        return $ids;
    }

    private function verifiedArchive(string $file, string $dir, string $kind, array $snapshot, array $photos, array $ids): bool
    {
        if (! is_file($file)) {
            return false;
        }
        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CHECKCONS) !== true) {
            return false;
        }
        try {
            $manifest = json_decode($zip->getFromName('manifest.json') ?: 'null', true);
            if (! is_array($manifest) || ($manifest['run_id'] ?? null) !== basename($dir) || ($manifest['package_kind'] ?? null) !== $kind || ($manifest['snapshot_at'] ?? null) !== $snapshot['snapshot_at']) {
                return false;
            }
            $selected = array_fill_keys(array_map('strval', $ids), true);
            $expectedPhotos = array_values(array_filter($photos, fn ($p) => isset($selected[(string) $p['part_id']])));
            if (json_decode($zip->getFromName('photos.json') ?: 'null', true) !== $expectedPhotos || ($manifest['products_count'] ?? null) !== count($ids)) {
                return false;
            }
            $hashes = $manifest['file_sha256'] ?? [];
            if (! isset($hashes['products.json'], $hashes['photos.json'])) {
                return false;
            }
            foreach ($expectedPhotos as $photo) {
                if ($photo['status'] === 'COPIED_VERIFIED') {
                    $hashes[$photo['package_path']] = $photo['sha256'];
                }
            }
            foreach ($hashes as $name => $expected) {
                $stream = $zip->getStream($name);
                if (! is_resource($stream)) {
                    return false;
                }
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                if (! is_string($expected) || ! hash_equals($expected, hash_final($hash))) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $zip->close();
        }
    }

    private function caseCoverage(array $snapshot, array $selected): array
    {
        $galleryCounts = array_count_values(array_column($snapshot['datasets']['part_images'] ?? [], 'part_id'));
        $hasOffer = static fn ($part, $channel) => collect($part['offers'] ?? [])->contains(fn ($offer) => str_starts_with((string) ($offer['marketplace'] ?? ''), $channel));
        $cases = ['sold' => fn ($p) => $p['status'] === 'sold', 'to_list' => fn ($p) => ! empty($p['needs_listing']),
            'ebay_offer' => fn ($p) => $hasOffer($p, 'ebay'), 'ovoko_offer' => fn ($p) => $hasOffer($p, 'ovoko'), 'multiple_photos' => fn ($p) => ($galleryCounts[$p['id']] ?? 0) > 1];
        $coverage = [];
        foreach ($cases as $name => $test) {
            $coverage[$name] = ['source_count' => count(array_filter($snapshot['products'], $test)), 'selected_ids' => array_values(array_column(array_filter($selected, $test), 'id'))];
        }

        return $coverage;
    }

    private function csv(string $path, array $rows, array $fields): void
    {
        $file = fopen($path.'.pending', 'wb');
        fwrite($file, "\xEF\xBB\xBF");
        fputcsv($file, $fields, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($file, array_map(function ($field) use ($row) {
                $value = $row[$field] ?? null;
                if ($value === null) {
                    return '\\N';
                }
                $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
                if (str_starts_with($value, '\\')) {
                    $value = '\\'.$value;
                }

                return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
            }, $fields), ';', '"', '');
        }
        fclose($file);
        chmod($path.'.pending', 0600);
        if (is_file($path) && hash_equals(hash_file('sha256', $path), hash_file('sha256', $path.'.pending'))) {
            unlink($path.'.pending');
        } else {
            rename($path.'.pending', $path);
        }
    }

    private function fields(array $rows, array $fallback = []): array
    {
        return array_values(array_unique(array_merge($fallback, ...array_map('array_keys', $rows ?: [[]]))));
    }

    private function packageFiles(string $base): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && ! preg_match('/\.(tmp|pending|partial)$/D', $file->getFilename())) {
                $files[substr($file->getPathname(), strlen($base) + 1)] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    private function root(): string
    {
        $root = config('migration_export.root', storage_path('app/private/migration-exports'));
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        foreach (array_unique([...$this->originals->roots(), realpath(public_path()) ?: public_path(), realpath(dirname(base_path()).'/public_html') ?: dirname(base_path()).'/public_html']) as $source) {
            if (realpath($root) === $source || str_starts_with(realpath($root), $source.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('export_root_must_be_private');
            }
        }

        if ((fileperms($root) & 0077) !== 0) {
            throw new RuntimeException('export_root_permissions_must_be_private');
        }

        return $root;
    }

    private function validateId(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new MigrationExportException('invalid_run_id', 422);
        }
    }

    private function directory(int $owner, string $id, bool $operational = false): string
    {
        $this->validateId($id);
        $dir = $this->root().'/'.$id;
        if (! is_dir($dir)) {
            throw new MigrationExportException('run_not_found', 404, ['storage_root_fingerprint' => substr(hash('sha256', realpath($this->root())), 0, 16)]);
        }
        if (! is_file($dir.'/state.json')) {
            throw new MigrationExportException('checkpoint_missing', 404);
        }
        if ($operational && is_file($dir.'/owner.json') && filesize($dir.'/owner.json') <= 1024) {
            $identity = $this->read($dir.'/owner.json');
            if (($identity['owner_id'] ?? null) !== $owner || ($identity['run_id'] ?? null) !== $id) {
                throw new MigrationExportException('run_owner_mismatch', 404, ['owner_match' => false]);
            }
            if (is_file($dir.'/deleted.json')) {
                throw new MigrationExportException('export_deleted', 410);
            }

            return $dir;
        }
        if ($operational && filesize($dir.'/state.json') > 65536) {
            throw new MigrationExportException('operational_identity_missing', 409);
        }
        try {
            $state = $this->read($dir.'/state.json');
        } catch (\Throwable) {
            throw new MigrationExportException('other', 409, ['diagnostic_detail' => 'checkpoint_invalid']);
        }
        if (($state['owner_id'] ?? null) !== $owner) {
            throw new MigrationExportException('run_owner_mismatch', 404, ['owner_match' => false]);
        }
        if (($state['state'] ?? '') === 'deleted' || is_file($dir.'/deleted.json')) {
            throw new MigrationExportException('export_deleted', 410);
        }
        if (! isset($state['state'], $state['processed_images']) || ! is_int($state['processed_images'])) {
            throw new MigrationExportException('other', 409, ['diagnostic_detail' => 'checkpoint_invalid']);
        }

        return $dir;
    }

    private function read(string $file): array
    {
        return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function write(string $file, array $data): void
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (is_file($file) && hash_equals(hash('sha256', $content), hash_file('sha256', $file))) {
            return;
        }
        if (@file_put_contents($file.'.tmp', $content) !== strlen($content)) {
            throw new RuntimeException('artifact_write_failed');
        }
        if (! chmod($file.'.tmp', 0600) || ! rename($file.'.tmp', $file)) {
            throw new RuntimeException('artifact_replace_failed');
        }
    }
}
