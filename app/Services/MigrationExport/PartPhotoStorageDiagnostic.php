<?php

namespace App\Services\MigrationExport;

use League\Flysystem\PathPrefixer;

/** Filesystem metadata only: no models, disk adapters, providers, mkdir or artifact writes. */
final class PartPhotoStorageDiagnostic
{
    public const DISK = 'public';

    public const EXAMPLE = 'parts/photos/imported/2082/031bef934b8f88568ccbe2875db3.jpg';

    public function inspect(): array
    {
        // Match Laravel's path prefixing without constructing its local adapter:
        // constructing Storage::disk() can create a missing disk root directory.
        if (config('filesystems.disks.public.driver') !== 'local') {
            throw new MigrationExportException('photo_diagnostic_requires_local_disk');
        }
        $root = config('filesystems.disks.public.root');
        if (! is_string($root) || ! str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new MigrationExportException('photo_disk_absolute_root_unavailable');
        }
        $prefixer = new PathPrefixer($root, DIRECTORY_SEPARATOR);
        $prefix = config('filesystems.disks.public.prefix');
        if (is_string($prefix) && $prefix !== '') {
            $prefixer = new PathPrefixer($prefixer->prefixPath($prefix), DIRECTORY_SEPARATOR);
        }
        $diskRoot = rtrim($prefixer->prefixPath(''), DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
        $physicalRoot = realpath($diskRoot) ?: $diskRoot;
        $absolute = (new PathPrefixer($physicalRoot, DIRECTORY_SEPARATOR))->prefixPath(self::EXAMPLE);

        return [
            'base_path' => base_path(),
            'storage_path' => storage_path(),
            'storage_path_app_public' => storage_path('app/public'),
            'public_path' => public_path(),
            'public_path_storage' => public_path('storage'),
            'part_images_disk' => self::DISK,
            'disk_root' => $diskRoot,
            'example' => [
                'db_path' => self::EXAMPLE,
                'disk' => self::DISK,
                'resolved_absolute_path' => realpath($absolute) ?: $absolute,
                'exists' => is_file($absolute),
            ],
        ];
    }
}
