<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Str;

class OrderInvoiceDisplayResolver
{
    /**
     * @return array{has_invoice: bool, lines: list<string>, fields: list<string>}
     */
    public function resolve(Order $order): array
    {
        $localInvoiceCompany = $this->firstFilled([$order->company_name]);
        $localInvoiceNip = $this->firstFilled([$order->nip]);
        $invoiceCompany = $this->firstFilled([
            data_get($order->raw_payload, 'invoice.company.name'),
            data_get($order->raw_payload, 'invoice.companyName'),
            data_get($order->raw_payload, 'invoice.company'),
            data_get($order->raw_payload, 'invoice.address.companyName'),
            data_get($order->raw_payload, 'invoice.address.company'),
            data_get($order->invoice_data, 'company.name'),
            data_get($order->invoice_data, 'companyName'),
            data_get($order->invoice_data, 'company_name'),
            data_get($order->invoice_data, 'name'),
            $localInvoiceCompany,
        ]);
        $invoiceNip = $this->firstFilled([
            data_get($order->raw_payload, 'invoice.company.taxId'),
            data_get($order->raw_payload, 'invoice.taxId'),
            data_get($order->raw_payload, 'invoice.tax_id'),
            data_get($order->raw_payload, 'invoice.nip'),
            data_get($order->raw_payload, 'invoice.address.taxId'),
            data_get($order->raw_payload, 'invoice.address.nip'),
            data_get($order->invoice_data, 'company.taxId'),
            data_get($order->invoice_data, 'taxId'),
            data_get($order->invoice_data, 'tax_id'),
            data_get($order->invoice_data, 'nip'),
            $localInvoiceNip,
        ]);

        $positiveFields = [];
        foreach ($this->positiveInvoiceIndicators($order) as $field => $value) {
            if ($this->isPositiveInvoiceValue($value)) {
                $positiveFields[] = $field;
            }
        }

        foreach ($this->filledInvoiceFields($order) as $field => $value) {
            if ($this->isFilledInvoiceValue($value)) {
                $positiveFields[] = $field;
            }
        }

        if (filled($localInvoiceCompany)) {
            $positiveFields[] = 'orders.company_name';
        }

        if (filled($localInvoiceNip)) {
            $positiveFields[] = 'orders.nip';
        }

        $positiveFields = array_values(array_unique($positiveFields));
        $hasInvoice = $positiveFields !== [];

        return [
            'has_invoice' => $hasInvoice,
            'lines' => $hasInvoice
                ? array_values(array_filter(['FAKTURA', $invoiceCompany, $invoiceNip ? 'NIP: '.$invoiceNip : null], fn ($value) => filled($value)))
                : ['BEZ FAKTURY'],
            'fields' => $positiveFields,
        ];
    }

    /** @param array<int, mixed> $values */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (filled($value) && is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function positiveInvoiceIndicators(Order $order): array
    {
        return [
            'raw_payload.invoice.required' => data_get($order->raw_payload, 'invoice.required'),
            'invoice_data.required' => data_get($order->invoice_data, 'required'),
            'raw_payload.invoice.type' => data_get($order->raw_payload, 'invoice.type'),
            'invoice_data.type' => data_get($order->invoice_data, 'type'),
        ];
    }

    /** @return array<string, mixed> */
    private function filledInvoiceFields(Order $order): array
    {
        return [
            'raw_payload.invoice.address' => data_get($order->raw_payload, 'invoice.address'),
            'raw_payload.invoice.company' => data_get($order->raw_payload, 'invoice.company'),
            'raw_payload.invoice.companyName' => data_get($order->raw_payload, 'invoice.companyName'),
            'raw_payload.invoice.taxId' => data_get($order->raw_payload, 'invoice.taxId'),
            'raw_payload.invoice.nip' => data_get($order->raw_payload, 'invoice.nip'),
            'invoice_data.address' => data_get($order->invoice_data, 'address'),
            'invoice_data.company' => data_get($order->invoice_data, 'company'),
            'invoice_data.companyName' => data_get($order->invoice_data, 'companyName'),
            'invoice_data.company_name' => data_get($order->invoice_data, 'company_name'),
            'invoice_data.taxId' => data_get($order->invoice_data, 'taxId'),
            'invoice_data.nip' => data_get($order->invoice_data, 'nip'),
        ];
    }

    private function isPositiveInvoiceValue(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        if (! is_scalar($value) || ! filled($value)) {
            return false;
        }

        $normalized = Str::lower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'tak', 'required', 'requested', 'invoice', 'vat'], true);
    }

    private function isFilledInvoiceValue(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->flatten()->contains(fn ($item) => filled($item));
        }

        return filled($value);
    }
}
