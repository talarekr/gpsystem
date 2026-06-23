<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkshopImageDiagnosticsController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(hash_equals(self::TOKEN, (string) $request->query('token')), 403);

        $partId = (int) $request->query('part_id');
        abort_unless($partId > 0, 422, 'part_id is required.');

        $part = Part::query()->with('images')->findOrFail($partId);
        $repair = $request->boolean('repair_direct_original')
            ? $this->repairDirectWorkshopOriginals($part)
            : [];

        if ($repair !== []) {
            $part->refresh()->load('images');
        }

        $referenceUrl = (string) $request->query('reference_url', 'https://gpswiss.pl/storage/parts/photos/imported/63924/23201.jpg');

        $adminTableImageUrl = $part->adminTableImageUrl();
        $primaryImageUrl = $part->primaryImageUrl();
        $listingImageUrl = $part->listingImageUrl();

        return response()->json([
            'part_id' => $part->id,
            'storage_paths' => $this->storagePathsPayload($request, $part),
            'request_host' => $request->getHost(),
            'app_url' => config('app.url'),
            'public_disk_url' => config('filesystems.disks.public.url'),
            'reference_url' => $referenceUrl,
            'reference_url_host' => parse_url($referenceUrl, PHP_URL_HOST),
            'admin_table_image_url' => $adminTableImageUrl,
            'admin_table_image_url_host' => parse_url((string) $adminTableImageUrl, PHP_URL_HOST),
            'primary_image_url' => $primaryImageUrl,
            'primary_image_url_host' => parse_url((string) $primaryImageUrl, PHP_URL_HOST),
            'listing_image_url' => $listingImageUrl,
            'listing_image_url_host' => parse_url((string) $listingImageUrl, PHP_URL_HOST),
            'repair_direct_original' => $repair,
            'images_relation_count' => $part->images->count(),
            'images' => $part->images->map(fn (PartImage $image): array => $this->imagePayload($image))->values(),
        ]);
    }

    private function storagePathsPayload(Request $request, Part $part): array
    {
        $newWorkshopPath = ltrim((string) $request->query('new_workshop_path', $part->images->first()?->path), '/');
        $oldImportedReferencePath = ltrim((string) $request->query(
            'old_imported_reference_path',
            'parts/photos/imported/63924/23201.jpg'
        ), '/');
        $diagnosticPath = $newWorkshopPath !== '' ? $newWorkshopPath : $oldImportedReferencePath;
        $publicStorageRoot = $this->publicStorageRoot();
        $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);

        return [
            'storage_disk_public_root' => config('filesystems.disks.public.root'),
            'storage_disk_public_full_path' => Storage::disk('public')->path($diagnosticPath),
            'storage_disk_public_exists' => $diagnosticPath !== '' && Storage::disk('public')->exists($diagnosticPath),
            'public_path_storage' => public_path('storage'),
            'public_path_storage_is_link' => is_link(public_path('storage')),
            'public_path_storage_realpath' => realpath(public_path('storage')) ?: null,
            'document_root' => $documentRoot !== '' ? $documentRoot : null,
            'document_root_storage_path' => $documentRoot !== '' ? $documentRoot.DIRECTORY_SEPARATOR.'storage' : null,
            'document_root_storage_exists' => $documentRoot !== '' && file_exists($documentRoot.DIRECTORY_SEPARATOR.'storage'),
            'gpswiss_expected_public_file_path' => $this->publicStorageFilePath($diagnosticPath, $publicStorageRoot),
            'gpswiss_expected_public_file_exists' => $diagnosticPath !== '' && is_file($this->publicStorageFilePath($diagnosticPath, $publicStorageRoot)),
            'old_imported_reference_path' => $oldImportedReferencePath,
            'old_imported_reference_storage_exists' => $oldImportedReferencePath !== '' && Storage::disk('public')->exists($oldImportedReferencePath),
            'old_imported_reference_public_exists' => $oldImportedReferencePath !== '' && is_file($this->publicStorageFilePath($oldImportedReferencePath, $publicStorageRoot)),
            'new_workshop_path' => $newWorkshopPath,
            'new_workshop_storage_exists' => $newWorkshopPath !== '' && Storage::disk('public')->exists($newWorkshopPath),
            'new_workshop_public_exists' => $newWorkshopPath !== '' && is_file($this->publicStorageFilePath($newWorkshopPath, $publicStorageRoot)),
        ];
    }

    private function publicStorageRoot(): string
    {
        $configuredRoot = trim((string) config('filesystems.served_public_storage_root', ''));
        if ($configuredRoot !== '') {
            return rtrim($configuredRoot, DIRECTORY_SEPARATOR);
        }

        $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);
        if ($documentRoot !== '') {
            return $documentRoot.DIRECTORY_SEPARATOR.'storage';
        }

        $siblingPublicHtmlStorage = dirname(base_path()).DIRECTORY_SEPARATOR.'public_html'.DIRECTORY_SEPARATOR.'storage';
        if (is_dir(dirname($siblingPublicHtmlStorage))) {
            return $siblingPublicHtmlStorage;
        }

        return public_path('storage');
    }

    private function publicStorageFilePath(string $relativePath, string $publicStorageRoot): string
    {
        return $publicStorageRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
    }


    /** @return array<int, array<string, mixed>> */
    private function repairDirectWorkshopOriginals(Part $part): array
    {
        $repairs = [];

        foreach ($part->images as $image) {
            $oldPath = ltrim((string) $image->path, '/');

            if (! Str::startsWith($oldPath, 'parts/photos/') || Str::contains(Str::after($oldPath, 'parts/photos/'), '/')) {
                continue;
            }

            if ($image->source_system !== 'workshop_quick_create') {
                continue;
            }

            if (! Storage::disk('public')->exists($oldPath)) {
                $repairs[] = [
                    'image_id' => $image->id,
                    'old_path' => $oldPath,
                    'status' => 'skipped_missing_source',
                ];

                continue;
            }

            $newPath = 'parts/photos/imported/'.$part->id.'/'.basename($oldPath);

            if (! Storage::disk('public')->exists($newPath)) {
                Storage::disk('public')->copy($oldPath, $newPath);
            }

            $payload = $image->legacy_payload ?? [];
            if (is_array($payload)) {
                data_set($payload, 'presentation.source_path', $newPath);
            }

            $image->forceFill([
                'path' => $newPath,
                'legacy_payload' => $payload,
            ])->save();

            $repairs[] = [
                'image_id' => $image->id,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'status' => 'repaired',
            ];
        }

        return $repairs;
    }

    private function imagePayload(PartImage $image): array
    {
        $path = ltrim((string) $image->path, '/');
        $publicPath = str_starts_with($path, 'storage/') ? substr($path, strlen('storage/')) : $path;
        $storagePublicExists = $publicPath !== '' && Storage::disk('public')->exists($publicPath);
        $storageDefaultExists = $path !== '' && Storage::exists($path);
        $absolutePath = $storagePublicExists ? Storage::disk('public')->path($publicPath) : null;

        $listingUrl = $image->listingUrl();
        $productUrl = $image->productUrl();

        return [
            'id' => $image->id,
            'part_id' => $image->part_id,
            'path' => $image->path,
            'url' => $image->absolutePublicUrl(),
            'disk' => 'public',
            'filename' => basename($path),
            'alt_text' => $image->alt_text,
            'sort_order' => $image->sort_order,
            'is_primary' => $image->is_primary,
            'source_system' => $image->source_system,
            'storage_public_exists' => $storagePublicExists,
            'storage_default_exists' => $storageDefaultExists,
            'storage_url' => $publicPath !== '' ? Storage::disk('public')->url($publicPath) : null,
            'storage_url_host' => $publicPath !== '' ? parse_url(Storage::disk('public')->url($publicPath), PHP_URL_HOST) : null,
            'asset_url' => $publicPath !== '' ? asset('storage/'.$publicPath) : null,
            'asset_url_host' => $publicPath !== '' ? parse_url(asset('storage/'.$publicPath), PHP_URL_HOST) : null,
            'gpswiss_storage_url' => $publicPath !== '' ? 'https://gpswiss.pl/storage/'.$publicPath : null,
            'technical_storage_url' => $publicPath !== '' ? 'https://gpsystem.thecamels.pl/storage/'.$publicPath : null,
            'relative_storage_url' => $publicPath !== '' ? '/storage/'.$publicPath : null,
            'admin_image_url_accessor' => $image->absolutePublicUrl(),
            'admin_image_url_host' => parse_url((string) $image->absolutePublicUrl(), PHP_URL_HOST),
            'listing_url_accessor' => $listingUrl,
            'listing_url_host' => parse_url((string) $listingUrl, PHP_URL_HOST),
            'product_url_accessor' => $productUrl,
            'product_url_host' => parse_url((string) $productUrl, PHP_URL_HOST),
            'expected_public_path' => $publicPath,
            'file_size' => $absolutePath && is_file($absolutePath) ? filesize($absolutePath) : null,
            ...$this->presentationPayload($image, 'listing'),
            ...$this->presentationPayload($image, 'product'),
            'columns' => $image->getAttributes(),
            'legacy_presentation' => $image->legacy_payload['presentation'] ?? null,
        ];
    }

    private function presentationPayload(PartImage $image, string $variant): array
    {
        $path = data_get($image->legacy_payload, 'presentation.'.$variant.'_path');
        $publicPath = is_string($path) ? $this->publicPath($path) : null;
        $exists = $publicPath !== null && Storage::disk('public')->exists($publicPath);
        $absolutePath = $exists ? Storage::disk('public')->path($publicPath) : null;

        return [
            'presentation_'.$variant.'_path' => $publicPath,
            'presentation_'.$variant.'_exists_public' => $exists,
            'presentation_'.$variant.'_url' => $publicPath !== null ? Storage::disk('public')->url($publicPath) : null,
            'presentation_'.$variant.'_file_size' => $absolutePath && is_file($absolutePath) ? filesize($absolutePath) : null,
        ];
    }

    private function publicPath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $urlPath = parse_url($path, PHP_URL_PATH);

            if (! is_string($urlPath) || ! str_starts_with($urlPath, '/storage/')) {
                return null;
            }

            $path = substr($urlPath, strlen('/storage/'));
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path !== '' ? $path : null;
    }

}
