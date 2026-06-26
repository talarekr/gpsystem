<?php

namespace App\Services\Marketplace\Shipments;

use App\Models\Order;
use App\Support\Shipments\ShipmentPreviewResult;
use BadMethodCallException;
use Illuminate\Support\Str;

class OvokoShipmentAdapter implements MarketplaceShipmentAdapterInterface
{
    public function supports(Order $order): bool
    {
        return Str::lower((string) $order->marketplace) === 'ovoko';
    }

    public function preview(Order $order, array $input = []): ShipmentPreviewResult
    {
        $payload = $order->raw_payload ?? [];
        $currency = $order->currency ?: (string) data_get($payload, 'currency', 'EUR');
        $package = [
            'weight' => $input['weight'] ?? null,
            'length' => $input['length'] ?? null,
            'width' => $input['width'] ?? null,
            'height' => $input['height'] ?? null,
            'package_type' => $input['package_type'] ?? null,
            'label_reference' => $input['label_reference'] ?? ($order->order_number ?: $order->marketplace_order_id),
        ];

        $receiver = array_filter([
            'name' => $this->firstFilled([data_get($payload, 'client.name'), data_get($payload, 'buyer.name'), $order->customer_name]),
            'street' => $this->firstFilled([data_get($payload, 'shipping.address'), data_get($payload, 'delivery.address.street'), $order->address_line1]),
            'postal_code' => $this->firstFilled([data_get($payload, 'shipping.post_code'), data_get($payload, 'delivery.address.zipCode'), $order->postal_code]),
            'city' => $this->firstFilled([data_get($payload, 'shipping.city'), data_get($payload, 'delivery.address.city'), $order->city]),
            'country' => $this->firstFilled([data_get($payload, 'shipping.country'), data_get($payload, 'delivery.address.countryCode'), $order->country]),
            'email' => $this->firstFilled([data_get($payload, 'client.email'), data_get($payload, 'buyer.email'), $order->email]),
            'phone' => $this->firstFilled([data_get($payload, 'client.phone'), data_get($payload, 'delivery.address.phone'), $order->phone]),
        ], fn ($value) => filled($value));

        $missing = array_values(array_filter(array_map(
            fn (string $field): ?string => blank(data_get($package, $field)) ? 'package.'.$field : null,
            ['weight', 'length', 'width', 'height']
        )));

        return ShipmentPreviewResult::make([
            'ok' => $this->supports($order),
            'read_only' => true,
            'ovoko_write' => false,
            'package_data_sent' => false,
            'label_downloaded' => false,
            'pickup_ordered' => false,
            'marketplace_write' => false,
            'will_send' => false,
            'capabilities' => $this->capabilities($order),
            'required_fields' => $this->requiredFields($order),
            'validation' => ['ok' => $missing === [], 'missing' => $missing],
            'audit' => [
                'order' => [
                    'id' => $order->id,
                    'marketplace' => $order->marketplace,
                    'marketplace_order_id' => $order->marketplace_order_id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'total' => $order->total,
                    'currency' => $currency,
                ],
                'receiver' => $receiver,
                'delivery_type' => $this->firstFilled([data_get($payload, 'delivery.type'), data_get($payload, 'delivery_type'), data_get($payload, 'shipping.type')]),
                'shipping_provider' => $this->firstFilled([data_get($payload, 'shipping.provider'), data_get($payload, 'shipping_provider'), data_get($payload, 'delivery.provider'), $order->delivery_method]),
                'payment_type' => $this->firstFilled([data_get($payload, 'payment.type'), data_get($payload, 'payment_type')]),
                'payment_method' => $this->firstFilled([data_get($payload, 'payment.method'), data_get($payload, 'payment_method'), $order->payment_status]),
                'package' => $package,
            ],
            'payload_preview' => [
                'endpoint' => 'crm/importPostData',
                'will_send' => false,
                'body' => [
                    'order_id' => $order->marketplace_order_id ?: $order->order_number,
                    'receiver' => $receiver,
                    'shipping_provider' => $this->firstFilled([data_get($payload, 'shipping.provider'), data_get($payload, 'shipping_provider'), $order->delivery_method]),
                    'package' => $package,
                ],
            ],
            'label_preview' => [
                'endpoint' => 'get/print_shipping_label/{order_id}',
                'order_id' => $order->marketplace_order_id ?: $order->order_number,
                'will_download' => false,
            ],
        ]);
    }

    public function requiredFields(Order $order): array
    {
        return ['weight', 'length', 'width', 'height', 'package_type', 'label_reference'];
    }

    public function capabilities(Order $order): array
    {
        return [
            'can_create_shipment' => false,
            'can_send_package_data' => true,
            'can_download_label' => true,
            'can_order_pickup' => false,
            'requires_package_dimensions' => true,
            'requires_weight' => true,
            'flow' => 'ovoko_package_data_then_label',
        ];
    }

    public function sendPackageData(Order $order, array $input = []): never
    {
        throw new BadMethodCallException('Real Ovoko package-data write is not implemented in the read-only stage.');
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
