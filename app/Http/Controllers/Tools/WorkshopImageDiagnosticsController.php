<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WorkshopImageDiagnosticsController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(hash_equals(self::TOKEN, (string) $request->query('token')), 403);

        $partId = (int) $request->query('part_id');
        abort_unless($partId > 0, 422, 'part_id is required.');

        $part = Part::query()->with('images')->findOrFail($partId);

        $referenceUrl = (string) $request->query('reference_url', 'https://gpswiss.pl/storage/parts/photos/imported/63924/23201.jpg');

        return response()->json([
            'part_id' => $part->id,
            'request_host' => $request->getHost(),
            'app_url' => config('app.url'),
            'public_disk_url' => config('filesystems.disks.public.url'),
            'reference_url' => $referenceUrl,
            'reference_url_host' => parse_url($referenceUrl, PHP_URL_HOST),
            'admin_table_image_url' => $part->adminTableImageUrl(),
            'primary_image_url' => $part->primaryImageUrl(),
            'listing_image_url' => $part->listingImageUrl(),
            'images_relation_count' => $part->images->count(),
            'images' => $part->images->map(fn (PartImage $image): array => $this->imagePayload($image))->values(),
        ]);
    }

    private function imagePayload(PartImage $image): array
    {
        $path = ltrim((string) $image->path, '/');
        $publicPath = str_starts_with($path, 'storage/') ? substr($path, strlen('storage/')) : $path;
        $storagePublicExists = $publicPath !== '' && Storage::disk('public')->exists($publicPath);
        $storageDefaultExists = $path !== '' && Storage::exists($path);
        $absolutePath = $storagePublicExists ? Storage::disk('public')->path($publicPath) : null;

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
            'listing_url_accessor' => $image->listingUrl(),
            'product_url_accessor' => $image->productUrl(),
            'expected_public_path' => $publicPath,
            'file_size' => $absolutePath && is_file($absolutePath) ? filesize($absolutePath) : null,
            'columns' => $image->getAttributes(),
            'legacy_presentation' => $image->legacy_payload['presentation'] ?? null,
        ];
    }
}
