<?php

namespace App\Services\MigrationExport;

/** Metadata only: a separately supplied archive is never claimed to be present or verified. */
final class ExternalPhotoArchive
{
    public const STATUS = 'EXTERNAL_ARCHIVE_NOT_VERIFIED';

    public const EXTRA_COLUMNS = ['external_relative_path', 'external_path_status', 'original_availability'];

    public static function selected(array $state): bool
    {
        return ($state['photo_delivery']['mode'] ?? null) === 'external_archive';
    }

    public static function contract(array $state): array
    {
        return ['mode' => self::selected($state) ? 'external_archive' : 'included_copies',
            'external_archive_required' => self::selected($state),
            'originals_included' => ! self::selected($state),
            'verification' => self::selected($state) ? 'not_performed; bytes/sha256/mime unknown; availability not checked' : 'see per-image copy/verification results',
            'path_mapping' => 'external_relative_path is relative to public storage contents; preserve directories and original names; package_path is null for external files',
            'instructions' => 'external-photos.md', 'selection' => $state['photo_delivery'] ?? null];
    }

    public static function row(array $task): array
    {
        $row = array_intersect_key($task, array_flip(['entity_type', 'entity_id', 'car_id', 'image_id', 'part_id', 'role', 'sort_order', 'is_primary', 'alt_text', 'original_filename', 'original_relative_path']));
        $status = $task['source']['status'] ?? 'REFERENCE_ONLY';

        return $row + ['package_path' => null, 'external_relative_path' => $row['original_relative_path'] ?? null,
            'external_path_status' => in_array($status, ['LOCATED', 'REFERENCE_ONLY', 'MISSING_ORIGINAL', 'UNREADABLE_OR_UNSAFE'], true) ? 'REFERENCE_ONLY' : $status,
            'original_availability' => 'not_checked', 'status' => self::STATUS, 'bytes' => null, 'sha256' => null, 'mime' => null,
            'extension' => strtolower(pathinfo($row['original_filename'] ?? '', PATHINFO_EXTENSION)) ?: null,
            'export_status' => 'external', 'skipped_reason' => 'originals_supplied_separately'];
    }
}
