<?php

namespace App\Services\Admin;

use App\Models\Order;

class OrderStatusOptions
{
    /**
     * Local admin status definitions for /admin/orders list and detail cards.
     * These options are persisted only to the local orders.status column and
     * are not synchronized back to marketplaces.
     */
    private const OPTIONS = [
        'allegro' => [
            'new' => 'NOWE',
            'processing' => 'W REALIZACJI',
            'ready_to_ship' => 'DO WYSŁANIA',
            'ready_for_pickup' => 'DO ODBIORU',
            'shipped' => 'WYSŁANE',
            'picked_up' => 'ODEBRANE',
            'cancelled' => 'ANULOWANE',
            'on_hold' => 'WSTRZYMANE',
        ],
        'ebay' => [
            'new' => 'NOWE',
            'processing' => 'W REALIZACJI',
            'shipped' => 'WYSŁANE',
        ],
        'ovoko' => [
            'new' => 'NOWE',
            'processing' => 'W REALIZACJI',
            'ready_to_ship' => 'DO WYSŁANIA',
            'shipped' => 'WYSŁANE',
        ],
        'shop' => [
            'new' => 'NOWE',
            'processing' => 'W REALIZACJI',
            'on_hold' => 'WSTRZYMANE',
            'ready_to_ship' => 'DO WYSŁANIA',
            'ready_for_pickup' => 'DO ODBIORU',
            'shipped' => 'WYSŁANE',
            'picked_up' => 'ODEBRANE',
            'cancelled' => 'ANULOWANE',
        ],
        'fallback' => [
            'new' => 'NOWE',
            'processing' => 'W REALIZACJI',
            'shipped' => 'WYSŁANE',
        ],
    ];

    private const MARKETPLACE_STATUS_MAP = [
        'NEW' => 'new',
        'NOT_STARTED' => 'new',
        'PROCESSING' => 'processing',
        'IN_PROGRESS' => 'processing',
        'READY_FOR_PROCESSING' => 'processing',
        'READY_FOR_SHIPMENT' => 'ready_to_ship',
        'READY_TO_SHIP' => 'ready_to_ship',
        'READY_FOR_PICKUP' => 'ready_for_pickup',
        'SENT' => 'shipped',
        'FULFILLED' => 'shipped',
        'SHIPPED' => 'shipped',
        'PICKED_UP' => 'picked_up',
        'COMPLETED' => 'shipped',
        'COMPLETE' => 'shipped',
        'CANCELLED' => 'cancelled',
        'CANCELED' => 'cancelled',
        'SUSPENDED' => 'on_hold',
        'ON_HOLD' => 'on_hold',
        'HOLD' => 'on_hold',
    ];

    private const LOCAL_STATUS_MAP = [
        'new' => 'new',
        'processing' => 'processing',
        'completed' => 'shipped',
        'complete' => 'shipped',
        'shipped' => 'shipped',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'on_hold' => 'on_hold',
        'ready_to_ship' => 'ready_to_ship',
        'ready_for_pickup' => 'ready_for_pickup',
        'picked_up' => 'picked_up',
    ];

    public static function optionsForOrder(Order $order): array
    {
        return self::optionsForSource($order->marketplace);
    }

    public static function optionsForSource(?string $source): array
    {
        return self::OPTIONS[self::sourceKey($source)] ?? self::OPTIONS['fallback'];
    }

    public static function selectedValueForOrder(Order $order): string
    {
        $options = self::optionsForOrder($order);
        $candidate = self::mapStatus($order->status, self::LOCAL_STATUS_MAP)
            ?? self::mapStatus($order->marketplace_status, self::MARKETPLACE_STATUS_MAP);

        return $candidate !== null && array_key_exists($candidate, $options) ? $candidate : 'new';
    }

    private static function sourceKey(?string $source): string
    {
        $source = strtolower(trim((string) $source));

        return match ($source) {
            'allegro' => 'allegro',
            'ebay', 'ebay_de', 'ebay_fr' => 'ebay',
            'ovoko', 'rrr' => 'ovoko',
            'shop', 'sklep', 'local', 'lokalny', '' => 'shop',
            default => 'fallback',
        };
    }

    private static function mapStatus(mixed $status, array $map): ?string
    {
        $status = trim((string) $status);

        if ($status === '') {
            return null;
        }

        return $map[$status] ?? $map[strtolower($status)] ?? $map[strtoupper($status)] ?? null;
    }
}
