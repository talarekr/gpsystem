<?php

namespace App\Services\Marketplace;

class EbayListingExtractor
{
    private const CHANNELS = [
        'ebay_de' => [
            'marketplace_url' => 'https://www.ebay.de/itm/',
            'url_keys' => ['_wei_ebay_public_url'],
            'keys' => [
                'listing_id' => '_wei_ebay_listing_id',
                'item_id' => '_wei_ebay_item_id',
                'offer_id' => '_wei_ebay_offer_id',
                'inventory_id' => '_wei_ebay_inventory_id',
                'inventory_item_id' => '_wei_ebay_inventory_item_id',
                'sku' => '_wei_ebay_sku',
                'public_url' => '_wei_ebay_public_url',
                'listing_status' => '_wei_ebay_listing_status',
                'last_sync_status' => '_wei_ebay_last_sync_status',
                'last_publish_at' => '_wei_ebay_last_publish_at',
                'last_export_at' => '_wei_ebay_last_export_at',
                'inventory_sku' => '_wei_ebay_inventory_sku',
            ],
        ],
        'ebay_fr' => [
            'marketplace_url' => 'https://www.ebay.fr/itm/',
            'url_keys' => ['_wei_fr_ebay_public_url', '_wei_fr_ebay_listing_url'],
            'keys' => [
                'listing_id' => '_wei_fr_ebay_listing_id',
                'item_id' => '_wei_fr_ebay_item_id',
                'offer_id' => '_wei_fr_ebay_offer_id',
                'inventory_id' => '_wei_fr_ebay_inventory_id',
                'inventory_item_id' => '_wei_fr_ebay_inventory_item_id',
                'sku' => '_wei_fr_ebay_sku',
                'public_url' => '_wei_fr_ebay_public_url',
                'listing_url' => '_wei_fr_ebay_listing_url',
                'listing_status' => '_wei_fr_ebay_listing_status',
                'last_sync_status' => '_wei_fr_ebay_last_sync_status',
                'last_publish_at' => '_wei_fr_ebay_last_publish_at',
                'last_export_at' => '_wei_fr_ebay_last_export_at',
                'marketplace' => '_wei_fr_ebay_marketplace',
            ],
        ],
    ];

    public function extract(mixed $legacyPayload, string $channel): ?array
    {
        $config = self::CHANNELS[$channel] ?? null;
        if ($config === null) return null;
        $payload = $this->normalizePayload($legacyPayload);
        $found = [];
        foreach ($config['keys'] as $name => $key) {
            $value = $this->findValue($payload, $key);
            if ($value !== null && trim((string) $value) !== '') $found[$name] = trim((string) $value);
        }
        $identifier = $this->first($found, ['listing_id', 'item_id', 'offer_id', 'inventory_item_id', 'inventory_id', 'sku']);
        if ($identifier === null) return null;
        $url = null;
        foreach ($config['url_keys'] as $urlKey) {
            $candidate = $this->findValue($payload, $urlKey);
            if ($this->isEbayUrl($candidate)) { $url = trim((string) $candidate); break; }
        }
        $urlId = $found['item_id'] ?? $found['listing_id'] ?? null;
        if ($url === null && $urlId !== null) $url = $config['marketplace_url'].$urlId;
        return ['channel'=>$channel,'external_offer_id'=>$identifier,'url'=>$url,'fields'=>$found,'source_keys'=>$config['keys']];
    }

    public function channels(): array { return array_keys(self::CHANNELS); }

    private function first(array $values, array $keys): ?string
    {
        foreach ($keys as $key) if (($values[$key] ?? '') !== '') return $values[$key];
        return null;
    }

    private function isEbayUrl(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('#^https?://([^/]+\.)?ebay\.[a-z.]+/#i', trim($value));
    }

    private function normalizePayload(mixed $payload): mixed
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            return json_last_error() === JSON_ERROR_NONE ? $this->normalizePayload($decoded) : $payload;
        }
        if (is_array($payload)) {
            return array_map(fn ($value) => $this->normalizePayload($value), $payload);
        }
        return $payload;
    }

    private function findValue(mixed $payload, string $key): mixed
    {
        if (is_array($payload)) {
            if (array_key_exists($key, $payload)) return $payload[$key];
            foreach (['legacy_payload_json', 'meta', 'metadata', 'custom_fields'] as $nested) {
                if (array_key_exists($nested, $payload)) {
                    $value = $this->findValue($payload[$nested], $key);
                    if ($value !== null) return $value;
                }
            }
            foreach ($payload as $value) {
                $found = $this->findValue($value, $key);
                if ($found !== null) return $found;
            }
        }
        if (is_string($payload) && preg_match('/["\']'.preg_quote($key, '/').'["\']\s*[:=]\s*["\']?([^"\'",;\}\]\s]+)/i', $payload, $m)) return $m[1];
        return null;
    }
}
