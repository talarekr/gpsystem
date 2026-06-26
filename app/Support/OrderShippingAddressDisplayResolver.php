<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Str;

class OrderShippingAddressDisplayResolver
{
    public function resolve(Order $order): array
    {
        $marketplace = Str::lower(trim((string) $order->marketplace));

        return match ($marketplace) {
            'ebay', 'ebay_de', 'ebay_fr' => $this->resolveEbay($order),
            default => $this->resolveLocal($order),
        };
    }

    private function resolveEbay(Order $order): array
    {
        $payload = $order->raw_payload ?? [];
        $shipTo = $this->firstArray([
            data_get($payload, 'fulfillmentStartInstructions.0.shippingStep.shipTo'),
            data_get($payload, 'shipTo'),
            data_get($payload, 'shippingAddress'),
            data_get($payload, 'delivery.address'),
            data_get($payload, 'shipping.address'),
            data_get($payload, 'fulfillment.shippingStep.shipTo'),
            data_get($payload, 'buyer.buyerRegistrationAddress'),
        ]);
        $contactAddress = $this->firstArray([
            data_get($shipTo, 'contactAddress'),
            data_get($shipTo, 'address'),
            $shipTo,
        ]) ?? [];

        $recipient = $this->firstFilled([
            data_get($shipTo, 'fullName'),
            data_get($shipTo, 'name'),
            data_get($shipTo, 'companyName'),
            data_get($contactAddress, 'fullName'),
            data_get($contactAddress, 'name'),
            data_get($contactAddress, 'companyName'),
        ]);
        $street = $this->joinFilled([
            data_get($contactAddress, 'addressLine1'),
            data_get($contactAddress, 'addressLine2'),
        ]);
        $cityLine = $this->joinFilled([
            data_get($contactAddress, 'postalCode') ?: data_get($contactAddress, 'zipCode') ?: data_get($contactAddress, 'postcode'),
            data_get($contactAddress, 'city'),
            data_get($contactAddress, 'stateOrProvince'),
            data_get($contactAddress, 'countryCode') ?: data_get($contactAddress, 'country'),
        ]);
        $phone = $this->firstFilled([
            data_get($shipTo, 'primaryPhone.phoneNumber'),
            data_get($shipTo, 'primaryPhone.number'),
            data_get($shipTo, 'phoneNumber'),
            data_get($shipTo, 'phone'),
            $order->phone,
        ]);

        $lines = $this->compactLines([$recipient, $street, $cityLine, $phone]);

        return $lines !== [] ? $lines : ['Brak danych adresowych'];
    }

    private function resolveLocal(Order $order): array
    {
        $payload = $order->raw_payload ?? [];
        $recipient = $this->firstFilled([
            $this->joinFilled([data_get($payload, 'delivery.address.firstName'), data_get($payload, 'delivery.address.lastName')]),
            data_get($payload, 'delivery.address.name'),
            data_get($payload, 'delivery.address.fullName'),
            $this->joinFilled([data_get($payload, 'buyer.firstName'), data_get($payload, 'buyer.lastName')]),
            data_get($payload, 'buyer.fullName'),
            data_get($payload, 'buyer.name'),
            $order->customer_name,
            $order->company_name,
        ]);
        $cityLine = $this->joinFilled([$order->postal_code, $order->city, $order->country]);

        return $this->compactLines([$recipient, $order->address_line1, $cityLine, $order->phone]);
    }

    private function firstArray(array $values): ?array
    {
        foreach ($values as $value) {
            if (is_array($value) && $value !== []) {
                return $value;
            }
        }

        return null;
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

    private function joinFilled(array $values): ?string
    {
        $line = trim(implode(' ', array_filter(array_map(
            fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $values,
        ), fn (string $value): bool => $value !== '' && $value !== '-')));

        return $line !== '' ? $line : null;
    }

    private function compactLines(array $lines): array
    {
        return array_values(array_filter($lines, fn ($line): bool => is_string($line) && trim($line) !== ''));
    }
}
