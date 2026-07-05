<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Support\Arr;

class OrderStatusMarketplaceSyncService
{
    public const ACTION = 'order_status_sync';

    public function sync(Order $order, ?string $previousStatus = null, ?int $retryOfLogId = null): MarketplaceSyncLog
    {
        $marketplace = $this->normalizeMarketplace((string) $order->marketplace);
        $plan = $this->plan($order, $previousStatus, $retryOfLogId);

        if (! in_array($marketplace, ['allegro', 'ebay', 'ovoko'], true)) {
            return $this->log($order, $marketplace ?: 'local', $plan, 'skipped', 'local_or_unsupported_marketplace');
        }

        if (! ($plan['supported'] ?? false)) {
            return $this->log($order, $marketplace, $plan, 'skipped', (string) ($plan['skipped_reason'] ?? 'unsupported_status_for_marketplace'));
        }

        if ($this->alreadySynced($order, $marketplace, $plan)) {
            return $this->log($order, $marketplace, $plan, 'skipped', 'already_synced');
        }

        $result = $this->dispatch($order, $marketplace, $plan);

        return $this->log($order, $marketplace, $plan + ['result' => $result], ($result['ok'] ?? false) ? 'success' : 'error', null, $result);
    }

    public function plan(Order $order, ?string $previousStatus = null, ?int $retryOfLogId = null): array
    {
        $marketplace = $this->normalizeMarketplace((string) $order->marketplace);
        $status = (string) $order->status;
        $base = [
            'previous_local_status' => $previousStatus,
            'new_local_status' => $status,
            'marketplace' => $marketplace,
            'marketplace_order_id' => $order->marketplace_order_id,
            'retry_of_log_id' => $retryOfLogId,
            'supported' => false,
            'dry_run' => true,
        ];

        if ($marketplace === 'allegro') {
            $map = [
                'new' => 'NEW',
                'processing' => 'PROCESSING',
                'ready_to_ship' => 'READY_FOR_SHIPMENT',
                'ready_for_pickup' => 'READY_FOR_PICKUP',
                'shipped' => 'SENT',
                'picked_up' => 'PICKED_UP',
            ];
            $target = $map[$status] ?? null;
            return $base + [
                'target_marketplace_status' => $target,
                'action' => self::ACTION,
                'supported' => $target !== null,
                'supported_marketplace_statuses' => array_values($map),
                'skipped_reason' => $target ? null : 'unsupported_allegro_status',
            ];
        }

        if ($marketplace === 'ebay') {
            return $base + ['target_marketplace_status' => $status === 'shipped' ? 'shipping_fulfillment' : null, 'action' => 'ebay_create_shipping_fulfillment', 'supported' => $status === 'shipped', 'skipped_reason' => $status === 'shipped' ? null : 'unsupported_status_for_marketplace'];
        }

        if ($marketplace === 'ovoko') {
            return $base + ['target_marketplace_status' => null, 'action' => self::ACTION, 'supported' => false, 'skipped_reason' => 'ovoko_order_status_endpoint_not_confirmed_in_rrr_docs'];
        }

        return $base + ['target_marketplace_status' => null, 'action' => self::ACTION, 'skipped_reason' => 'local_or_unsupported_marketplace'];
    }

    private function dispatch(Order $order, string $marketplace, array $plan): array
    {
        $account = MarketplaceAccount::query()->where('marketplace', $marketplace === 'ebay' ? 'like' : '=', $marketplace === 'ebay' ? 'ebay%' : $marketplace)->first();

        if ($marketplace === 'allegro') {
            return (new AllegroApiClient('allegro_main', $account))->updateOrderFulfillmentStatus((string) $order->marketplace_order_id, (string) $plan['target_marketplace_status'], data_get($order->raw_payload, 'checkoutForm.revision') ?? data_get($order->raw_payload, 'revision'));
        }

        if ($marketplace === 'ebay') {
            return (new EbayApiClient((string) ($account?->marketplace ?: 'ebay'), $account))->createShippingFulfillment($order);
        }

        return ['ok' => false, 'message' => 'Unsupported marketplace order status dispatch.'];
    }

    private function log(Order $order, string $marketplace, array $plan, string $status, ?string $skippedReason = null, array $result = []): MarketplaceSyncLog
    {
        return MarketplaceSyncLog::query()->create([
            'marketplace' => $marketplace,
            'order_id' => $order->id,
            'action' => (string) ($plan['action'] ?? self::ACTION),
            'status' => $status,
            'http_status' => $result['http_status'] ?? null,
            'message' => $skippedReason ?: ($result['message'] ?? null),
            'external_id' => $order->marketplace_order_id,
            'tracking_number' => $order->shipments()->latest('id')->value('tracking_number'),
            'payload' => [
                'order_status_sync' => true,
                'marketplace_order_id' => $order->marketplace_order_id,
                'previous_local_status' => $plan['previous_local_status'] ?? null,
                'new_local_status' => $plan['new_local_status'] ?? $order->status,
                'target_marketplace_status' => $plan['target_marketplace_status'] ?? null,
                'request_summary' => $result['request_summary'] ?? $this->skipRequestSummary($order, $marketplace, $plan, $skippedReason),
                'response_summary' => $result['response_summary'] ?? $this->skipResponseSummary($plan, $skippedReason),
                'skipped_reason' => $skippedReason,
                'retry_of_log_id' => $plan['retry_of_log_id'] ?? null,
            ],
            'created_at' => now(),
        ]);
    }

    private function skipRequestSummary(Order $order, string $marketplace, array $plan, ?string $skippedReason): array
    {
        return [
            'method' => $marketplace === 'allegro' ? 'PUT' : null,
            'endpoint' => $marketplace === 'allegro' ? 'PUT /order/checkout-forms/{checkoutFormId}/fulfillment' : null,
            'marketplace' => $marketplace,
            'checkout_form_id' => $marketplace === 'allegro' ? $order->marketplace_order_id : null,
            'local_status' => $plan['new_local_status'] ?? $order->status,
            'previous_local_status' => $plan['previous_local_status'] ?? null,
            'target_marketplace_status' => $plan['target_marketplace_status'] ?? null,
            'supported_marketplace_statuses' => $plan['supported_marketplace_statuses'] ?? null,
            'skipped_reason' => $skippedReason,
        ];
    }

    private function skipResponseSummary(array $plan, ?string $skippedReason): array
    {
        return [
            'response' => null,
            'error' => null,
            'mapping_supported' => (bool) ($plan['supported'] ?? false),
            'target_marketplace_status' => $plan['target_marketplace_status'] ?? null,
            'skipped_reason' => $skippedReason,
        ];
    }

    private function alreadySynced(Order $order, string $marketplace, array $plan): bool
    {
        return MarketplaceSyncLog::query()
            ->where('order_id', $order->id)
            ->where('marketplace', $marketplace)
            ->where('status', 'success')
            ->where('action', (string) ($plan['action'] ?? self::ACTION))
            ->where('payload->new_local_status', (string) $order->status)
            ->where('payload->target_marketplace_status', $plan['target_marketplace_status'])
            ->exists();
    }

    private function normalizeMarketplace(string $marketplace): string
    {
        $marketplace = strtolower(trim($marketplace));
        return str_starts_with($marketplace, 'ebay') ? 'ebay' : $marketplace;
    }
}
