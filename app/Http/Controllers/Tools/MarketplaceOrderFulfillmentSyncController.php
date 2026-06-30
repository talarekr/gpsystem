<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\MarketplaceOrderFulfillmentSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MarketplaceOrderFulfillmentSyncController extends Controller
{
    public function __construct(private readonly ApiIntegrationLogger $logger) {}

    public function __invoke(Request $request, Order $order, MarketplaceOrderFulfillmentSyncService $service): JsonResponse
    {
        abort_unless($request->user()?->canAccessPanel(filament()->getPanel('admin')), 403);

        $apply = $request->boolean('apply');
        if ($apply && ! hash_equals('sync-fulfillment', (string) $request->query('confirm', ''))) {
            return response()->json(['ok' => false, 'dry_run' => true, 'message' => 'Apply requires confirm=sync-fulfillment. No marketplace write was executed.'], 422);
        }

        try {
            $result = $apply ? $service->apply($order) : $service->dryRun($order);
            $status = ($result['ok'] ?? false) ? 200 : ($apply ? 422 : 200);
            return response()->json($result + ['message' => $apply ? 'Single-order fulfillment sync processed.' : 'DRY-RUN only. No marketplace write was executed.'], $status);
        } catch (Throwable $exception) {
            $meta = is_array($order->meta) ? $order->meta : [];
            $order->forceFill(['meta' => array_merge($meta, [
                'marketplace_fulfillment_status' => 'error',
                'marketplace_fulfillment_last_error' => $exception->getMessage(),
            ])])->save();

            $this->logger->error((string) ($order->marketplace ?: 'marketplace'), 'marketplace_fulfillment_sync_tool', $exception, ['order_id' => $order->id]);

            return response()->json(['ok' => false, 'message' => 'Fulfillment sync failed. Details logged in MarketplaceSyncLog / API integration logs.'], 500);
        }
    }
}
