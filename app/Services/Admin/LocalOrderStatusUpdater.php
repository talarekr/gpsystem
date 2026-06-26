<?php

namespace App\Services\Admin;

use App\Models\Order;
use InvalidArgumentException;

class LocalOrderStatusUpdater
{
    public function update(Order $order, string $status): Order
    {
        $status = trim($status);
        $options = OrderStatusOptions::optionsForOrder($order);

        if (! array_key_exists($status, $options)) {
            throw new InvalidArgumentException('Wybrany status nie jest dostępny dla tego kanału sprzedaży.');
        }

        $order->forceFill(['status' => $status])->save();

        return $order->refresh();
    }
}
