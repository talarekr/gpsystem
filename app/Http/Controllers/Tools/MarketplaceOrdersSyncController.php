<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\MarketplaceOrdersImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class MarketplaceOrdersSyncController extends Controller
{
    public function __construct(private readonly ApiIntegrationLogger $logger) {}

    public function __invoke(Request $request, MarketplaceOrdersImportService $service): JsonResponse
    {
        $apply = $request->boolean('apply');
        if ($apply && ! hash_equals('sync-orders', (string) $request->query('confirm', ''))) {
            return response()->json([
                'ok' => false,
                'dry_run' => true,
                'message' => 'Apply requires confirm=sync-orders. No data changed.',
            ], 422);
        }

        $since = (string) $request->query('since', '2026-06-29 00:00:00');
        try {
            Carbon::parse($since, 'Europe/Warsaw');
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'dry_run' => true,
                'message' => 'Invalid since timestamp. Use e.g. 2026-06-29 00:00:00.',
            ], 422);
        }

        try {
            $summary = $service->run([
                'channels' => (string) $request->query('channels', 'allegro,ebay_de,ebay_fr'),
                'since' => $since,
                'limit' => (int) $request->query('limit', 50),
                'dry_run' => ! $apply,
                'live_import' => true,
            ]);

            return response()->json([
                'ok' => ($summary['errors'] ?? []) === [],
                'message' => ($summary['errors'] ?? []) === []
                    ? ($apply ? 'Orders sync completed.' : 'DRY-RUN only. No local orders changed.')
                    : 'Technical errors were logged in MarketplaceSyncLog / API integration logs.',
                'summary' => $summary,
            ], ($summary['errors'] ?? []) === [] ? 200 : 500);
        } catch (Throwable $exception) {
            $this->logger->error('marketplace', 'marketplace_orders_sync_tool', $exception, ['request' => $request->only(['channels', 'since', 'limit', 'apply'])]);

            return response()->json([
                'ok' => false,
                'message' => 'Technical error. Details logged in MarketplaceSyncLog / API integration logs.',
            ], 500);
        }
    }
}
