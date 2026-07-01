<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualLinkMappingDiagnosticsController extends Controller
{
    public function __invoke(Request $request, PartMarketplaceStatusResolver $resolver): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        $part = Part::query()->with('marketplaceListings')->find($partId);

        if (! $part) {
            return response()->json(['error' => 'part_not_found', 'part_id' => $partId], 404);
        }

        $listings = $part->marketplaceListings
            ->filter(fn (MarketplaceListing $listing): bool => in_array($listing->marketplace, ['allegro', 'allegro_main', 'ovoko'], true))
            ->values();

        return response()->json([
            'part_id' => $part->id,
            'marketplace_write' => false,
            'sync_triggered' => false,
            'marketplace_listings' => $listings->map(fn (MarketplaceListing $listing): array => $this->listingDiagnostics($part, $listing, $resolver))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listingDiagnostics(Part $part, MarketplaceListing $listing, PartMarketplaceStatusResolver $resolver): array
    {
        $channel = $listing->marketplace === 'allegro_main' ? 'allegro' : $listing->marketplace;
        $resolverDiagnostics = $resolver->diagnosticsForPartChannel($part, $channel);
        $ready = $this->stockSyncMappingReady($listing, $channel);
        $rawPayload = is_array($listing->raw_payload) ? $listing->raw_payload : [];

        return [
            'id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'channel' => $channel,
            'status' => $listing->status,
            'sync_status' => $listing->sync_status,
            'external_offer_id' => $listing->external_offer_id,
            'external_listing_id' => $listing->external_listing_id,
            'url' => $listing->url,
            'metadata' => [
                'ovoko_part_id' => data_get($rawPayload, 'ovoko_part_id'),
            ],
            'resolver' => $resolverDiagnostics,
            'resolved_is_listed' => $resolverDiagnostics['resolved_is_listed'],
            'resolved_url' => $resolverDiagnostics['resolved_url'],
            'link_visible' => $resolverDiagnostics['link_visible'],
            'stock_sync_mapping_ready' => $ready['ready'],
            'reason' => $ready['reason'],
        ];
    }

    /**
     * @return array{ready: bool, reason: ?string}
     */
    private function stockSyncMappingReady(MarketplaceListing $listing, string $channel): array
    {
        if (! $listing->part_id) {
            return ['ready' => false, 'reason' => 'missing_part_id'];
        }

        if ($channel === 'allegro') {
            return filled($listing->external_offer_id) || filled($listing->external_listing_id)
                ? ['ready' => true, 'reason' => null]
                : ['ready' => false, 'reason' => 'missing_allegro_offer_id'];
        }

        if ($channel === 'ovoko') {
            $rawPayload = is_array($listing->raw_payload) ? $listing->raw_payload : [];

            return filled($listing->external_listing_id) || filled(data_get($rawPayload, 'ovoko_part_id'))
                ? ['ready' => true, 'reason' => null]
                : ['ready' => false, 'reason' => 'missing_ovoko_listing_id'];
        }

        return ['ready' => false, 'reason' => 'unsupported_marketplace'];
    }
}
