<?php

namespace App\Services\Marketplace\PriceSync;

use App\Models\Part;
use App\Services\Marketplace\NbpExchangeRateService;

class PartPriceResolver
{
    public function __construct(private readonly PriceNormalizer $normalizer, private readonly NbpExchangeRateService $exchangeRates) {}

    /** @return array<string,mixed> */
    public function resolve(Part $part, string $channel): array
    {
        return match ($channel) {
            'allegro' => $this->plain($part->allegro_price, 'parts.allegro_price', 'PLN', 'PLN', 'parts.allegro_price'),
            'ovoko' => $this->plain($part->ovoko_price, 'parts.ovoko_price', 'PLN', strtoupper((string) ($part->currency ?: 'PLN')), 'parts.ovoko_price'),
            'ebay_de' => $this->ebayDe($part),
            default => ['ok' => false, 'blockers' => ['unsupported_channel']],
        };
    }

    /** @return array<string,mixed> */
    public function snapshot(Part $part): array
    {
        return collect(['allegro','ovoko','ebay_de'])->mapWithKeys(fn (string $c): array => [$c => $this->resolve($part, $c)])->all();
    }

    private function plain(mixed $value, string $field, string $sourceCurrency, string $marketplaceCurrency, string $source): array
    {
        $price = $this->normalizer->normalize($value);
        return ['ok' => true, 'source_value' => $value, 'source_field' => $field, 'source_currency' => $sourceCurrency, 'marketplace_price' => $price, 'marketplace_currency' => $marketplaceCurrency, 'price_source' => $source, 'conversion' => null, 'blockers' => []];
    }

    private function ebayDe(Part $part): array
    {
        $source = $this->normalizer->normalize($part->ebay_price);
        $rate = $this->exchangeRates->eurPln();
        $r = is_numeric($rate['rate'] ?? null) ? (float) $rate['rate'] : null;
        $eur = $source !== null && $r && $r > 0 ? $this->normalizer->normalize(((float) $source) / $r) : null;
        return ['ok' => $eur !== null, 'source_value' => $part->ebay_price, 'source_field' => 'parts.ebay_price', 'source_currency' => 'PLN', 'marketplace_price' => $eur, 'marketplace_currency' => 'EUR', 'price_source' => 'parts.ebay_price', 'conversion' => ['source' => $rate['source'] ?? 'NBP_TABLE_A', 'rate' => $r, 'effective_date' => $rate['effective_date'] ?? null, 'table_no' => $rate['table_no'] ?? null], 'blockers' => $eur === null ? ['missing_nbp_exchange_rate'] : []];
    }
}
