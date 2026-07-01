<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class OvokoListingUrlDiagnosticsController extends Controller
{
    public function __invoke(Request $request, PartMarketplaceStatusResolver $resolver): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessPanel(Filament::getPanel('admin')), 403);

        abort_unless(Schema::hasTable('marketplace_listings'), 500, 'Required table marketplace_listings does not exist.');

        $partId = (int) $request->query('part_id', 7897);
        $part = Part::query()->with('marketplaceListings')->find($partId);
        $listing = MarketplaceListing::query()
            ->where('marketplace', 'ovoko')
            ->where('part_id', $partId)
            ->latest('id')
            ->first();

        $resolved = $part ? $resolver->diagnosticsForPartChannel($part, 'ovoko') : [
            'resolved_is_listed' => false,
            'resolved_url' => null,
            'link_visible' => false,
            'link_hidden_reason' => 'part_not_found',
        ];

        return response()->json([
            'part_id' => $partId,
            'channel' => 'ovoko',
            'marketplace_listing_id' => $listing?->id,
            'status' => $listing?->status,
            'sync_status' => $listing?->sync_status,
            'external_offer_id' => $listing?->external_offer_id,
            'external_listing_id' => $listing?->external_listing_id,
            'url' => $listing?->url,
            'metadata' => $listing?->raw_payload,
            'response_payload' => data_get($listing?->raw_payload, 'response_summary'),
        ] + $resolved + [
            'ovoko_write' => false,
            'publish' => false,
        ]);
    }
}
