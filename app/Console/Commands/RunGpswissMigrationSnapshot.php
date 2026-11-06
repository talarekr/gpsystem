<?php

namespace App\Console\Commands;

use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Snapshot only. This process never consumes jobs or executes photo/export/import ticks. */
final class RunGpswissMigrationSnapshot extends Command
{
    protected $signature = 'gps:migration-snapshot {--owner-id= : Local export owner ID} {--run= : Existing export UUID} {--attempt= : Current snapshot attempt UUID}';

    protected $description = 'Complete one pending private GPSwiss database snapshot without business writes or provider calls';

    public function handle(GpswissMigrationExport $exports): int
    {
        $owner = filter_var($this->option('owner-id'), FILTER_VALIDATE_INT);
        $run = (string) $this->option('run');
        $attempt = (string) $this->option('attempt');
        if ($owner === false || $owner < 0 || ! Str::isUuid($run) || ! Str::isUuid($attempt)) {
            $this->error('invalid_snapshot_worker_arguments');

            return self::FAILURE;
        }
        $memory = max(128, min(4096, (int) config('migration_export.snapshot_memory_mb', 1024)));
        if (function_exists('ini_set')) {
            ini_set('memory_limit', $memory.'M');
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(0); // CLI lifecycle is independent of browser/FPM timeouts.
        }
        $reserve = str_repeat('r', 256 * 1024);
        $finished = false;
        register_shutdown_function(static function () use (&$reserve, &$finished, $exports, $owner, $run, $attempt): void {
            $reserve = null;
            $fatal = error_get_last();
            if (! $finished && $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                try {
                    $reason = str_contains($fatal['message'], 'Allowed memory size') ? 'snapshot_memory_exhausted'
                        : (str_contains($fatal['message'], 'Maximum execution time') ? 'snapshot_timeout' : 'snapshot_fatal_error');
                    $exports->snapshotFailure($owner, $run, $attempt, $reason);
                } catch (\Throwable) {
                    // SIGKILL/unwritable storage is detected later by the unlocked running checkpoint.
                }
            }
        });
        try {
            $status = $exports->runSnapshot($owner, $run, $attempt);
            $finished = true;
            $this->line(json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return ($status['snapshot_state'] ?? null) === 'snapshot_completed' || in_array($status['state'] ?? null, ['photos', 'archive', 'completed'], true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $error) {
            try {
                $exports->snapshotFailure($owner, $run, $attempt, 'snapshot_worker_failed');
            } catch (\Throwable) {
            }
            $finished = true;
            $this->error($error instanceof MigrationExportException ? $error->reasonCode : 'snapshot_worker_failed');

            return self::FAILURE;
        }
    }
}
