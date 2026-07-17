<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\Api\EbayApiClient;

class OrderStatusMarketplaceSyncService
{
    public const ACTION = 'order_status_sync';
    public const CODE_VERSION = '137-ebay-ovoko-status-audit';
    public const SYNC_WRITER = self::class.'::log';

    public function sync(Order $order, ?string $previousStatus = null, ?int $retryOfLogId = null, ?string $desiredStatus = null): MarketplaceSyncLog
    {
        $order = $order->refresh();
        if ($desiredStatus !== null) {
            $order->status = $desiredStatus;
        }
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
        $marketplaceRaw = (string) $order->marketplace;
        $marketplace = $this->normalizeMarketplace($marketplaceRaw);
        $status = (string) $order->status;
        $base = [
            'previous_local_status' => $previousStatus,
            'new_local_status' => $status,
            'marketplace_raw_value' => $marketplaceRaw,
            'normalized_marketplace' => $marketplace,
            'marketplace' => $marketplace,
            'marketplace_order_id' => $order->marketplace_order_id,
            'retry_of_log_id' => $retryOfLogId,
            'dry_run' => true,
            'order_status_sync_code_version' => self::CODE_VERSION,
            'code_version' => self::CODE_VERSION,
            'local_status_raw_value' => $status,
            'local_status_ui_label' => \App\Services\Admin\OrderStatusOptions::optionsForOrder($order)[$status] ?? null,
            'mapper_class' => self::class,
            'mapper_method' => 'plan',
            'sync_writer' => self::SYNC_WRITER,
        ];

        if ($marketplace === 'allegro') {
            $map = [
                'new' => 'NEW',
                'processing' => 'PROCESSING',
                'ready_to_ship' => 'READY_FOR_SHIPMENT',
                'ready_for_pickup' => 'READY_FOR_PICKUP',
                'shipped' => 'SENT',
                'picked_up' => 'PICKED_UP',
                'cancelled' => 'CANCELLED',
                'on_hold' => 'SUSPENDED',
            ];
            $target = $map[$status] ?? null;
            return $this->finalizePlan(array_merge($base, [
                'target_marketplace_status' => $target,
                'mapper_branch' => 'allegro_fulfillment_status',
                'action' => self::ACTION,
                'supported' => $target !== null,
                'available_map' => $map,
                'supported_marketplace_statuses' => array_values($map),
                'skipped_reason' => $target ? null : 'unsupported_allegro_status',
            ]));
        }

        if ($marketplace === 'ebay') {
            return $this->finalizePlan(array_merge($base, [
                'target_marketplace_status' => $status === 'shipped' ? 'shipping_fulfillment' : null,
                'target_marketplace_action' => $status === 'shipped' ? 'createShippingFulfillment' : null,
                'mapper_branch' => 'ebay_shipping_fulfillment',
                'action' => 'ebay_create_shipping_fulfillment',
                'supported' => $status === 'shipped',
                'supported_local_statuses' => ['shipped'],
                'skipped_reason' => $status === 'shipped' ? null : 'unsupported_ebay_status',
            ]));
        }

        if ($marketplace === 'ovoko') {
            return $this->finalizePlan(array_merge($base, ['target_marketplace_status' => null, 'mapper_branch' => 'ovoko_no_status_endpoint', 'action' => self::ACTION, 'supported' => false, 'skipped_reason' => 'ovoko_order_status_endpoint_not_confirmed_in_rrr_docs']));
        }

        return $this->finalizePlan(array_merge($base, ['target_marketplace_status' => null, 'mapper_branch' => 'unsupported_marketplace', 'action' => self::ACTION, 'supported' => false, 'skipped_reason' => 'local_or_unsupported_marketplace']));
    }


    private function finalizePlan(array $plan): array
    {
        $marketplaceSupportsStatusSync = in_array((string) ($plan['normalized_marketplace'] ?? $plan['marketplace'] ?? ''), ['allegro', 'ebay'], true);

        if ($marketplaceSupportsStatusSync && ($plan['target_marketplace_status'] ?? null) !== null) {
            $plan['supported'] = true;
            $plan['skipped_reason'] = null;
        } else {
            $plan['supported'] = (bool) ($plan['supported'] ?? false);
        }

        if (($plan['target_marketplace_status'] ?? null) !== null && ($plan['skipped_reason'] ?? null) === 'unsupported_status_for_marketplace') {
            $plan['skipped_reason'] = null;
        }

        return $plan;
    }

