<?php

namespace App\Http\Controllers\Tools;

use App\Models\Part;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CheckProductImageController extends Controller
{
    public function __invoke(Request $request)
    {
        $configuredToken = (string) env('PRODUCT_IMAGES_IMPORT_TOKEN', '');
        $requestToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $requestToken === '' || ! hash_equals($configuredToken, $requestToken)) {
            abort(403);
        }

        $part = Part::query()->with('images')->findOrFail((int) $request->query('part_id'));
        $publicStoragePath = public_path('storage');
        $storagePublicPath = storage_path('app/public');

        $images = $part->images
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values()
            ->map(function ($image) {
                $path = ltrim((string) $image->path, '/');
                $storageAppPublicPath = $path === '' ? null : storage_path('app/public/'.$path);
                $publicStoragePhysicalPath = $path === '' ? null : public_path('storage/'.$path);

                return [
                    'id' => $image->id,
                    'path' => $image->path,
                    'sort_order' => $image->sort_order,
                    'is_primary' => (bool) $image->is_primary,
                    'storage_app_public_exists' => $storageAppPublicPath !== null && is_file($storageAppPublicPath),
                    'storage_app_public_path' => $storageAppPublicPath,
                    'storage_app_public_perms' => $this->permissions($storageAppPublicPath),
                    'public_storage_physical_exists' => $publicStoragePhysicalPath !== null && is_file($publicStoragePhysicalPath),
                    'public_storage_physical_path' => $publicStoragePhysicalPath,
                    'public_storage_physical_perms' => $this->permissions($publicStoragePhysicalPath),
                    'public_url' => $image->relativePublicUrl(),
                    'absolute_public_url' => $image->absolutePublicUrl(),
                    'expected_url' => $path === '' ? null : '/storage/'.$path,
                    'expected_absolute_url' => $path === '' ? null : url('/storage/'.$path),
                ];
            });

        return response()->json([
            'part_id' => $part->id,
            'primary_image_path' => $part->primary_image_path,
            'primary_image_url' => $part->primary_image_relative_url,
            'absolute_primary_image_url' => $part->primary_image_url,
            'images_count' => $images->count(),
            'images' => $images,
            'public_storage' => [
                'path' => $publicStoragePath,
                'exists' => file_exists($publicStoragePath),
                'is_symlink' => is_link($publicStoragePath),
                'target' => is_link($publicStoragePath) ? readlink($publicStoragePath) : null,
                'expected_target' => $storagePublicPath,
                'points_to_expected_target' => is_link($publicStoragePath) && realpath($publicStoragePath) === realpath($storagePublicPath),
            ],
            'url_comparison' => [
                'imported_url' => $part->primary_image_url,
                'presentation_example_url' => url('/storage/parts/photos/presentation/product/12-1a9a282e0dfe.jpg'),
                'img_src_should_use' => $part->primary_image_url,
            ],
            'url_rule' => 'Imported paths are stored as parts/photos/imported/{woo_product_id}/filename.jpg and should be served as absolute /storage URL in rendered <img src> attributes.',
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function permissions(?string $path): ?string
    {
        if ($path === null || ! file_exists($path)) {
            return null;
        }

        return substr(sprintf('%o', fileperms($path)), -4);
    }
}
