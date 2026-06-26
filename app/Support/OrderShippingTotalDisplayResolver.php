<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Str;

class OrderShippingTotalDisplayResolver
{
    public function resolve(Order $order): ?array
    {
        return $this->dedicatedPayloadAmount($order)
            ?? $this->localShippingTotal($order)
            ?? $this->safeDifferenceFallback($order);
    }

    private function dedicatedPayloadAmount(Order $order): ?array
    {
        foreach ($this->payloadCandidates($order) as $candidate) {
            $amount = $this->numeric($candidate['amount'] ?? null);
            if ($amount === null || $amount < 0) {
                continue;
            }

            return [
                'amount' => $amount,
                'currency' => $this->currency($candidate['currency'] ?? null, $order),
                'source' => $candidate['source'],
            ];
        }

        return null;
    }

    private function payloadCandidates(Order $order): array
    {
        $payload = $order->raw_payload ?? [];

        return [
            ['amount' => data_get($payload, 'delivery.cost.amount'), 'currency' => data_get($payload, 'delivery.cost.currency'), 'source' => 'raw_payload.delivery.cost.amount'],
            ['amount' => data_get($payload, 'summary.deliveryCost.amount'), 'currency' => data_get($payload, 'summary.deliveryCost.currency'), 'source' => 'raw_payload.summary.deliveryCost.amount'],
            ['amount' => data_get($payload, 'delivery.price.amount'), 'currency' => data_get($payload, 'delivery.price.currency'), 'source' => 'raw_payload.delivery.price.amount'],
            ['amount' => data_get($payload, 'delivery.amount'), 'currency' => data_get($payload, 'delivery.currency'), 'source' => 'raw_payload.delivery.amount'],
            ['amount' => data_get($payload, 'delivery.cost'), 'currency' => data_get($payload, 'delivery.currency'), 'source' => 'raw_payload.delivery.cost'],
            ['amount' => data_get($payload, 'delivery.price'), 'currency' => data_get($payload, 'delivery.currency'), 'source' => 'raw_payload.delivery.price'],
            ['amount' => data_get($payload, 'summary.delivery.amount'), 'currency' => data_get($payload, 'summary.delivery.currency'), 'source' => 'raw_payload.summary.delivery.amount'],
            ['amount' => data_get($payload, 'summary.delivery.value'), 'currency' => data_get($payload, 'summary.delivery.currency'), 'source' => 'raw_payload.summary.delivery.value'],
            ['amount' => data_get($payload, 'pricingSummary.deliveryCost.amount'), 'currency' => data_get($payload, 'pricingSummary.deliveryCost.currency'), 'source' => 'raw_payload.pricingSummary.deliveryCost.amount'],
            ['amount' => data_get($payload, 'pricingSummary.deliveryCost.value'), 'currency' => data_get($payload, 'pricingSummary.deliveryCost.currency'), 'source' => 'raw_payload.pricingSummary.deliveryCost.value'],
            ['amount' => data_get($payload, 'deliveryCost.amount'), 'currency' => data_get($payload, 'deliveryCost.currency'), 'source' => 'raw_payload.deliveryCost.amount'],
            ['amount' => data_get($payload, 'deliveryCost.value'), 'currency' => data_get($payload, 'deliveryCost.currency'), 'source' => 'raw_payload.deliveryCost.value'],
            ['amount' => data_get($payload, 'shipping_price.seller.amount'), 'currency' => data_get($payload, 'shipping_price.seller.currency'), 'source' => 'raw_payload.shipping_price.seller.amount'],
            ['amount' => data_get($payload, 'shipping_price.buyer.amount'), 'currency' => data_get($payload, 'shipping_price.buyer.currency'), 'source' => 'raw_payload.shipping_price.buyer.amount'],
            ['amount' => data_get($payload, 'delivery_amount'), 'currency' => data_get($payload, 'currency'), 'source' => 'raw_payload.delivery_amount'],
        ];
    }

    private function localShippingTotal(Order $order): ?array
    {
        $amount = $this->numeric($order->shipping_total);

        return $amount !== null && $amount > 0
            ? ['amount' => $amount, 'currency' => $this->currency(null, $order), 'source' => 'orders.shipping_total']
            : null;
    }

    private function safeDifferenceFallback(Order $order): ?array
    {
        $orderTotal = $this->numeric($order->total);
        if ($orderTotal === null || $orderTotal <= 0 || ! $order->relationLoaded('items')) {
            return null;
        }

        $currency = $this->currency(null, $order);
        $itemsTotal = 0.0;

        foreach ($order->items as $item) {
            $itemCurrency = $this->currency($item->currency ?: null, $order);
            $itemTotal = $this->numeric($item->line_total);

            if ($itemTotal === null || $itemCurrency !== $currency) {
                return null;
            }

            $itemsTotal += $itemTotal;
        }

        if ($itemsTotal <= 0) {
            $subtotal = $this->numeric($order->subtotal);
            if ($subtotal === null || $subtotal <= 0) {
                return null;
            }
            $itemsTotal = $subtotal;
        }

        $shipping = round($orderTotal - $itemsTotal, 2);

        return $shipping > 0
            ? ['amount' => $shipping, 'currency' => $currency, 'source' => 'fallback.total_minus_items_total']
            : null;
    }

    private function numeric(mixed $value): ?float
    {
        if (is_array($value)) {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function currency(mixed $currency, Order $order): string
    {
        $currency = trim((string) $currency);

        return Str::upper($currency !== '' ? $currency : ($order->currency ?: 'PLN'));
    }
}
