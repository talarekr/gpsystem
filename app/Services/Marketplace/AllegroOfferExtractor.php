<?php

namespace App\Services\Marketplace;

class AllegroOfferExtractor
{
    /** @return array<int, array<string, mixed>> */
    public function extract(mixed $payload): array
    {
        $data = $this->normalizePayload($payload);
        if ($data === []) return [];

        return array_values(array_filter([
            $this->listing($data, 'primary', '_allegro_offer_id', '_allegro_offer_url', '_allegro_status'),
            $this->listing($data, 'secondary', '_secondary_allegro_offer_id', '_secondary_allegro_offer_url', '_secondary_allegro_status'),
        ]));
    }

    /** @return array<int, string> */
    public function knownKeys(): array
    {
        return ['_allegro_offer_id', '_secondary_allegro_offer_id', '_allegro_offer_url', '_secondary_allegro_offer_url', '_allegro_status', '_secondary_allegro_status', '_source_marketplace', '_source_account', '_source_channel'];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    private function listing(array $data, string $kind, string $idKey, string $urlKey, string $statusKey): ?array
    {
        $offerId = $this->clean($data[$idKey] ?? null);
        if ($offerId === null) return null;

        $account = $this->clean($data['_source_account'] ?? null);
        $channel = $this->clean($data['_source_channel'] ?? null) ?? $kind;

        return [
            'kind' => $kind,
            'offer_id' => $offerId,
            'url' => $this->clean($data[$urlKey] ?? null),
            'status' => $this->clean($data[$statusKey] ?? null),
            'source_marketplace' => $this->clean($data['_source_marketplace'] ?? null),
            'source_account' => $account,
            'source_channel' => $channel,
            'account_code' => 'allegro_'.str($account ?: $channel ?: $kind)->slug('_')->toString(),
            'account_name' => 'Allegro '.($account ?: $channel ?: $kind),
        ];
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
