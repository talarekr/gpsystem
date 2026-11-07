<?php

namespace App\Services\MigrationExport;

/** One server contract for bounded work before and after record capture. */
final class BrowserMigrationWork
{
    private const CAPTURE = ['snapshot_boundaries', 'snapshot_export', 'snapshot_verify', 'snapshot_recovery_preflight', 'snapshot_operator_finalize'];

    private const PREPARE = ['relation_audit', 'photo_inventory'];

    private const PHOTOS = ['photos', 'photos_retry_setup', 'photos_retry_inventory', 'photos_retry'];

    private const PACKAGE = ['archive_index', 'archive_sample', 'archive_sample_volumes', 'archive_volumes', 'archive_catalog'];

    public static function action(array $state): ?string
    {
        $root = $state['state'] ?? '';
        $phase = $state['phase'] ?? '';

        return match (true) {
            in_array($root, ['snapshot_pending', 'snapshot_running'], true) && in_array($phase, self::CAPTURE, true) => 'snapshot_tick',
            in_array($root, ['snapshot_running', 'snapshot_completed'], true) && in_array($phase, self::PREPARE, true) => 'snapshot_tick',
            $root === 'photos' && in_array($phase, self::PHOTOS, true) => 'tick',
            $root === 'archive' && in_array($phase, self::PACKAGE, true) => 'tick',
            default => null,
        };
    }

    public static function next(array $state, bool $busy = false, bool $recoverable = false): string
    {
        return match (true) {
            $busy => 'wait',
            ($state['state'] ?? '') === 'completed' => 'download',
            in_array($state['state'] ?? '', ['snapshot_paused', 'pipeline_paused'], true), isset($state['resume_state']) => 'resume',
            $recoverable => 'resume',
            ! empty($state['last_error']) => 'blocked',
            default => self::action($state) ?? 'blocked',
        };
    }
}
