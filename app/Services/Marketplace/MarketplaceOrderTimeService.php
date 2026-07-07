<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Carbon;
use Throwable;

class MarketplaceOrderTimeService
{
    public const LOCAL_TIMEZONE = 'Europe/Warsaw';

    public function marketplaceUtcToLocalStorage(mixed $value): ?string
    {
        $utc = $this->parseMarketplaceUtc($value);

        return $utc?->copy()->timezone(self::LOCAL_TIMEZONE)->format('Y-m-d H:i:s');
    }

    public function marketplaceUtcIso(mixed $value): ?string
    {
        $utc = $this->parseMarketplaceUtc($value);

        return $utc?->format('Y-m-d\TH:i:s.v\Z');
    }

    public function localDisplay(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public function marketplaceUtcDiagnostics(mixed $value): array
    {
        $utc = $this->parseMarketplaceUtc($value);

        return [
            'raw_timestamp' => is_scalar($value) ? (string) $value : null,
            'parsed_utc' => $utc?->format('Y-m-d\TH:i:s.v\Z'),
            'displayed_timezone' => self::LOCAL_TIMEZONE,
            'displayed_at' => $utc?->copy()->timezone(self::LOCAL_TIMEZONE)->format('Y-m-d H:i:s'),
            'source_assumption' => 'marketplace_order_timestamp_is_utc',
        ];
    }

    public function parseMarketplaceUtc(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
