<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\OvokoListingUrlBackfillService;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvokoListingUrlBackfillController extends Controller
{
    private const CONFIRMATION = 'ovoko-url-backfill';

    public function __invoke(Request $request, OvokoListingUrlBackfillService $backfill, ApiIntegrationLogger $logger): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessPanel(Filament::getPanel('admin')), 403);

        $apply = $request->boolean('apply') && $request->query('confirm') === self::CONFIRMATION;
        $limit = max(1, min(1000, (int) $request->query('limit', 100)));

        if (! $request->filled('listing_id') && ! $request->filled('part_id')) {
            $result = $backfill->runLocalGeneratedBulk(
                apply: $apply,
                limit: $limit,
                onlyMissing: $request->boolean('only_missing'),
                includeExistingInvalid: $request->boolean('include_existing_invalid'),
            );
        } else {
            $result = $backfill->run(
                apply: $apply,
                force: $request->boolean('force'),
                partId: $request->filled('part_id') ? (int) $request->query('part_id') : null,
                limit: $limit,
                listingId: $request->filled('listing_id') ? (int) $request->query('listing_id') : null,
                maxPages: max(1, min(50, (int) $request->query('max_pages', 3))),
            );
        }

        if (! $apply && ($request->filled('listing_id') || $request->filled('part_id'))) {
            $logger->success('ovoko', 'ovoko_listing_url_diagnostic', 'Ovoko listing URL diagnostic dry-run completed.', [
                'marketplace_listing_id' => $request->filled('listing_id') ? (int) $request->query('listing_id') : null,
                'part_id' => $request->filled('part_id') ? (int) $request->query('part_id') : null,
                'request' => $request->only(['listing_id', 'part_id', 'limit', 'force', 'max_pages']),
                'response' => ['summary' => $result['summary'], 'results' => $result['results']],
            ]);
        }

        return response()->json([
            'ok' => true,
            'mode' => $result['mode'],
            'dry_run' => ! $apply,
            'apply_requested' => $request->boolean('apply'),
            'apply_confirmed' => $apply,
            'force' => $request->boolean('force'),
            'only_missing' => $request->boolean('only_missing'),
            'include_existing_invalid' => $request->boolean('include_existing_invalid'),
            'local_update_only' => $apply,
            'ovoko_write' => false,
            'crm_import_part' => false,
            'publish' => false,
            'stock_order_price_sync' => false,
            'summary' => $result['summary'],
            'results' => $result['results'],
            'warnings' => $result['warnings'],
        ]);
    }
}
