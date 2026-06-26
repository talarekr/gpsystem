<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Shipment;
use Carbon\Carbon;
use Illuminate\Support\Str;

class OrderDeliveryDisplayResolver
{
    public function resolve(Order $order): array
    {
        $marketplace = Str::lower(trim((string) $order->marketplace));

        return match ($marketplace) {
            'allegro' => $this->resolveAllegro($order),
            default => $this->resolveFallback($order),
        };
    }

    private function resolveAllegro(Order $order): array
    {
        $payload = $order->raw_payload ?? [];
        $shippingTotal = app(OrderShippingTotalDisplayResolver::class)->resolve($order);
        $method = $this->firstFilled([
            data_get($payload, 'delivery.method.name'),
            data_get($payload, 'delivery.method.id'),
            data_get($payload, 'delivery.shippingCarrierCode'),
            $order->delivery_method,
        ]);

        return $this->compactLines([
            $this->deadlineLine('Czas na wysłanie', 'do', $this->firstFilled([
                data_get($payload, 'delivery.time.dispatch.to'),
                data_get($payload, 'delivery.dispatch.to'),
                data_get($payload, 'delivery.time.dispatch.from'),
                data_get($payload, 'delivery.dispatch.from'),
                data_get($payload, 'delivery.deadline'),
                data_get($payload, 'shipment.deadline'),
                data_get($payload, 'shipping_deadline'),
                data_get($payload, 'dispatch_time'),
            ])),
            $this->dateRangeLine('Przewidywana dostawa', $this->firstFilled([
                data_get($payload, 'delivery.time.from'),
                data_get($payload, 'delivery.expected.from'),
                data_get($payload, 'delivery.guaranteed.from'),
            ]), $this->firstFilled([
                data_get($payload, 'delivery.time.to'),
                data_get($payload, 'delivery.expected.to'),
                data_get($payload, 'delivery.expected'),
                data_get($payload, 'delivery.guaranteed.to'),
                data_get($payload, 'delivery.guaranteed'),
            ])),
            $method,
            $this->isCashOnDelivery($payload) ? 'Pobranie' : null,
            $shippingTotal !== null ? 'Koszt dostawy: '.$this->money($shippingTotal['amount'], $shippingTotal['currency']) : null,
            $this->shipmentsCountLine($payload, $order),
            $this->trackingLine($payload, $order),
        ]);
    }

    private function resolveFallback(Order $order): array
    {
        $shipment = $order->relationLoaded('shipments') ? $order->shipments->first() : null;
        $shippingTotal = app(OrderShippingTotalDisplayResolver::class)->resolve($order);

        return $this->compactLines([
            $order->delivery_method,
            $shippingTotal !== null ? 'Koszt dostawy: '.$this->money($shippingTotal['amount'], $shippingTotal['currency']) : null,
            $shipment?->tracking_number ? 'Nr przesyłki: '.$shipment->tracking_number : null,
        ]);
    }

    private function dateRangeLine(string $label, ?string $from, ?string $to): ?string
    {
        if ($from === null && $to === null) {
            return null;
        }

        if ($from !== null && $to !== null && $from !== $to) {
            return $label.': '.$this->formatDate($from).' – '.$this->formatDate($to);
        }

        return $label.': '.$this->formatDate($to ?? $from);
    }

    private function deadlineLine(string $label, string $prefix, ?string $value): ?string
    {
        return $value === null ? null : $label.': '.$prefix.' '.$this->formatDate($value);
    }

    private function formatDate(string $value): string
    {
        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return trim($value);
        }

        return str_contains($value, 'T') || preg_match('/\d{1,2}:\d{2}/', $value) === 1
            ? $date->format('Y-m-d H:i')
            : $date->toDateString();
    }

    private function isCashOnDelivery(array $payload): bool
    {
        foreach ([data_get($payload, 'payment.type'), data_get($payload, 'payment_method'), data_get($payload, 'payment_type')] as $value) {
            $value = Str::lower(trim((string) $value));
            if (Str::contains($value, ['cash_on_delivery', 'cod', 'pobran'])) {
                return true;
            }
        }

        return data_get($payload, 'payment.cashOnDelivery') !== null || data_get($payload, 'payment.cod') !== null;
    }

    private function shipmentsCountLine(array $payload, Order $order): ?string
    {
        $count = $this->numeric(data_get($payload, 'shipmentSummary.count'))
            ?? $this->numeric(data_get($payload, 'shipmentsCount'))
            ?? (is_array(data_get($payload, 'shipments')) ? count(data_get($payload, 'shipments')) : null)
            ?? ($order->relationLoaded('shipments') && $order->shipments->isNotEmpty() ? $order->shipments->count() : null);

        return $count !== null && $count > 0 ? 'Liczba przesyłek: '.(int) $count : null;
    }

    private function trackingLine(array $payload, Order $order): ?string
    {
        $shipment = $order->relationLoaded('shipments') ? $order->shipments->first() : null;
        $carrier = $this->firstFilled([
            data_get($payload, 'shipmentSummary.carrier'),
            data_get($payload, 'shipments.0.carrier'),
            data_get($payload, 'tracking.carrier'),
            $shipment ? (Shipment::CARRIERS[$shipment->carrier] ?? $shipment->carrier) : null,
        ]);
        $number = $this->firstFilled([
            data_get($payload, 'shipmentSummary.waybill'),
            data_get($payload, 'shipmentSummary.trackingNumber'),
            data_get($payload, 'shipments.0.trackingNumber'),
            data_get($payload, 'shipments.0.waybill'),
            data_get($payload, 'tracking.number'),
            data_get($payload, 'tracking.trackingNumber'),
            $shipment?->tracking_number,
        ]);
        $allegroId = $this->firstFilled([
            data_get($payload, 'shipmentSummary.id'),
            data_get($payload, 'shipments.0.id'),
            data_get($payload, 'tracking.id'),
            data_get($payload, 'tracking.allegroId'),
        ]);
        $value = trim(collect([$carrier, $number, $allegroId])->filter()->unique()->implode(' '));

        return $value !== '' ? 'Przesyłka: '.$value : null;
    }

    private function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', ' ').' '.Str::upper($currency ?: 'PLN');
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && $value !== '-' && Str::lower($value) !== 'null') {
                return $value;
            }
        }

        return null;
    }

    private function compactLines(array $lines): array
    {
        return array_values(array_filter($lines, fn ($line): bool => is_string($line) && trim($line) !== ''));
    }
}
