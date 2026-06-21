<?php

namespace App\Services\Marketplace;

class AllegroOfferExtractor
{
    /** @return array<int, array<string, mixed>> */
    public function extract(mixed $payload): array
    {
        $data = $this->normalizePayload($payload);
        if ($data === []) return [];

        $listing = $this->primaryListing($data);

        return $listing === null ? [] : [$listing];
    }

    /** @return array<int, string> */
    public function knownKeys(): array
    {
        return [
            'legacy_payload_json._allegro_offer_id',
            'woo_product.allegro_offer_id',
            'legacy_payload_json._allegro_status',
            'legacy_payload_json._allegro_category_id',
            'legacy_payload_json._allegro_currency',
            'legacy_payload_json._allegro_imported_at',
            'legacy_payload_json._allegro_parameters',
            'legacy_payload_json._allegro_offer_url',
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    private function primaryListing(array $data): ?array
    {
        $legacy = data_get($data, 'legacy_payload_json');
        $legacy = is_array($legacy) ? $legacy : [];

        $offerId = $this->clean(data_get($data, 'legacy_payload_json._allegro_offer_id'))
            ?? $this->clean(data_get($data, 'woo_product.allegro_offer_id'))
            ?? $this->clean($data['_allegro_offer_id'] ?? null);

        if ($offerId === null) return null;

        $canonicalUrl = $this->canonicalUrl($offerId);
        $currency = $this->clean(data_get($data, 'legacy_payload_json._allegro_currency')) ?? 'PLN';

        return [
            'kind' => 'primary',
            'offer_id' => $offerId,
            'url' => $canonicalUrl,
            'canonical_url' => $canonicalUrl,
            'status' => $this->clean(data_get($data, 'legacy_payload_json._allegro_status')) ?? 'imported',
            'category_id' => $this->clean(data_get($data, 'legacy_payload_json._allegro_category_id')),
            'currency' => $currency,
            'imported_at' => $this->clean(data_get($data, 'legacy_payload_json._allegro_imported_at')),
            'parameters' => data_get($data, 'legacy_payload_json._allegro_parameters'),
            'legacy_offer_url' => $this->clean(data_get($data, 'legacy_payload_json._allegro_offer_url')),
            'source_marketplace' => 'allegro',
            'source_account' => 'allegro_main',
            'source_channel' => 'allegro_main',
            'account_code' => 'allegro_main',
            'account_name' => 'Allegro main',
            'source_keys' => array_keys($legacy),
        ];
    }

    private function canonicalUrl(string $offerId): string
    {
        return 'https://allegro.pl/oferta/'.$offerId;
    }

    /** @return array<string, mixed> */
    private function normalizePayload(mixed $payload): array
    {
        if (is_array($payload)) return $payload;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function clean(mixed $value): ?string
    {
        if (is_array($value) || is_object($value) || $value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
