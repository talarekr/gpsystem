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
        $part = Part::query()->with('images')
            ->when($partId > 0, fn ($query) => $query->whereKey($partId))
            ->when($partId <= 0 && $request->filled('slug'), fn ($query) => $query->where('slug', $request->query('slug')))
            ->when($partId <= 0 && ! $request->filled('slug') && $request->filled('sku'), fn ($query) => $query->where('sku', $request->query('sku')))
            ->first();

        if (! $part) {
            return response()->json([
                'message' => 'Part not found.',
                'part_id' => $partId ?: null,
                'slug' => $request->query('slug'),
                'sku' => $request->query('sku'),
            ], 404, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $primaryImage = $part->primaryImage();
        $listingImage = $part->listingImage();
        $publicDisk = Storage::disk('public');

        $proposedSelection = $part->images
            ->map(fn (PartImage $image): array => ['image' => $image, 'score' => $this->proposedScore($image)])
            ->filter(fn (array $item): bool => $item['score'] !== null)
            ->sort(function (array $a, array $b): int {
                return [$b['score'], (int) $b['image']->is_primary, $a['image']->sort_order, $a['image']->id]
                    <=> [$a['score'], (int) $a['image']->is_primary, $b['image']->sort_order, $b['image']->id];
            })
            ->first();
        $proposedImage = is_array($proposedSelection) ? $proposedSelection['image'] : null;

        $images = $part->images
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (PartImage $image): array => $this->imageDiagnostics($image, $listingImage, $proposedImage));

        return response()->json([
            'part_id' => $part->id,
            'images_count' => $part->images->count(),
            'primary_image_id' => $primaryImage?->id,
            'listing_image_id' => $listingImage?->id,
            'proposed_listing_image_id' => $proposedImage?->id,
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
    private function imageDiagnostics(PartImage $image, ?PartImage $listingImage = null, ?PartImage $proposedImage = null): array
    {
        $presentation = data_get($image->legacy_payload, 'presentation');
        $presentationExists = is_array($presentation);
        $listingPath = $presentationExists ? $this->cleanRelativePath($presentation['listing_path'] ?? null) : null;
        $productPath = $presentationExists ? $this->cleanRelativePath($presentation['product_path'] ?? null) : null;

        return [
            'image_id' => $image->id,
            'path' => $image->path,
            'is_primary' => (bool) $image->is_primary,
            'sort_order' => $image->sort_order,
            'source_system' => $image->source_system,
            'external_id' => $image->external_id,
            'is_imported_photo' => $image->isImportedPhoto(),
            'listing_url' => $image->listingUrl(),
            'currently_would_be_selected' => $listingImage?->is($image) ?? false,
            'proposed_score' => $this->proposedScore($image),
            'proposed_would_be_selected' => $proposedImage?->is($image) ?? false,
            'product_url_current' => $image->productUrl(),
            'absolute_public_url' => $image->absolutePublicUrl(),
            'legacy_payload_presentation_exists' => $presentationExists,
            'presentation' => [
                'listing_path' => $listingPath,
                'product_path' => $productPath,
                'listing_score' => $presentationExists ? ($presentation['listing_score'] ?? null) : null,
                'listing_fill_width_ratio' => $presentationExists ? ($presentation['listing_fill_width_ratio'] ?? data_get($presentation, 'metrics.listing.fill_ratio.width_ratio')) : null,
                'listing_fill_height_ratio' => $presentationExists ? ($presentation['listing_fill_height_ratio'] ?? data_get($presentation, 'metrics.listing.fill_ratio.height_ratio')) : null,
                'listing_dominant_ratio' => $presentationExists ? ($presentation['listing_dominant_ratio'] ?? data_get($presentation, 'metrics.listing.fill_ratio.dominant_ratio')) : null,
                'object_aspect_ratio' => $presentationExists ? ($presentation['object_aspect_ratio'] ?? null) : null,
                'selected_crop_pass' => $presentationExists ? ($presentation['selected_crop_pass'] ?? data_get($presentation, 'selected_crops.listing.pass')) : null,
                'bounding_box' => $presentationExists ? ($presentation['bounding_box'] ?? null) : null,
                'crop_metrics' => $presentationExists ? (data_get($presentation, 'metrics.listing') ?? null) : null,
                'selected_crop' => $presentationExists ? (data_get($presentation, 'selected_crops.listing') ?? null) : null,
                'presentation_version' => $presentationExists ? ($presentation['presentation_version'] ?? null) : null,
                'forced' => $presentationExists ? ($presentation['forced'] ?? null) : null,
                'warnings' => $presentationExists ? ($presentation['warnings'] ?? null) : null,
                'listing_path_exists' => $this->pathExistenceDiagnostics($listingPath),
                'product_path_exists' => $this->pathExistenceDiagnostics($productPath),
            ],
        ];
    }


    private function proposedScore(PartImage $image): ?float
    {
        $presentation = data_get($image->legacy_payload, 'presentation');

        if (! is_array($presentation)) {
            return null;
        }

        return PartImage::calculateProposedListingScore($presentation);
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
