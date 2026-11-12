<?php

namespace App\Services\MigrationExport;

use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use League\Flysystem\PathPrefixer;

/** Bounded SELECTs and exact local file metadata only; no adapters, processors or writes. */
final class PartPhotoStorageDiagnostic
{
    public const DISK = 'public';

    public const EXAMPLE = 'parts/photos/imported/2082/031bef934b8f88568ccbe2875db3.jpg';

    public const KNOWN_PRODUCT = 'parts/photos/presentation/product/20081-c609f1c66216.jpg';

    private const ANCHOR_IMAGE = 20081;

    public function inspect(): array
    {
        // Constructing a local Storage adapter may mkdir its root; never instantiate one here.
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
        $roots = $this->roots($diskRoot);
        [$status, $rows] = ReadOnlyDatabase::run(fn () => $this->sample());
        $images = [];
        $knownOriginal = null;
        $sourceMatch = 'record_not_found';
        foreach ($rows as $row) {
            $payload = is_array($row['legacy_payload'] ?? null) ? $row['legacy_payload'] : json_decode($row['legacy_payload'] ?? '{}', true);
            $presentation = is_array($payload) && is_array($payload['presentation'] ?? null) ? $payload['presentation'] : [];
            $original = $this->probe($row['path'] ?? null, $roots);
            $product = $this->probe($presentation['product_path'] ?? null, $roots);
            $listing = $this->probe($presentation['listing_path'] ?? null, $roots);
            $images[] = [
                'part_id' => (string) $row['part_id'], 'image_id' => (string) $row['id'],
                'db_path' => $original['db_path'], 'disk' => self::DISK,
                'resolved_absolute_path' => $original['resolved_absolute_path'], 'exists' => $original['exists'],
                'legacy_presentation' => [
                    'listing_path' => $listing['db_path'], 'product_path' => $product['db_path'],
                    'source_path' => $this->displayPath($presentation['source_path'] ?? null),
                ],
                'original' => $original, 'product' => $product, 'listing' => $listing,
                'presentation_source' => $this->probe($presentation['source_path'] ?? null, $roots),
                'historical_candidates' => $this->historicalCandidates($row, $original['relative_path'], $roots),
            ];
            // The generator uses image ID in the variant name, but an old variant can be stale.
            if ((string) $row['id'] === (string) self::ANCHOR_IMAGE) {
                $sourceMatch = $product['relative_path'] === self::KNOWN_PRODUCT ? 'exact_stored_product_path' : 'image_id_hint_only_not_verified';
                if ($sourceMatch === 'exact_stored_product_path') {
                    $knownOriginal = $original;
                }
            }
        }

        return [
            'base_path' => base_path(), 'storage_path' => storage_path(),
            'storage_path_app_public' => storage_path('app/public'), 'public_path' => public_path(),
            'public_path_storage' => public_path('storage'), 'part_images_disk' => self::DISK, 'disk_root' => $diskRoot,
            'example' => $this->probe(self::EXAMPLE, $roots), 'known_roots' => $roots,
            'known_product' => [
                'product_path_source' => 'user_supplied_public_url_path',
                'product' => $this->probe(self::KNOWN_PRODUCT, $roots),
                'listing' => $this->probe(str_replace('/product/', '/listing/', self::KNOWN_PRODUCT), $roots),
                'listing_path_source' => 'paired_filename_from_PartImagePresentationService',
                'image_id_hint' => (string) self::ANCHOR_IMAGE, 'source_match' => $sourceMatch, 'original' => $knownOriginal,
            ],
            'sample_status' => $status, 'sample_images' => $images,
            'original_locations' => $this->originalLocations($images),
            'scope' => 'at_most_5_db_images_and_fixed_paths_not_full_inventory',
        ];
    }

    private function roots(string $diskRoot): array
    {
        // Same roots used by upload/import, presentation lookup and MigrationOriginals.
        $paths = ['disk_public' => $diskRoot, 'configured_served_root' => config('filesystems.served_public_storage_root'),
            'public_path_storage' => public_path('storage'), 'sibling_public_html_storage' => dirname(base_path()).'/public_html/storage'];
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        if (is_string($documentRoot) && str_starts_with($documentRoot, '/')) {
            $paths['document_root_storage'] = rtrim($documentRoot, '/').'/storage';
        }
        $out = [];
        foreach ($paths as $source => $path) {
            if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")) {
                continue;
            }
            $path = rtrim($path, '/') ?: '/';
            $out[] = ['source' => $source, 'absolute_path' => $path, 'real_path' => realpath($path) ?: null,
                'exists' => is_dir($path), 'is_link' => is_link($path)];
        }