    private function dispatch(Order $order, string $marketplace, array $plan): array
    {
        $account = $this->accountFor($order, $marketplace);

        if ($marketplace === 'allegro') {
            if (data_get($order->raw_payload, 'fulfillment.provider.id') === 'ALLEGRO') {
                return ['ok' => false, 'message' => 'Status tego zamówienia jest zarządzany przez One Fulfillment by Allegro.', 'request_summary' => ['method' => null, 'endpoint' => null, 'one_fulfillment_blocked' => true], 'response_summary' => ['one_fulfillment_blocked' => true]];
            }

            return (new AllegroApiClient('allegro_main', $account))->updateOrderFulfillmentStatus((string) $order->marketplace_order_id, (string) $plan['target_marketplace_status'], data_get($order->raw_payload, 'checkoutForm.revision') ?? data_get($order->raw_payload, 'revision'));
        }

        if ($marketplace === 'ebay') {
            return (new EbayApiClient((string) ($account?->code ?: $order->marketplace ?: 'ebay_de'), $account))->createShippingFulfillment($order);
        }

        return ['ok' => false, 'message' => 'Unsupported marketplace order status dispatch.'];
    }

    private function accountFor(Order $order, string $marketplace): ?MarketplaceAccount
    {
        if ($marketplace === 'ebay') {
            $orderMarketplace = strtolower(trim((string) $order->marketplace));

            return MarketplaceAccount::query()
                ->where(function ($query) use ($orderMarketplace) {
                    $query->where('code', $orderMarketplace)
                        ->orWhere('marketplace', $orderMarketplace);
                })
                ->first()
                ?: MarketplaceAccount::query()->where('code', 'ebay_de')->first()
                ?: MarketplaceAccount::query()->where('marketplace', 'like', 'ebay%')->orderBy('id')->first();
        }

        return MarketplaceAccount::query()->where('marketplace', $marketplace)->first();
    }

