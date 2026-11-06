<?php

namespace App\Services\MigrationExport;

use Illuminate\Support\Str;

/** Starts only the dedicated snapshot CLI; never a Laravel queue, scheduler, or HTTP fallback. */
final class MigrationSnapshotLauncher
{
    public function launch(int $owner, string $run, string $attempt): array
    {
        if ($owner < 0 || ! Str::isUuid($run) || ! Str::isUuid($attempt)) {
            throw new MigrationExportException('invalid_snapshot_worker_arguments', 422);
        }
        $result = ['launcher_status' => 'worker_launch_unavailable',
            'snapshot_manual_command' => 'php artisan gps:migration-snapshot --owner-id='.$owner.' --run='.$run.' --attempt='.$attempt];
        $php = config('migration_export.php_cli');
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (DIRECTORY_SEPARATOR !== '/' || ! is_string($php) || ! str_starts_with($php, '/')
            || ! is_file($php) || ! is_executable($php) || ! function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return $result;
        }
        $nohup = collect(['/usr/bin/nohup', '/bin/nohup'])->first(fn ($path) => is_file($path) && is_executable($path));
        if (! $nohup || ! is_file(base_path('artisan'))) {
            return $result;
        }
        $memory = max(128, min(4096, (int) config('migration_export.snapshot_memory_mb', 1024)));
        $arguments = [$nohup, $php, '-d', 'memory_limit='.$memory.'M', base_path('artisan'), 'gps:migration-snapshot',
            '--owner-id='.$owner, '--run='.$run, '--attempt='.$attempt, '--no-interaction'];
        $command = implode(' ', array_map('escapeshellarg', $arguments)).' </dev/null >/dev/null 2>&1 &';
        try {
            // Close only the short-lived shell. nohup's child owns the snapshot lock and lifecycle.
            $process = @proc_open(['/bin/sh', '-c', $command], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, base_path());
            if (is_resource($process) && proc_close($process) === 0) {
                $result['launcher_status'] = 'worker_launch_requested';
            }
        } catch (\Throwable) {
            // Status must be confirmed by the worker lock/heartbeat, never by shell success alone.
        }

        return $result;
    }
}
