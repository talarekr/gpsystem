<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use InvalidArgumentException;

class ManualMarketplaceListingMapper
{
    /** @return array{marketplace:string,external_id:string,url:string} */
    public function parse(string $marketplace, string $url): array
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($marketplace === 'allegro') {
            if (! in_array($host, ['allegro.pl', 'www.allegro.pl'], true)) {
                throw new InvalidArgumentException('Allegro: dozwolona jest tylko domena allegro.pl.');
            }
            if (! preg_match('/(?:^|[-\/])(\d+)\/?$/', $path, $matches)) {
                throw new InvalidArgumentException('Allegro: nie udało się odczytać liczbowego ID oferty z końca URL.');
            }

            return ['marketplace' => 'allegro', 'external_id' => $matches[1], 'url' => $url];
        }

        if (! in_array($host, ['ovoko.pl', 'www.ovoko.pl'], true)) {
            throw new InvalidArgumentException('Ovoko: dozwolona jest tylko domena ovoko.pl.');
        }

        foreach (explode('/', trim($path, '/')) as $segment) {
            if (preg_match('/^hgf(\d+)(?:-|$)/i', $segment, $matches)) {
                return ['marketplace' => 'ovoko', 'external_id' => $matches[1], 'url' => $url];
            }
        }

        throw new InvalidArgumentException('Ovoko: ścieżka musi zawierać segment hgf z liczbowym ID.');
    }

    /** @return array<string,mixed> */
    public function map(Part $part, string $marketplace, string $url): array
    {
        $parsed = $this->parse($marketplace, $url);
        $marketplace = $parsed['marketplace'];
        $externalId = $parsed['external_id'];

        $listing = MarketplaceListing::query()
            ->where('part_id', $part->id)
            ->whereIn('marketplace', $marketplace === 'allegro' ? ['allegro', 'allegro_main'] : [$marketplace])
            ->first();

        if ($listing) {
            $existingId = $this->externalId($listing);
            if ($existingId !== null && $existingId !== $externalId) {
                $this->log($marketplace, $part->id, $listing->id, 'conflict', $externalId, $parsed['url'], ['existing_external_id' => $existingId]);

                return $this->result($part->id, $marketplace, $externalId, $parsed['url'], 'conflict', [
                    'error' => 'existing_mapping_conflict',
                    'existing_external_id' => $existingId,
                    'new_external_id' => $externalId,
                ]);
            }

            $listing->forceFill($this->listingAttributes($marketplace, $externalId, $parsed['url'], $listing->raw_payload ?? []))->save();
            $this->log($marketplace, $part->id, $listing->id, 'updated_url_only', $externalId, $parsed['url']);

            return $this->result($part->id, $marketplace, $externalId, $parsed['url'], 'updated_url_only');
        }

        $listing = MarketplaceListing::query()->create($this->listingAttributes($marketplace, $externalId, $parsed['url'], []) + [
            'part_id' => $part->id,
            'marketplace' => $marketplace,
            'marketplace_account_id' => MarketplaceAccount::query()->where('marketplace', $marketplace)->value('id'),
            'currency' => $part->currency ?: 'PLN',
            'status' => $marketplace === 'allegro' ? 'ACTIVE' : 'published',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
            'match_confidence' => 100,
            'match_reason' => 'manual_marketplace_link_mapping',
        ]);
        $this->log($marketplace, $part->id, $listing->id, 'created_mapping', $externalId, $parsed['url']);

        return $this->result($part->id, $marketplace, $externalId, $parsed['url'], 'created_mapping');
    }

    private function normalizeMarketplace(string $marketplace): string
    {
        return match ($marketplace) {
            'allegro', 'allegro_main' => 'allegro',
            'ovoko' => 'ovoko',
            default => throw new InvalidArgumentException('Obsługiwane są tylko kanały Allegro i Ovoko.'),
        };
    }

    /** @return array<string,mixed> */
    private function listingAttributes(string $marketplace, string $externalId, string $url, array $rawPayload): array
    {
        $rawPayload['manual_mapping'] = ['source' => 'admin_pasted_url', 'marketplace_write' => false, 'sync_triggered' => false];
        if ($marketplace === 'ovoko') $rawPayload['ovoko_part_id'] = $externalId;

        return ['external_offer_id' => $externalId, 'external_listing_id' => $externalId, 'url' => $url, 'raw_payload' => $rawPayload];
    }

    private function externalId(MarketplaceListing $listing): ?string
    {
        $id = trim((string) ($listing->external_offer_id ?: $listing->external_listing_id));
        return $id === '' ? null : $id;
    }

    /** @return array<string,mixed> */
    private function result(int $partId, string $marketplace, string $externalId, string $url, string $action, array $extra = []): array
    {
        return $extra + ['part_id' => $partId, 'marketplace' => $marketplace, 'extracted_external_id' => $externalId, 'url' => $url, 'action' => $action, 'marketplace_write' => false, 'sync_triggered' => false];
    }

    private function log(string $marketplace, int $partId, ?int $listingId, string $action, string $externalId, string $url, array $extra = []): void
    {
        MarketplaceSyncLog::query()->create(['marketplace' => $marketplace, 'marketplace_listing_id' => $listingId, 'part_id' => $partId, 'action' => 'manual_link_mapping:'.$action, 'status' => $action === 'conflict' ? 'blocked' : 'success', 'message' => $action, 'payload' => $extra + ['extracted_external_id' => $externalId, 'url' => $url, 'marketplace_write' => false, 'sync_triggered' => false], 'created_at' => now()]);
    }
}
