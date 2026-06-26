<?php

namespace App\Support;

use App\Models\Order;

class OrderCustomerDisplay
{
    /**
     * @return array{name:string,phone:string}
     */
    public static function forOrder(Order $order): array
    {
        $marketplace = strtolower(trim((string) $order->marketplace));
        $fallbackName = self::fallbackName($order);
        $marketplaceFallbackName = self::marketplaceFallbackName($order);
        $fallbackPhone = trim((string) $order->phone);

        if ($marketplace === 'ovoko') {
            return [
                'name' => self::firstFilled([
                    data_get($order->raw_payload, 'client_name'),
                    $order->customer_name,
                    $marketplaceFallbackName,
                ]),
                'phone' => self::firstFilled([
                    data_get($order->raw_payload, 'client_phone'),
                    $order->phone,
                ]),
            ];
        }

        if ($marketplace === 'allegro') {
            $buyerFirstLast = self::joinName([
                data_get($order->raw_payload, 'buyer.firstName'),
                data_get($order->raw_payload, 'buyer.lastName'),
            ]);

            return [
                'name' => self::firstFilled([
                    $buyerFirstLast,
                    data_get($order->raw_payload, 'buyer.fullName'),
                    data_get($order->raw_payload, 'buyer.name'),
                    $marketplaceFallbackName,
                ]),
                'phone' => self::firstFilled([
                    data_get($order->raw_payload, 'buyer.phoneNumber'),
                    data_get($order->raw_payload, 'buyer.phone'),
                    data_get($order->raw_payload, 'delivery.address.phoneNumber'),
                    data_get($order->raw_payload, 'delivery.address.phone'),
                    data_get($order->raw_payload, 'shippingAddress.phoneNumber'),
                    data_get($order->raw_payload, 'shippingAddress.phone'),
                    $order->phone,
                ]),
            ];
        }

        return [
            'name' => $fallbackName,
            'phone' => $fallbackPhone,
        ];
    }

    private static function fallbackName(Order $order): string
    {
        return self::firstFilled([$order->customer_name, $order->company_name, $order->email, '—']);
    }

    private static function marketplaceFallbackName(Order $order): string
    {
        $customerName = trim((string) $order->customer_name);
        $payloadLogin = self::firstFilled([
            data_get($order->raw_payload, 'buyer.login'),
            data_get($order->raw_payload, 'buyer.username'),
        ]);

        if ($customerName !== '' && strcasecmp($customerName, $payloadLogin) !== 0) {
            return $customerName;
        }

        return '—';
    }

    /**
     * @param array<int, mixed> $values
     */
    private static function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<int, mixed> $parts
     */
    private static function joinName(array $parts): string
    {
        return trim(implode(' ', array_filter(array_map(
            fn (mixed $part): string => trim((string) $part),
            $parts,
        ), fn (string $part): bool => $part !== '')));
    }
}
