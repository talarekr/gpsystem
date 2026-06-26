<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Str;

class AllegroShipmentPreviewBuilder
{
    public function __construct(private readonly AllegroShipmentParcelSizeOptions $parcelSizeOptions) {}

    public function build(?Order $order, array $input = []): array
    {
        if (! $order) {
            return $this->notFound();
        }

        if (Str::lower((string) $order->marketplace) !== 'allegro') {
            return $this->notAllegro($order);
        }

        $payload = $order->raw_payload ?? [];
        $currency = $this->firstFilled([data_get($payload, 'summary.totalToPay.currency'), data_get($payload, 'payment.paidAmount.currency'), $order->currency]) ?? 'PLN';
        $orderData = [
            'id' => $order->id,
            'marketplace' => $order->marketplace,
            'marketplace_order_id' => $order->marketplace_order_id,
            'ordered_at' => optional($order->ordered_at)->toISOString(),
            'status' => $order->status,
            'marketplace_status' => $order->marketplace_status,
            'payment_status' => $order->payment_status,
            'subtotal' => $this->money($order->subtotal, $currency),
            'shipping_total' => $this->money($this->firstNumeric([data_get($payload, 'delivery.cost.amount'), $order->shipping_total]), $currency),
            'total' => $this->money($order->total, $currency),
            'currency' => $currency,
        ];
        $receiver = $this->receiver($order, $payload);
        $pickupPoint = $this->pickupPoint($payload);
        $delivery = $this->delivery($payload, $order);
        $sender = $this->sender();
        $parcelOptions = $this->parcelSizeOptions->resolve($delivery['name'] ?? null, $delivery['id'] ?? null, $delivery['shippingCarrierCode'] ?? null);
        $input = $this->normalizeParcelInput($input, $parcelOptions);
        $parcel = $this->parcel($order, $input);
        $cod = $this->cod($payload, $order, $currency);
        $warnings = $this->warnings($input, $parcelOptions);

        $missing = array_values(array_unique(array_merge(
            $this->missing($orderData, 'order', ['marketplace_order_id']),
            $this->missing($receiver, 'receiver', ['name', 'street', 'postalCode', 'city', 'countryCode', 'email', 'phone']),
            $this->missing($sender, 'sender', ['name', 'street', 'postalCode', 'city', 'countryCode', 'email', 'phone']),
            $this->missing($parcel, 'packages.0', ['length.value', 'width.value', 'height.value', 'weight.value'])
        )));

        if (($delivery['is_pickup_point'] ?? false) && blank($pickupPoint['id'] ?? null)) {
            $missing[] = 'pickupPoint.id';
        }

        return [
            'ok' => $missing === [],
            'read_only' => true,
            'allegro_write' => false,
            'shipment_created' => false,
            'label_created' => false,
            'pickup_ordered' => false,
            'marketplace_write' => false,
            'will_send' => false,
            'capabilities' => [
                'can_create_shipment' => true,
                'can_download_label' => true,
                'can_order_pickup' => true,
                'requires_package_dimensions' => true,
                'requires_weight' => true,
                'flow' => 'allegro_shipment_management',
            ],
            'required_fields' => ['weight', 'length', 'width', 'height', 'package_type', 'label_reference'],
            'validation' => ['ok' => $missing === [], 'missing' => $missing],
            'audit' => [
                'order' => $orderData,
                'receiver' => $receiver,
                'pickup_point' => $pickupPoint,
                'delivery_method' => $delivery,
                'sender' => $sender,
                'cash_on_delivery' => $cod,
                'parcel' => $parcel,
                'parcel_size_options' => $parcelOptions,
                'warnings' => $warnings,
                'subtotal_note' => 'audit.order.subtotal comes from orders.subtotal. Shipment payload uses subtotal only as the current insurance fallback; cashOnDelivery uses Allegro payment/summary total or order total.',
                'local_data_sources' => ['orders.*', 'orders.raw_payload.delivery.*', 'config services.shipments.sender.* / SHIPMENT_SENDER_*'],
            ],
            'warnings' => $warnings,
            'payload_preview' => [
                'endpoint' => 'POST /shipment-management/shipments/create-commands',
                'will_send' => false,
                'body' => [
                    'input' => array_filter([
                        'sender' => $sender,
                        'receiver' => array_filter($receiver + ['point' => $pickupPoint['id'] ?? null], fn ($v) => filled($v)),
                        'referenceNumber' => $order->marketplace_order_id ?: $order->order_number,
                        'packages' => [$parcel],
                        'insurance' => $this->money($this->firstNumeric([$order->subtotal, $order->total]), $currency),
                        'cashOnDelivery' => $cod['is_cod'] ? $this->money($cod['amount'], $cod['currency']) : null,
                        'labelFormat' => 'PDF',
                        'additionalServices' => [],
                        'additionalProperties' => new \stdClass(),
                    ], fn ($v) => $v !== null),
                ],
            ],
        ];
    }

