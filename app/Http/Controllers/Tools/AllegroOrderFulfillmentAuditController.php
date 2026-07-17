<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\OrderStatusOptions;
use App\Services\Marketplace\OrderStatusMarketplaceSyncService;
use Illuminate\Http\JsonResponse;

class AllegroOrderFulfillmentAuditController extends Controller
{
    public function __invoke(int $localOrderId, OrderStatusMarketplaceSyncService $syncService): JsonResponse
    {
        $order = Order::query()->findOrFail($localOrderId);
        $plan = $syncService->plan($order);
        $providerId = data_get($order->raw_payload, 'fulfillment.provider.id');
        $statusUpdateAllowed = strtolower((string) $order->marketplace) === 'allegro' && $providerId !== 'ALLEGRO';

        return response()->json([
            'local_order_id' => $order->id,
            'local_status' => $order->status,
            'local_mapped_allegro_status' => $plan['target_marketplace_status'] ?? null,
            'allegro_fulfillment_status' => data_get($order->raw_payload, 'fulfillment.status', $order->marketplace_status),
            'fulfillment_provider_id' => $providerId,
            'status_update_allowed' => $statusUpdateAllowed,
            'status_update_block_reason' => $providerId === 'ALLEGRO' ? 'Status tego zamówienia jest zarządzany przez One Fulfillment by Allegro.' : null,
            'available_panel_actions' => array_values($plan['supported_marketplace_statuses'] ?? ['NEW', 'PROCESSING', 'READY_FOR_SHIPMENT', 'READY_FOR_PICKUP', 'SENT', 'PICKED_UP', 'CANCELLED', 'SUSPENDED']),
            'panel_options' => OrderStatusOptions::optionsForOrder($order),
        ]);
    }
}
