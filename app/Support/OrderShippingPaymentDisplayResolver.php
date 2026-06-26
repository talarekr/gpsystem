<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Str;

class OrderShippingPaymentDisplayResolver
{
    public function resolve(Order $order, bool $includeAmount = false): array
    {
        $marketplace = Str::lower(trim((string) $order->marketplace));

        return match ($marketplace) {
            'allegro' => $this->resolveAllegro($order, $includeAmount),
            'ebay', 'ebay_de', 'ebay_fr' => $this->resolveEbay($order),
            'ovoko' => $this->resolveOvoko($order),
            default => $this->resolveLocal($order),
        };
    }

    private function resolveAllegro(Order $order, bool $includeAmount): array
    {
        return $this->compactLines(
            $this->allegroPaymentLabel($order, $includeAmount),
            $this->firstFilled([
                data_get($order->raw_payload, 'delivery.method.name'),
                data_get($order->raw_payload, 'delivery.method.id'),
                data_get($order->raw_payload, 'delivery.shippingCarrierCode'),
                $order->delivery_method,
            ]),
        );
    }

    private function resolveEbay(Order $order): array
    {
        return $this->compactLines($this->ebayPaymentLabel($order), null);
    }

    private function resolveOvoko(Order $order): array
    {
        $provider = $this->providerLabel($this->firstFilled([
            data_get($order->raw_payload, 'shipping_provider'),
            data_get($order->raw_payload, 'delivery_provider'),
            data_get($order->raw_payload, 'carrier'),
        ]));
        $type = $this->ovokoDeliveryTypeLabel($this->firstFilled([
            data_get($order->raw_payload, 'delivery_type'),
            data_get($order->raw_payload, 'shipping_type'),
        ]));
        $delivery = collect([$provider, $type])->filter()->implode(' · ');

        if ($delivery === '') {
            $delivery = $this->firstFilled([$order->delivery_method]);
        }

        return $this->compactLines($this->ovokoPaymentLabel($order), $delivery);
    }

    private function resolveLocal(Order $order): array
    {
        return $this->compactLines(null, $order->delivery_method);
    }

    private function allegroPaymentLabel(Order $order, bool $includeAmount): ?string
    {
        $type = Str::upper(trim((string) data_get($order->raw_payload, 'payment.type')));
        if ($type === 'CASH_ON_DELIVERY') {
            $amount = $this->cashOnDeliveryAmount($order);

            return $includeAmount && $amount !== null ? 'Pobranie · '.$amount : 'Pobranie';
        }

        if ($this->hasConfirmedOnlinePayment($order)) {
            $amount = $includeAmount ? $this->paidAmount($order) : null;

            return $amount !== null ? 'Zapłacono · '.$amount : 'Zapłacono';
        }

        return $type !== '' || $this->firstFilled([$order->payment_status, data_get($order->raw_payload, 'payment')]) !== null ? 'Oczekuje' : null;
    }

    private function ebayPaymentLabel(Order $order): ?string
    {
        $status = Str::upper(trim((string) $this->firstFilled([
            $order->payment_status,
            data_get($order->raw_payload, 'paymentSummary.payments.0.paymentStatus'),
            data_get($order->raw_payload, 'payment_status'),
        ])));

        if (in_array($status, ['PAID', 'PAID_IN_FULL', 'SETTLED', 'COMPLETED'], true)) {
            return 'Zapłacono';
        }

        return $status !== '' ? 'Oczekuje' : 'Zapłacono';
    }

    private function ovokoPaymentLabel(Order $order): ?string
    {
        $paymentType = Str::lower(trim((string) $this->firstFilled([
            data_get($order->raw_payload, 'payment_type'),
            data_get($order->raw_payload, 'payment_method'),
            $order->payment_method ?? null,
        ])));

        if (Str::contains($paymentType, ['cod', 'cash_on_delivery', 'cash on delivery', 'pobran'])) {
            return 'Pobranie';
        }

        $status = Str::lower(trim((string) $this->firstFilled([$order->payment_status, data_get($order->raw_payload, 'payment_status')])));
        if ($status === '' && $paymentType === '') {
            return null;
        }

        if (Str::contains($status, ['unpaid', 'pending', 'waiting', 'not_paid', 'not paid', 'oczek'])) {
            return 'Oczekuje';
        }

        return 'Zapłacono';
    }

