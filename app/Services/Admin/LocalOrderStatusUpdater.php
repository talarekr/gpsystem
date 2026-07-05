<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Services\Marketplace\OrderStatusMarketplaceSyncService;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class LocalOrderStatusUpdater
{
    /**
     * @return array{order: Order, sync_log: \App\Models\MarketplaceSyncLog|null}
     */
    public function updateWithSyncResult(Order $order, string $status): array
    {
        $status = trim($status);
        $options = OrderStatusOptions::optionsForOrder($order);

        if (! array_key_exists($status, $options)) {
            throw new InvalidArgumentException('Wybrany status nie jest dostępny dla tego kanału sprzedaży.');
        }

        $previousStatus = (string) $order->status;
        $updates = ['status' => $status];

        if ($order->status !== $status && Schema::hasColumn($order->getTable(), 'status_changed_at')) {
            $updates['status_changed_at'] = now();
        }

        $order->forceFill($updates)->save();

        $order = $order->refresh();

        if ($previousStatus !== $status) {
            $syncLog = app(OrderStatusMarketplaceSyncService::class)->sync($order, $previousStatus);
        }

        return ['order' => $order, 'sync_log' => $syncLog ?? null];
    }

    public function update(Order $order, string $status): Order
    {
        return $this->updateWithSyncResult($order, $status)['order'];
    }
}
