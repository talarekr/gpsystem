<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckStorefrontVisibilityController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'error_message' => 'Invalid diagnostics token.',
            ], 403);
        }

        $storefrontVisible = Part::query()->storefrontVisible();
        $baseEligible = fn (): Builder => Part::query()
            ->where('needs_listing', false)
            ->notSold()
            ->inStock();

        return response()->json([
            'ok' => true,
            'parts_total' => Part::query()->count(),
            'needs_listing_true_count' => Part::query()->where('needs_listing', true)->count(),
            'needs_listing_false_count' => Part::query()->where('needs_listing', false)->count(),
            'status_counts' => $this->statusCounts(),
            'is_visible_storefront_counts' => $this->visibilityCounts(),
            'quantity_positive_count' => Part::query()->where('quantity', '>', 0)->count(),
            'quantity_zero_count' => Part::query()->where(fn (Builder $query) => $query->whereNull('quantity')->orWhere('quantity', '<=', 0))->count(),
            'storefront_visible_count' => (clone $storefrontVisible)->count(),
            'needs_listing_visible_in_storefront_count' => (clone $storefrontVisible)->where('needs_listing', true)->count(),
            'storefront_hidden_by_needs_listing_count' => (clone $this->otherwiseStorefrontVisible())->where('needs_listing', true)->count(),
            'storefront_hidden_by_status_count' => Part::query()
                ->where('needs_listing', false)
                ->whereIn('status', ['sold', 'archived'])
                ->inStock()
                ->count(),
            'storefront_hidden_by_visibility_flag_count' => 0,
            'storefront_hidden_by_quantity_count' => Part::query()
                ->where('needs_listing', false)
                ->notSold()
                ->where(fn (Builder $query) => $query->whereNull('quantity')->orWhere('quantity', '<=', 0))
                ->count(),
            'visibility_flag_false_or_null_otherwise_visible_count' => $baseEligible()
                ->where(fn (Builder $query) => $query->whereNull('is_visible_storefront')->orWhere('is_visible_storefront', false))
                ->count(),
            'sample_visible_parts' => $this->sample((clone $storefrontVisible)->orderBy('id')),
            'sample_hidden_by_visibility_flag' => $this->sample($baseEligible()
                ->where(fn (Builder $query) => $query->whereNull('is_visible_storefront')->orWhere('is_visible_storefront', false))
                ->orderBy('id')),
            'sample_hidden_by_status' => $this->sample(Part::query()
                ->where('needs_listing', false)
                ->whereIn('status', ['sold', 'archived'])
                ->inStock()
                ->orderBy('id')),
            'sample_hidden_by_quantity' => $this->sample(Part::query()
                ->where('needs_listing', false)
                ->notSold()
                ->where(fn (Builder $query) => $query->whereNull('quantity')->orWhere('quantity', '<=', 0))
                ->orderBy('id')),
        ]);
    }

    private function otherwiseStorefrontVisible(): Builder
    {
        return Part::query()
            ->notSold()
            ->inStock();
    }

    /** @return array<string, int> */
    private function statusCounts(): array
    {
        return Part::query()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('aggregate', 'status')
            ->mapWithKeys(fn (int $count, ?string $status): array => [$status ?? 'NULL' => $count])
            ->all();
    }

    /** @return array<string, int> */
    private function visibilityCounts(): array
    {
        return [
            'true' => Part::query()->where('is_visible_storefront', true)->count(),
            'false' => Part::query()->where('is_visible_storefront', false)->count(),
            'null' => Part::query()->whereNull('is_visible_storefront')->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function sample(Builder $query): array
    {
        return $query->limit(10)->get(['id', 'name', 'sku', 'part_number', 'status', 'quantity', 'needs_listing', 'is_visible_storefront'])
            ->map(fn (Part $part): array => [
                'id' => $part->id,
                'name' => $part->name,
                'sku' => $part->sku,
                'part_number' => $part->part_number,
                'status' => $part->status,
                'quantity' => $part->quantity,
                'needs_listing' => (bool) $part->needs_listing,
                'is_visible_storefront' => $part->is_visible_storefront,
                'url' => $part->slug ? route('storefront.product', $part->slug) : null,
            ])->values()->all();
    }
}