    private function notFound(): array
    {
        return ['ok' => false, 'read_only' => true, 'allegro_write' => false, 'shipment_created' => false, 'label_created' => false, 'pickup_ordered' => false, 'marketplace_write' => false, 'will_send' => false, 'error' => 'Order not found.'];
    }

    private function notAllegro(Order $order): array
    {
        return [
            'ok' => false,
            'read_only' => true,
            'allegro_write' => false,
            'shipment_created' => false,
            'label_created' => false,
            'pickup_ordered' => false,
            'marketplace_write' => false,
            'will_send' => false,
            'error' => 'order_not_allegro',
            'order' => [
                'id' => $order->id,
                'marketplace' => $order->marketplace,
                'marketplace_order_id' => $order->marketplace_order_id,
            ],
            'capabilities' => Str::lower((string) $order->marketplace) === 'ebay' ? [
                'supported' => false,
                'reason' => 'eBay shipment flow will be handled separately via DHL/API.',
            ] : ['supported' => false, 'reason' => 'Allegro preview accepts only Allegro orders.'],
        ];
    }

    private function receiver(Order $order, array $payload): array
    {
        return array_filter([
            'name' => $this->firstFilled([$this->join([data_get($payload, 'delivery.address.firstName'), data_get($payload, 'delivery.address.lastName')]), data_get($payload, 'delivery.address.name'), $order->customer_name]),
            'street' => $this->firstFilled([data_get($payload, 'delivery.address.street'), data_get($payload, 'delivery.address.addressLine1'), $order->address_line1]),
            'postalCode' => $this->firstFilled([data_get($payload, 'delivery.address.zipCode'), data_get($payload, 'delivery.address.postCode'), $order->postal_code]),
            'city' => $this->firstFilled([data_get($payload, 'delivery.address.city'), $order->city]),
            'countryCode' => $this->firstFilled([data_get($payload, 'delivery.address.countryCode'), data_get($payload, 'delivery.address.country'), $order->country]) ?: 'PL',
            'email' => $this->firstFilled([data_get($payload, 'buyer.email'), data_get($payload, 'delivery.address.email'), $order->email]),
            'phone' => $this->firstFilled([data_get($payload, 'delivery.address.phoneNumber'), data_get($payload, 'delivery.address.phone'), data_get($payload, 'buyer.phoneNumber'), $order->phone]),
        ], fn ($v) => filled($v));
    }

    private function pickupPoint(array $payload): array
    {
        $point = data_get($payload, 'delivery.pickupPoint', []);
        return is_array($point) ? $point : [];
    }

    private function delivery(array $payload, Order $order): array
    {
        $name = $this->firstFilled([data_get($payload, 'delivery.method.name'), $order->delivery_method]);
        return [
            'id' => data_get($payload, 'delivery.method.id'),
            'name' => $name,
            'shippingCarrierCode' => data_get($payload, 'delivery.shippingCarrierCode'),
            'is_cod' => Str::upper((string) data_get($payload, 'payment.type')) === 'CASH_ON_DELIVERY',
            'is_pickup_point' => data_get($payload, 'delivery.pickupPoint.id') !== null || Str::contains(Str::lower((string) $name), ['paczkomat', 'punkt', 'pickup', 'automat']),
            'is_courier' => Str::contains(Str::lower((string) $name), ['kurier', 'courier']),
            'deliveryMethodId_note' => 'Nie ustawiamy automatycznie w preview; w shipment-management pole jest opcjonalne dla create-commands.',
        ];
    }

