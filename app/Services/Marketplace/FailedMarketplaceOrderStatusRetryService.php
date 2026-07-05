<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceSyncLog;
use App\Models\Order;

class FailedMarketplaceOrderStatusRetryService
{
    public function preview(MarketplaceSyncLog $log): array
    {
        $order = $log->order;
        if (! $order) return ['retryable' => false, 'blocker' => 'Order is missing.', 'original_log_id' => $log->id];
        $plan = app(OrderStatusMarketplaceSyncService::class)->plan($order, data_get($log->payload, 'previous_local_status'), $log->id);
        return $plan + ['retryable' => $log->status === 'error' && (bool) ($plan['supported'] ?? false), 'original_log_id' => $log->id, 'blocker' => $log->status === 'error' ? ($plan['skipped_reason'] ?? null) : 'Only error logs are retryable.'];
    }

    public function retry(MarketplaceSyncLog $log): array
    {
        $preview = $this->preview($log);
        if (! ($preview['retryable'] ?? false)) return ['ok' => false, 'status' => 'blocked', 'plan' => $preview];
        /** @var Order $order */
        $order = $log->order;
        $retry = app(OrderStatusMarketplaceSyncService::class)->sync($order, data_get($log->payload, 'previous_local_status'), $log->id);
        return ['ok' => $retry->status === 'success', 'status' => $retry->status, 'log_id' => $retry->id, 'plan' => $preview];
    }
}