        return $out;
    }

    private function sample(): array
    {
        if (! Schema::hasTable('part_images')) {
            return ['table_missing', []];
        }
        $available = Schema::getColumnListing('part_images');
        if (array_diff(['id', 'part_id', 'path'], $available)) {
            return ['required_columns_missing', []];
        }
        $columns = array_values(array_intersect(['id', 'part_id', 'path', 'source_system', 'legacy_payload'], $available));
        $anchor = DB::table('part_images')->where('id', self::ANCHOR_IMAGE)->first($columns);
        $query = DB::table('part_images');
        if ($anchor) {
            $query->where('id', '<>', self::ANCHOR_IMAGE);
        }
        $rows = $query->orderBy('id')->limit($anchor ? 4 : 5)->get($columns)->map(fn ($row) => (array) $row)->all();
        if ($anchor) {
            array_unshift($rows, (array) $anchor);
        }

        return [$rows ? 'selected' : 'empty', $rows];
    }

    private function probe(mixed $raw, array $roots): array
    {
        [$relative, $pathStatus] = $this->relativePath($raw);
        $candidates = [];
        $found = [];
        $absolute = null;
        $diskExists = false;
        if ($relative !== null) {
            foreach ($roots as $root) {
                $path = (new PathPrefixer($root['real_path'] ?? $root['absolute_path'], DIRECTORY_SEPARATOR))->prefixPath($relative);
                $resolved = realpath($path) ?: $path;
                $exists = is_file($path);
                $candidates[] = ['root_source' => $root['source'], 'root_path' => $root['absolute_path'], 'physical_root_path' => $root['real_path'] ?? $root['absolute_path'],
                    'resolved_absolute_path' => $resolved, 'exists' => $exists, 'readable' => $exists && is_readable($path)];
                if ($root['source'] === 'disk_public') {
                    $absolute = $resolved;
                    $diskExists = $exists;
                }
                if ($exists) {
                    $found[$resolved] = $resolved;
                }
            }
        }

        return ['db_path' => $this->displayPath($raw), 'disk' => self::DISK, 'resolved_absolute_path' => $absolute,
            'exists' => $diskExists, 'relative_path' => $relative, 'path_status' => $pathStatus,
            'candidates' => $candidates, 'found_paths' => array_values($found),
            'resolution_status' => $relative === null ? 'not_locally_resolvable' : (count($found) > 1 ? 'multiple_physical_copies_not_compared' : ($found ? 'located' : 'missing_in_known_roots'))];
    }

    private function relativePath(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [null, 'empty'];
        }
        $path = trim($raw);
        if (strlen($path) > 4096 || preg_match('/[\x00-\x1f\\\\]/', $path)) {
            return [null, 'unsafe_path'];
        }
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $path)) {
            $url = parse_url(str_starts_with($path, '//') ? 'https:'.$path : $path);
            $hosts = array_filter([parse_url((string) config('filesystems.disks.public.url'), PHP_URL_HOST), parse_url((string) config('app.url'), PHP_URL_HOST), 'gpswiss.pl', 'www.gpswiss.pl']);
            if (! $url || ! in_array(strtolower($url['host'] ?? ''), array_map('strtolower', $hosts), true) || ! in_array($url['scheme'] ?? '', ['http', 'https'], true) || ! str_starts_with($url['path'] ?? '', '/storage/')) {
                return [null, 'remote_or_unmapped_url_not_fetched'];
            }
            $path = substr($url['path'], strlen('/storage/'));
        } else {
            if (str_starts_with($path, '/storage/')) {
                $path = substr($path, strlen('/storage/'));
            } elseif (str_starts_with($path, '/')) {
                return [null, 'absolute_db_path_not_probed'];
            } elseif (str_starts_with($path, 'storage/')) {
                $path = substr($path, strlen('storage/'));
            }
        }
        if ($this->displayPath($path) !== $path) {
            return [null, 'sensitive_path_not_probed'];
        }
        if ($path === '' || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
            return [null, 'unsafe_path'];
        }

        return [$path, 'local_key'];
    }

    private function displayPath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }
        $clean = MigrationDataPolicy::clean($path, 'path');

        return is_string($clean) ? $clean : '[invalid_path]';
    }

    private function historicalCandidates(array $row, ?string $relative, array $roots): array
    {
        if ($relative === null) {
            return [];
        }
        $part = (string) $row['part_id'];
        $paths = [];
        if (preg_match('~^parts/photos/([^/]+)$~D', $relative, $m)) {
            $paths['flat_admin_upload_move'] = 'parts/photos/admin/'.$part.'/'.$m[1];
            if (($row['source_system'] ?? null) === 'workshop_quick_create') {
                $paths['workshop_original_copy'] = 'parts/photos/imported/'.$part.'/'.$m[1];
            }
        }
        if (preg_match('~^parts/photos/admin/'.preg_quote($part, '~').'/([^/]+)$~D', $relative, $m)
            || (($row['source_system'] ?? null) === 'workshop_quick_create' && preg_match('~^parts/photos/imported/'.preg_quote($part, '~').'/([^/]+)$~D', $relative, $m))) {
            $paths['previous_flat_upload_path'] = 'parts/photos/'.$m[1];
        }
        $out = [];
        foreach ($paths as $rule => $path) {
            $out[] = ['rule' => $rule, 'candidate_only_not_content_verified' => true, 'lookup' => $this->probe($path, $roots)];
        }

        return $out;
    }

    private function originalLocations(array $images): array
    {
        $locations = [];
        foreach ($images as $image) {
            foreach ($image['original']['candidates'] as $candidate) {
                if (! $candidate['exists']) {
                    continue;
                }
                $key = $candidate['physical_root_path'];
                $locations[$key] ??= ['root_sources' => [], 'directory' => $key, 'files' => []];
                $locations[$key]['root_sources'][$candidate['root_source']] = $candidate['root_source'];
                $fileKey = $image['image_id'].'|'.$candidate['resolved_absolute_path'];
                $locations[$key]['files'][$fileKey] = ['part_id' => $image['part_id'], 'image_id' => $image['image_id'],
                    'relative_path' => $image['original']['relative_path'], 'resolved_absolute_path' => $candidate['resolved_absolute_path'], 'readable' => $candidate['readable']];
            }
        }

        return array_values(array_map(fn ($location) => array_replace($location, ['root_sources' => array_values($location['root_sources']), 'files' => array_values($location['files'])]), $locations));
    }
}
