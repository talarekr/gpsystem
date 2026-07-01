<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use InvalidArgumentException;

class ManualMarketplaceLinkMappingService
{
    /**
     * @return array{listing: MarketplaceListing, marketplace: string, external_id: string, url: string, action: string, mapping_ready: bool, marketplace_write: bool, sync_triggered: bool}
     */
    public function save(Part $part, string $marketplace, string $url): array
    {
        $marketplace = strtolower(trim($marketplace));
        $url = trim($url);

        if (! in_array($marketplace, ['allegro', 'ovoko'], true)) {
            throw new InvalidArgumentException('unsupported_marketplace');
        }

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('invalid_url');
        }

        $externalId = $marketplace === 'allegro'
            ? $this->parseAllegroOfferId($url)
            : $this->parseOvokoPartId($url);

        if ($externalId === null) {
            throw new InvalidArgumentException('invalid_'.$marketplace.'_url');
        }

        $listing = MarketplaceListing::query()
            ->where('part_id', $part->getKey())
            ->whereIn('marketplace', $marketplace === 'allegro' ? ['allegro', 'allegro_main'] : ['ovoko'])
            ->orderByDesc('id')
            ->first();

        if ($listing) {
            $existingId = $this->listingExternalId($listing);

            if ($existingId !== null && $existingId !== $externalId) {
                throw new ManualMarketplaceMappingConflictException($existingId, $externalId);
            }

            $listing->forceFill($this->attributes($part, $marketplace, $externalId, $url, $listing->raw_payload ?? []))->save();

            return $this->result($listing, $marketplace, $externalId, $url, 'updated');
        }

        $account = MarketplaceAccount::query()->firstOrCreate(
            ['code' => $marketplace === 'allegro' ? 'allegro_main' : 'ovoko_main'],
            ['marketplace' => $marketplace, 'name' => $marketplace === 'allegro' ? 'Allegro main' : 'Ovoko main', 'status' => 'active']
        );

        $listing = MarketplaceListing::query()->create($this->attributes($part, $marketplace, $externalId, $url, []) + [
            'marketplace_account_id' => $account->id,
            'part_id' => $part->getKey(),
            'marketplace' => $marketplace,
        ]);

        return $this->result($listing, $marketplace, $externalId, $url, 'created');
    }

    /**
     * @return array{listing: MarketplaceListing, marketplace: string, external_id: string, url: string, action: string, mapping_ready: bool, marketplace_write: bool, sync_triggered: bool}
     */
    private function result(MarketplaceListing $listing, string $marketplace, string $externalId, string $url, string $action): array
    {
        return [
            'listing' => $listing,
            'marketplace' => $marketplace,
            'external_id' => $externalId,
            'url' => $url,
            'action' => $action,
            'mapping_ready' => true,
            'marketplace_write' => false,
            'sync_triggered' => false,
        ];
    }

    public function parseAllegroOfferId(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('/(?:^|[-\/])(\d+)\/?$/', $path, $matches) ? $matches[1] : null;
    }

    public function parseOvokoPartId(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('/(?:^|\/)hgf(\d+)(?:-|\/|$)/i', $path, $matches) ? $matches[1] : null;
    }

    /** @param array<string, mixed> $rawPayload @return array<string, mixed> */
    private function attributes(Part $part, string $marketplace, string $externalId, string $url, array $rawPayload): array
    {
        $rawPayload['manual_mapping'] = ['source' => 'admin_manual_link', 'url' => $url, 'mapped_at' => now()->toISOString()];
        if ($marketplace === 'ovoko') {
            $rawPayload['ovoko_part_id'] = $externalId;
        }

        return [
            'external_offer_id' => $externalId,
            'external_listing_id' => $externalId,
            'sku' => $part->sku,
            'title' => $part->name,
            'price' => is_numeric($marketplace === 'allegro' ? $part->allegro_price : $part->ovoko_price) ? (float) ($marketplace === 'allegro' ? $part->allegro_price : $part->ovoko_price) : null,
            'quantity' => is_numeric($part->quantity) ? (int) $part->quantity : null,
            'currency' => $part->currency ?: 'PLN',
            'status' => $marketplace === 'allegro' ? 'ACTIVE' : 'imported',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
            'match_confidence' => 100,
            'match_reason' => 'admin_manual_link_mapping',
            'url' => $url,
            'raw_payload' => $rawPayload,
            'last_error' => null,
            'last_synced_at' => now(),
            'last_api_status' => $marketplace === 'allegro' ? 'ACTIVE' : null,
        ];
    }

    private function listingExternalId(MarketplaceListing $listing): ?string
    {
        foreach ([$listing->external_offer_id, $listing->external_listing_id] as $value) {
            $id = trim((string) ($value ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }
}
