<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceListingUrlBackfillService;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceListingUrlBackfillController extends Controller
{
    private const CONFIRMATION = 'marketplace-url-backfill';
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 6500;

    public function __invoke(Request $request, MarketplaceListingUrlBackfillService $backfill): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessPanel(Filament::getPanel('admin')), 403);

        $apply = $request->boolean('apply') && $request->query('confirm') === self::CONFIRMATION;
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query('limit', self::DEFAULT_LIMIT)));
        $offset = max(0, (int) $request->query('offset', 0));

        $result = $backfill->run(
            marketplace: (string) $request->query('marketplace', ''),
            channel: $request->filled('channel') ? (string) $request->query('channel') : null,
            apply: $apply,
            limit: $limit,
            offset: $offset,
            onlyMissing: $request->boolean('only_missing'),
            includeExistingInvalid: $request->boolean('include_existing_invalid'),
            listingId: $request->filled('listing_id') ? (int) $request->query('listing_id') : null,
            partId: $request->filled('part_id') ? (int) $request->query('part_id') : null,
        );

        return response()->json([
            'ok' => true, 'mode' => $result['mode'], 'dry_run' => ! $apply, 'apply_requested' => $request->boolean('apply'),
            'apply_confirmed' => $apply, 'only_missing' => $request->boolean('only_missing'), 'include_existing_invalid' => $request->boolean('include_existing_invalid'),
            'local_update_only' => $apply, 'marketplace_write' => false, 'publish' => false, 'stock_order_price_sync' => false,
            'summary' => $result['summary'], 'results' => $result['results'], 'warnings' => $result['warnings'],
        ]);
    }
}
