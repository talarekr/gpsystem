<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CheckCatalogRenderController extends Controller
{
    use BuildsStorefrontQueries;

    public function __invoke(Request $request, CategoryTreeService $categoryTree): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            abort(403);
        }

        try {
            $filterOptions = $this->storefrontFilterOptions(Part::query()->storefrontVisible());
            $parts = $this->storefrontQuery($request)->paginate(60)->withQueryString();
            $categoryRoots = $categoryTree->roots();

            return response()->json([
                'ok' => true,
                'count' => $parts->total(),
                'current_page' => $parts->currentPage(),
                'per_page' => $parts->perPage(),
                'filters' => [
                    'producers_available' => count($filterOptions['producers'] ?? []),
                    'models_available' => count($filterOptions['models'] ?? []),
                    'category_roots_available' => method_exists($categoryRoots, 'count') ? $categoryRoots->count() : count($categoryRoots),
                ],
                'first' => $parts->getCollection()->take(5)->map(fn (Part $part): array => $this->partRenderSnapshot($part))->values()->all(),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'count' => 0,
                'current_page' => null,
                'per_page' => null,
                'first' => [],
                'error' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'trace' => collect($exception->getTrace())->take(8)->map(fn (array $frame): array => [
                        'file' => $frame['file'] ?? null,
                        'line' => $frame['line'] ?? null,
                        'function' => $frame['function'] ?? null,
                        'class' => $frame['class'] ?? null,
                    ])->all(),
                ],
            ], 500);
        }
    }

    private function partRenderSnapshot(Part $part): array
    {
        $image = null;
        $imageUrl = null;
        $productUrl = null;

        try {
            $image = $part->listingImage();
            $imageUrl = $part->listingImageUrl();
        } catch (Throwable $exception) {
            $imageUrl = null;
        }

        try {
            $productUrl = route('storefront.product', $part->slug ?: $part->id);
        } catch (Throwable $exception) {
            $productUrl = null;
        }

        return [
            'id' => $part->id,
            'name' => $part->name,
            'part_number' => $part->part_number,
            'sku' => $part->sku,
            'price' => $part->price,
            'currency' => $part->currency ?: 'PLN',
            'category' => $part->category?->name,
            'vehicle' => [
                'make' => $part->car?->make,
                'model' => $part->car?->model,
            ],
            'has_image_record' => $image !== null,
            'has_image_url' => filled($imageUrl),
            'image_url' => $imageUrl,
            'has_price' => $part->price !== null,
            'has_category' => $part->category !== null,
            'has_product_url' => filled($productUrl),
            'product_url' => $productUrl,
        ];
    }
}