    private function log(Order $order, string $marketplace, array $plan, string $status, ?string $skippedReason = null, array $result = []): MarketplaceSyncLog
    {
        $debugContext = $this->debugContext($order, $marketplace, $plan);
        $requestSummary = array_merge($debugContext, $result['request_summary'] ?? $this->skipRequestSummary($order, $marketplace, $plan, $skippedReason));
        $responseSummary = array_merge($debugContext, $result['response_summary'] ?? $this->skipResponseSummary($plan, $skippedReason));

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
                'order_status_sync_code_version' => self::CODE_VERSION,
                'code_version' => self::CODE_VERSION,
                'sync_writer' => self::SYNC_WRITER,
                'marketplace' => $marketplace,
                'marketplace_raw_value' => $plan['marketplace_raw_value'] ?? $order->marketplace,
                'normalized_marketplace' => $plan['normalized_marketplace'] ?? $marketplace,
                'marketplace_order_id' => $order->marketplace_order_id,
                'previous_local_status' => $plan['previous_local_status'] ?? null,
                'new_local_status' => $plan['new_local_status'] ?? $order->status,
                'local_status_raw_value' => $plan['local_status_raw_value'] ?? $order->status,
                'local_status_ui_label' => $plan['local_status_ui_label'] ?? null,
                'target_marketplace_status' => $plan['target_marketplace_status'] ?? null,
                'target_marketplace_action' => $plan['target_marketplace_action'] ?? null,
                'mapping_supported' => (bool) ($plan['supported'] ?? false),
                'mapper_branch' => $plan['mapper_branch'] ?? null,
                'mapper_class' => $plan['mapper_class'] ?? self::class,
                'mapper_method' => $plan['mapper_method'] ?? 'plan',
                'available_map' => $plan['available_map'] ?? null,
                'request_summary' => $requestSummary,
                'response_summary' => $responseSummary,
                'skipped_reason' => $skippedReason,
                'retry_of_log_id' => $plan['retry_of_log_id'] ?? null,
            ],
            'created_at' => now(),
        ]);
    }


    private function debugContext(Order $order, string $marketplace, array $plan): array
    {
        return [
            'local_status_raw_value' => $plan['local_status_raw_value'] ?? $order->status,
            'marketplace_raw_value' => $plan['marketplace_raw_value'] ?? $order->marketplace,
            'normalized_marketplace' => $plan['normalized_marketplace'] ?? $marketplace,
            'target_marketplace_status' => $plan['target_marketplace_status'] ?? null,
            'target_marketplace_action' => $plan['target_marketplace_action'] ?? null,
            'mapping_supported' => (bool) ($plan['supported'] ?? false),
            'mapper_branch' => $plan['mapper_branch'] ?? null,
            'code_version' => self::CODE_VERSION,
            'order_status_sync_code_version' => self::CODE_VERSION,
            'sync_writer' => self::SYNC_WRITER,
        ];
    }

    private function skipRequestSummary(Order $order, string $marketplace, array $plan, ?string $skippedReason): array
    {
        return [
            'method' => $marketplace === 'allegro' ? 'PUT' : null,
            'endpoint' => $marketplace === 'allegro' ? 'PUT /order/checkout-forms/{checkoutFormId}/fulfillment' : null,
            'marketplace' => $marketplace,
            'marketplace_raw_value' => $plan['marketplace_raw_value'] ?? $order->marketplace,
            'normalized_marketplace' => $plan['normalized_marketplace'] ?? $marketplace,
            'checkout_form_id' => $marketplace === 'allegro' ? $order->marketplace_order_id : null,
            'local_status' => $plan['new_local_status'] ?? $order->status,
            'local_status_raw_value' => $plan['local_status_raw_value'] ?? $order->status,
            'local_status_ui_label' => $plan['local_status_ui_label'] ?? null,
            'previous_local_status' => $plan['previous_local_status'] ?? null,
            'target_marketplace_status' => $plan['target_marketplace_status'] ?? null,
            'target_marketplace_action' => $plan['target_marketplace_action'] ?? null,
            'mapping_supported' => (bool) ($plan['supported'] ?? false),
            'mapper_branch' => $plan['mapper_branch'] ?? null,
            'available_map' => $plan['available_map'] ?? null,
            'supported_marketplace_statuses' => $plan['supported_marketplace_statuses'] ?? null,
            'supported_local_statuses' => $plan['supported_local_statuses'] ?? null,
            'mapper_class' => $plan['mapper_class'] ?? self::class,
            'mapper_method' => $plan['mapper_method'] ?? 'plan',
            'order_status_sync_code_version' => self::CODE_VERSION,
            'code_version' => self::CODE_VERSION,
            'sync_writer' => self::SYNC_WRITER,
            'skipped_reason' => $skippedReason,
        ];
    }

    private function skipResponseSummary(array $plan, ?string $skippedReason): array
    {
        return [
            'response' => null,
            'error' => null,
            'mapping_supported' => (bool) ($plan['supported'] ?? false),
            'local_status_raw_value' => $plan['local_status_raw_value'] ?? null,
            'local_status_ui_label' => $plan['local_status_ui_label'] ?? null,
            'previous_local_status' => $plan['previous_local_status'] ?? null,
            'marketplace' => $plan['marketplace'] ?? null,
            'marketplace_raw_value' => $plan['marketplace_raw_value'] ?? null,
            'normalized_marketplace' => $plan['normalized_marketplace'] ?? null,
            'marketplace_order_id' => $plan['marketplace_order_id'] ?? null,
            'mapper_branch' => $plan['mapper_branch'] ?? null,
            'mapper_class' => $plan['mapper_class'] ?? self::class,
            'mapper_method' => $plan['mapper_method'] ?? 'plan',
            'order_status_sync_code_version' => self::CODE_VERSION,
            'code_version' => self::CODE_VERSION,
            'sync_writer' => self::SYNC_WRITER,
            'available_map' => $plan['available_map'] ?? null,
            'target_marketplace_status' => $plan['target_marketplace_status'] ?? null,
            'target_marketplace_action' => $plan['target_marketplace_action'] ?? null,
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
