<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckPartsToListController extends Controller
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

        $partsToList = Part::query()->where('needs_listing', true);
        $gpsGmailParts = Part::query()->whereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', ['%gps-gmail%']);
        $visibleNeedsListing = Part::query()->storefrontVisible()->where('needs_listing', true);

        return response()->json([
            'ok' => true,
            'parts_total' => Part::query()->count(),
            'needs_listing_count' => (clone $partsToList)->count(),
            'gps_gmail_sku_count' => (clone $gpsGmailParts)->count(),
            'gps_gmail_needs_listing_count' => (clone $gpsGmailParts)->where('needs_listing', true)->count(),
            'gps_gmail_not_needs_listing_count' => (clone $gpsGmailParts)->where('needs_listing', false)->count(),
            'needs_listing_visible_in_storefront_count' => (clone $visibleNeedsListing)->count(),
            'storefront_excludes_needs_listing' => (clone $visibleNeedsListing)->doesntExist(),
            'samples_visible_needs_listing' => (clone $visibleNeedsListing)
                ->limit(10)
                ->get(['id', 'name', 'sku', 'needs_listing', 'is_visible_storefront', 'status'])
                ->map(fn (Part $part): array => [
                    'part_id' => $part->id,
                    'sku' => $part->sku,
                    'title' => $part->name,
                    'name' => $part->name,
                    'needs_listing' => (bool) $part->needs_listing,
                    'is_visible_storefront' => (bool) $part->is_visible_storefront,
                    'status' => $part->status,
                ])->values(),
            'samples_gps_gmail' => (clone $gpsGmailParts)
                ->orderBy('id')
                ->limit(10)
                ->get(['id', 'name', 'sku', 'needs_listing'])
                ->map(fn (Part $part): array => [
                    'part_id' => $part->id,
                    'sku' => $part->sku,
                    'title' => $part->name,
                    'name' => $part->name,
                    'needs_listing' => (bool) $part->needs_listing,
                ])->values(),
            'missing_price_count' => (clone $partsToList)->where(fn ($query) => $query->whereNull('price')->orWhere('price', '<=', 0))->count(),
            'missing_images_count' => (clone $partsToList)->doesntHave('images')->count(),
            'missing_part_number_count' => (clone $partsToList)->where(fn ($query) => $query->whereNull('part_number')->orWhere('part_number', ''))->count(),
            'missing_sku_count' => (clone $partsToList)->where(fn ($query) => $query->whereNull('sku')->orWhere('sku', ''))->count(),
            'samples' => (clone $partsToList)
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'name', 'sku', 'part_number', 'price', 'quantity', 'status', 'created_at'])
                ->map(fn (Part $part): array => [
                    'id' => $part->id,
                    'name' => $part->name,
                    'sku' => $part->sku,
                    'part_number' => $part->part_number,
                    'price' => $part->price,
                    'quantity' => $part->quantity,
                    'status' => $part->status,
                    'created_at' => $part->created_at?->toISOString(),
                    'edit_url' => url('/admin/parts/'.$part->id.'/edit'),
                ])
                ->values(),
            'admin_url' => url('/admin/parts/to-list'),
            'note' => 'Default needs_listing=false; endpoint nie wykonuje backfillu istniejących części.',
        ]);
    }
}
