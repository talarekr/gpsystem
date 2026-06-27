<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Services\Marketplace\AllegroOfferParametersBuilder;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AllegroListingPreviewController extends Controller
{
    public function __invoke(Request $request, MarketplaceListingReadinessService $readinessService, AllegroOfferParametersBuilder $parametersBuilder)
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) abort(403);
        $part = Part::query()->with(['images', 'category', 'car'])->find((int) $request->query('part_id'));
        if (! $part) abort(404);

        $readiness = $readinessService->checkPartReadiness($part, 'allegro_main');
        $mapping = $this->categoryMapping($part);
        $parameters = $parametersBuilder->build($part, $mapping);
        $description = (string) (($part->description ?: $part->short_description) ?? '');
        $preview = [
            'title' => $part->name,
            'sku' => $part->sku,
            'part_number' => $part->part_number,
            'price_pln' => is_numeric($part->allegro_price ?? null) ? (float) $part->allegro_price : (is_numeric($part->price ?? null) ? (float) $part->price : null),
            'quantity' => (int) $part->quantity,
            'condition' => 'Używany',
            'category_id' => $mapping?->external_category_id,
            'local_category' => $part->category?->category_path ?? $part->category?->name,
            'category_mapping_source' => $mapping?->source,
            'image_urls' => $this->imageUrls($part),
            'description' => $description,
            'description_sections' => [['type' => 'TEXT', 'content' => strip_tags($description)]],
            'allegro_parameters' => $parameters,
            'will_make_marketplace_request' => false,
            'marketplace_listings_created' => false,
        ];

        return response()->view('admin.marketplace.allegro-listing-preview', compact('part', 'readiness', 'preview'));
    }

    private function categoryMapping(Part $part): ?MarketplaceCategoryMapping
    {
        if (! Schema::hasTable('marketplace_category_mappings') || ! $part->category_id) return null;
        return MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->whereIn('channel', ['allegro_main', 'allegro'])->orderByRaw("case when channel = 'allegro_main' then 0 else 1 end")->first();
    }

    private function imageUrls(Part $part): array
    {
        return $part->images->sortBy([['sort_order', 'asc'], ['id', 'asc']])->pluck('path')->filter()->map(fn ($path) => str_starts_with((string) $path, 'http') ? (string) $path : url('/storage/'.ltrim((string) $path, '/')))->values()->all();
    }
}