    private function sender(): array
    {
        return array_filter([
            'name' => config('services.shipments.sender.name'),
            'street' => config('services.shipments.sender.address'),
            'postalCode' => config('services.shipments.sender.postal_code'),
            'city' => config('services.shipments.sender.city'),
            'countryCode' => config('services.shipments.sender.country', 'PL'),
            'email' => config('services.shipments.sender.email'),
            'phone' => config('services.shipments.sender.phone'),
        ], fn ($v) => filled($v));
    }

    private function parcel(Order $order, array $input = []): array
    {
        return ['type' => $input['package_type'] ?? 'PACKAGE', 'length' => ['value' => $input['length'] ?? null, 'unit' => 'CENTIMETER'], 'width' => ['value' => $input['width'] ?? null, 'unit' => 'CENTIMETER'], 'height' => ['value' => $input['height'] ?? null, 'unit' => 'CENTIMETER'], 'weight' => ['value' => $input['weight'] ?? null, 'unit' => 'KILOGRAMS'], 'textOnLabel' => Str::limit($input['label_reference'] ?? $this->defaultLabelReference($order), 20, '')];
    }

    private function normalizeParcelInput(array $input, array $options): array
    {
        $dimensions = $this->parcelSizeOptions->dimensionsForCode($input['size_code'] ?? null);

        if (($options['mode'] ?? null) === 'size_code' && $dimensions) {
            $input = array_merge($input, $dimensions);
        }

        return $input;
    }

    private function warnings(array $input, array $options): array
    {
        $warnings = [];
        $limit = $options['weight_limit_kg'] ?? null;
        if ($limit && is_numeric($input['weight'] ?? null) && (float) $input['weight'] > (float) $limit) {
            $warnings[] = 'Waga przekracza limit '.$limit.' kg dla InPost/Paczkomat.';
        }

        return $warnings;
    }

    public function defaultLabelReference(Order $order): string
    {
        $clientId = data_get($order->raw_payload, 'buyer.id') ?: data_get($order->raw_payload, 'buyer.login');
        $reference = filled($clientId) ? 'Client:'.$clientId : ($order->order_number ?: $order->marketplace_order_id ?: (string) $order->id);

        return Str::limit((string) $reference, 20, '');
    }

    private function cod(array $payload, Order $order, string $currency): array
    {
        $isCod = Str::upper((string) data_get($payload, 'payment.type')) === 'CASH_ON_DELIVERY';
        $amount = $this->firstNumeric([data_get($payload, 'payment.cashOnDelivery.amount'), data_get($payload, 'summary.totalToPay.amount'), $order->total]);
        return ['is_cod' => $isCod, 'amount' => $isCod ? $amount : null, 'currency' => $currency];
    }

    private function money(mixed $amount, string $currency): ?array { return is_numeric($amount) ? ['amount' => number_format((float) $amount, 2, '.', ''), 'currency' => $currency] : null; }
    private function firstFilled(array $values): ?string { foreach ($values as $v) { if (is_scalar($v) && trim((string) $v) !== '' && trim((string) $v) !== '-') return trim((string) $v); } return null; }
    private function firstNumeric(array $values): ?float { foreach ($values as $v) { if (is_numeric($v)) return (float) $v; } return null; }
    private function join(array $values): ?string { $s = trim(implode(' ', array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $values)))); return $s !== '' ? $s : null; }
    private function missing(array $data, string $prefix, array $keys): array { return array_values(array_filter(array_map(fn ($k) => blank(data_get($data, $k)) ? "$prefix.$k" : null, $keys))); }
}