    private function hasConfirmedOnlinePayment(Order $order): bool
    {
        $status = Str::upper(trim((string) $order->payment_status));
        $finishedAt = $this->firstFilled([data_get($order->raw_payload, 'payment.finishedAt')]);
        $paidAmount = data_get($order->raw_payload, 'payment.paidAmount.amount', data_get($order->raw_payload, 'payment.paidAmount'));

        return in_array($status, ['PAID', 'PAID_IN_FULL', 'FINISHED', 'COMPLETED', 'READY_FOR_PROCESSING'], true)
            || $finishedAt !== null
            || (is_numeric($paidAmount) && (float) $paidAmount > 0);
    }

    private function cashOnDeliveryAmount(Order $order): ?string
    {
        $amount = $this->firstNumeric([
            data_get($order->raw_payload, 'payment.cashOnDelivery.amount'),
            data_get($order->raw_payload, 'payment.cashOnDelivery.value'),
            data_get($order->raw_payload, 'payment.cod.amount'),
            data_get($order->raw_payload, 'payment.cod.value'),
            data_get($order->raw_payload, 'payment.amount.amount'),
            data_get($order->raw_payload, 'payment.amount'),
            data_get($order->raw_payload, 'summary.totalToPay.amount'),
            data_get($order->raw_payload, 'summary.totalToPay'),
            data_get($order->raw_payload, 'totalToPay.amount'),
            data_get($order->raw_payload, 'totalToPay'),
            $order->total,
        ]);

        if ($amount === null) {
            return null;
        }

        $currency = $this->firstFilled([
            data_get($order->raw_payload, 'payment.cashOnDelivery.currency'),
            data_get($order->raw_payload, 'payment.cod.currency'),
            data_get($order->raw_payload, 'payment.amount.currency'),
            data_get($order->raw_payload, 'summary.totalToPay.currency'),
            data_get($order->raw_payload, 'totalToPay.currency'),
            $order->currency,
        ]) ?? 'PLN';

        return number_format($amount, 2, ',', ' ').' '.Str::upper($currency);
    }

    private function paidAmount(Order $order): ?string
    {
        $amount = $this->firstNumeric([
            data_get($order->raw_payload, 'payment.paidAmount.amount'),
            data_get($order->raw_payload, 'payment.paidAmount'),
            data_get($order->raw_payload, 'summary.paidAmount.amount'),
            data_get($order->raw_payload, 'summary.paidAmount'),
            data_get($order->raw_payload, 'summary.totalToPay.amount'),
            data_get($order->raw_payload, 'summary.totalToPay'),
            data_get($order->raw_payload, 'totalToPay.amount'),
            data_get($order->raw_payload, 'totalToPay'),
            data_get($order->raw_payload, 'payment.amount.amount'),
            data_get($order->raw_payload, 'payment.amount'),
            $order->total,
        ]);

        if ($amount === null) {
            return null;
        }

        $currency = $this->firstFilled([
            data_get($order->raw_payload, 'payment.paidAmount.currency'),
            data_get($order->raw_payload, 'summary.paidAmount.currency'),
            data_get($order->raw_payload, 'summary.totalToPay.currency'),
            data_get($order->raw_payload, 'totalToPay.currency'),
            data_get($order->raw_payload, 'payment.amount.currency'),
            $order->currency,
        ]) ?? 'PLN';

        return number_format($amount, 2, ',', ' ').' '.Str::upper($currency);
    }

    private function ovokoDeliveryTypeLabel(?string $type): ?string
    {
        return match (Str::lower(trim((string) $type))) {
            'courier' => 'Kurier',
            'parcel_locker' => 'Paczkomat',
            'self_pickup' => 'Odbiór osobisty',
            default => null,
        };
    }

    private function providerLabel(?string $provider): ?string
    {
        $provider = trim((string) $provider);
        if ($provider === '') {
            return null;
        }

        return [
            'dpd-poland' => 'DPD Poland',
            'dpd-baltic' => 'DPD Baltic',
            'in-post' => 'InPost',
            'ups' => 'UPS',
            'fedex' => 'FedEx',
            'gls' => 'GLS',
            'venipak' => 'Venipak',
            'schenker' => 'Schenker',
            'ovoko-economy' => 'Ovoko Economy',
            'ovoko-express' => 'Ovoko Express',
        ][Str::lower($provider)] ?? Str::headline(str_replace(['_', '-'], ' ', $provider));
    }

    private function compactLines(?string $payment, ?string $delivery): array
    {
        return array_filter([
            'payment' => $this->blankToNull($payment),
            'delivery' => $this->blankToNull($delivery),
        ], fn (?string $value): bool => $value !== null);
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }
            $value = $this->blankToNull($value);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function firstNumeric(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }

            if (is_string($value)) {
                $value = str_replace([' ', ','], ['', '.'], $value);
            }

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
