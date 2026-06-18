<?php

namespace App\Http\Controllers\Tools;

use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class CheckPartImagePresentationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configuredToken = (string) env('PRODUCT_IMAGES_IMPORT_TOKEN', '');
        $requestToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $requestToken === '' || ! hash_equals($configuredToken, $requestToken)) {
            abort(403);
        }

        $partId = (int) $request->query('part_id');
        $part = Part::query()->with('images')->find($partId);

        if (! $part) {
            return response()->json([
                'message' => 'Part not found.',
                'part_id' => $partId,
            ], 404, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $primaryImage = $part->primaryImage();
        $listingImage = $part->listingImage();
        $publicDisk = Storage::disk('public');

        $images = $part->images
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (PartImage $image): array => $this->imageDiagnostics($image));

        return response()->json([
            'part_id' => $part->id,
            'images_count' => $part->images->count(),
            'primary_image_id' => $primaryImage?->id,
            'listing_image_id' => $listingImage?->id,
            'primary_image_url' => $part->primaryImageUrl(),
            'listing_image_url' => $part->listingImageUrl(),
            'images' => $images,
            'diagnostics' => [
                'public_path' => public_path(),
                'base_path' => base_path(),
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
                'public_disk' => [
                    'root' => config('filesystems.disks.public.root'),
                    'url' => config('filesystems.disks.public.url'),
                    'path' => method_exists($publicDisk, 'path') ? $publicDisk->path('') : null,
                ],
            ],
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function imageDiagnostics(PartImage $image): array
    {
        $presentation = data_get($image->legacy_payload, 'presentation');
        $presentationExists = is_array($presentation);
        $listingPath = $presentationExists ? $this->cleanRelativePath($presentation['listing_path'] ?? null) : null;
        $productPath = $presentationExists ? $this->cleanRelativePath($presentation['product_path'] ?? null) : null;

        return [
            'id' => $image->id,
            'path' => $image->path,
            'is_primary' => (bool) $image->is_primary,
            'sort_order' => $image->sort_order,
            'source_system' => $image->source_system,
            'external_id' => $image->external_id,
            'is_imported_photo' => $image->isImportedPhoto(),
            'listing_url_current' => $image->listingUrl(),
            'product_url_current' => $image->productUrl(),
            'absolute_public_url' => $image->absolutePublicUrl(),
            'legacy_payload_presentation_exists' => $presentationExists,
            'presentation' => [
                'listing_path' => $listingPath,
                'product_path' => $productPath,
                'listing_score' => $presentationExists ? ($presentation['listing_score'] ?? null) : null,
                'presentation_version' => $presentationExists ? ($presentation['presentation_version'] ?? null) : null,
                'forced' => $presentationExists ? ($presentation['forced'] ?? null) : null,
                'warnings' => $presentationExists ? ($presentation['warnings'] ?? null) : null,
                'listing_path_exists' => $this->pathExistenceDiagnostics($listingPath),
                'product_path_exists' => $this->pathExistenceDiagnostics($productPath),
            ],
        ];
    }

    /** @return array<string, array{path: string|null, exists: bool}> */
    private function pathExistenceDiagnostics(?string $path): array
    {
        $publicHtmlPath = $path === null ? null : dirname(base_path()).'/public_html/storage/'.$path;
        $publicPath = $path === null ? null : public_path('storage/'.$path);

        return [
            'storage_disk_public' => [
                'path' => $path,
                'exists' => $path !== null && Storage::disk('public')->exists($path),
            ],
            'public_html_storage' => [
                'path' => $publicHtmlPath,
                'exists' => $publicHtmlPath !== null && is_file($publicHtmlPath),
            ],
            'public_path_storage' => [
                'path' => $publicPath,
                'exists' => $publicPath !== null && is_file($publicPath),
            ],
        ];
    }

    private function cleanRelativePath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return ltrim($path, '/');
    }
}
