<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            throw new InvalidArgumentException('Nieobsługiwany marketplace: '.$marketplace.'.');
        }

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Podaj poprawny adres URL.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Link musi zaczynać się od http:// albo https://.');
        }

        $externalId = $marketplace === 'allegro'
            ? $this->parseAllegroOfferId($url)
            : $this->parseOvokoPartId($url);

        if ($externalId === null) {
            throw new InvalidArgumentException('Nie udało się odczytać ID oferty z linku '.ucfirst($marketplace).'. Sprawdź, czy wklejony adres prowadzi do konkretnej oferty.');
        }

        $listings = MarketplaceListing::query()
            ->where('part_id', $part->getKey())
            ->whereIn('marketplace', $marketplace === 'allegro' ? ['allegro', 'allegro_main'] : ['ovoko'])
            ->orderByDesc('id')
            ->get();

        $matchingListing = $listings->first(fn (MarketplaceListing $listing): bool => $this->listingExternalId($listing) === $externalId);
        if ($matchingListing) {
            $matchingListing->forceFill($this->attributes($part, $marketplace, $externalId, $url, $matchingListing->raw_payload ?? []))->save();

            return $this->result($matchingListing, $marketplace, $externalId, $url, 'updated');
        }

        $listing = $listings->first();
        if ($listing) {
            $existingId = $this->listingExternalId($listing);

            if ($existingId !== null && $existingId !== $externalId) {
                throw new ManualMarketplaceMappingConflictException($existingId, $externalId);
            }

            $listing->forceFill($this->attributes($part, $marketplace, $externalId, $url, $listing->raw_payload ?? []))->save();

            return $this->result($listing, $marketplace, $externalId, $url, 'updated');
        }

        $duplicate = $this->findExistingListingByExternalId($marketplace, $externalId, (int) $part->getKey());

        if ($duplicate && (int) $duplicate->part_id !== (int) $part->getKey()) {
            Log::warning('manual_marketplace_link_duplicate_external_id', [
                'part_id' => $part->getKey(),
                'marketplace' => $marketplace,
                'external_id' => $externalId,
                'existing_listing_id' => $duplicate->id,
                'existing_part_id' => $duplicate->part_id,
            ]);

            throw new ManualMarketplaceMappingConflictException(
                $this->listingExternalId($duplicate) ?? $externalId,
                $externalId,
                $duplicate->id,
                $duplicate->part_id,
            );
        }

        if ($duplicate) {
            $duplicate->forceFill($this->attributes($part, $marketplace, $externalId, $url, $duplicate->raw_payload ?? []) + [
                'part_id' => $part->getKey(),
            ])->save();

            return $this->result($duplicate, $marketplace, $externalId, $url, 'updated');
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

    /** @return array<string, mixed> */
    public function replaceDryRun(Part $part, string $marketplace, string $url): array
    {
        [$marketplace, $externalId, $url] = $this->validatedMappingInput($marketplace, $url);

        return $this->replacePayload($part, $marketplace, $externalId, $url);
    }

    /** @return array<string, mixed> */
    public function replace(Part $part, string $marketplace, string $url, bool $dryRun = false): array
    {
        [$marketplace, $externalId, $url] = $this->validatedMappingInput($marketplace, $url);
        $payload = $this->replacePayload($part, $marketplace, $externalId, $url);

        if ($dryRun) {
            return $payload;
        }

        DB::transaction(function () use ($part, $marketplace, $externalId, $url, &$payload): void {
            $rows = $this->listingQueryForPartMarketplace($part, $marketplace)->lockForUpdate()->get();
            $previousExternalId = $payload['previous_external_id'];
            $target = $rows->first(fn (MarketplaceListing $listing): bool => $this->listingExternalId($listing) === $externalId);

            if (! $target) {
                $account = MarketplaceAccount::query()->firstOrCreate(
                    ['code' => $marketplace === 'allegro' ? 'allegro_main' : 'ovoko_main'],
                    ['marketplace' => $marketplace, 'name' => $marketplace === 'allegro' ? 'Allegro main' : 'Ovoko main', 'status' => 'active']
                );
                $target = MarketplaceListing::query()->create(['marketplace' => $marketplace, 'marketplace_account_id' => $account->id, 'part_id' => $part->getKey()]);
            }

            foreach ($rows as $listing) {
                if (! $listing->is($target)) {
                    $listing->forceFill($this->archiveAttributes($listing, $previousExternalId, $externalId))->save();
                }
            }

            $rawPayload = is_array($target->raw_payload) ? $target->raw_payload : [];
            $rawPayload['manual_mapping_repair'] = [
                'source' => 'admin_manual_link_mapping_replace',
                'previous_external_id' => $previousExternalId,
                'new_external_id' => $externalId,
                'url' => $url,
                'repaired_at' => now()->toISOString(),
                'marketplace_write' => false,
                'sync_triggered' => false,
            ];

            $target->forceFill($this->attributes($part, $marketplace, $externalId, $url, $rawPayload) + ['part_id' => $part->getKey(), 'marketplace' => $marketplace])->save();
            $payload = array_merge($this->replacePayload($part->fresh(), $marketplace, $externalId, $url), ['updated_row_id' => $target->id, 'previous_external_id' => $previousExternalId]);
        });

        return $payload;
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

    /** @return array{0: string, 1: string, 2: string} */
    private function validatedMappingInput(string $marketplace, string $url): array
    {
        $marketplace = strtolower(trim($marketplace));
        $url = trim($url);

        if ($marketplace !== 'allegro') {
            throw new InvalidArgumentException('unsupported_marketplace');
        }

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('invalid_url');
        }

        $externalId = $this->parseAllegroOfferId($url);
        if ($externalId === null) {
            throw new InvalidArgumentException('invalid_allegro_url');
        }

        return [$marketplace, $externalId, $url];
    }

    /** @return array<string, mixed> */
    private function replacePayload(Part $part, string $marketplace, string $externalId, string $url): array
    {
        $rows = $this->listingQueryForPartMarketplace($part, $marketplace)->get();
        $active = $rows->filter(fn (MarketplaceListing $listing): bool => $this->isActiveMapping($listing))->values();
        $previousExternalId = $active->first() ? $this->listingExternalId($active->first()) : $this->listingExternalId($rows->first());

        return [
            'action' => 'replace_mapping',
            'part_id' => $part->getKey(),
            'marketplace' => $marketplace,
            'previous_external_id' => $previousExternalId,
            'new_external_id' => $externalId,
            'url' => $url,
            'current_active_local_mapped_listings' => $active->map(fn (MarketplaceListing $listing): array => $this->listingSnapshot($listing))->all(),
            'all_local_mapped_listings' => $rows->map(fn (MarketplaceListing $listing): array => $this->listingSnapshot($listing))->all(),
            'row_will_be_updated' => $active->first()?->id ?: $rows->first()?->id,
            'rows_will_be_archived' => $rows->filter(fn (MarketplaceListing $listing): bool => $this->listingExternalId($listing) !== $externalId && ! $listing->is($active->first()))->pluck('id')->values()->all(),
            'marketplace_write' => false,
            'sync_triggered' => false,
            'publish' => false,
            'relist' => false,
            'end' => false,
        ];
    }

    private function listingQueryForPartMarketplace(Part $part, string $marketplace): Builder
    {
        return MarketplaceListing::query()
            ->where('part_id', $part->getKey())
            ->whereIn('marketplace', $marketplace === 'allegro' ? ['allegro', 'allegro_main'] : [$marketplace])
            ->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    private function listingSnapshot(MarketplaceListing $listing): array
    {
        return [
            'id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'external_offer_id' => $listing->external_offer_id,
            'external_listing_id' => $listing->external_listing_id,
            'url' => $listing->url,
            'status' => $listing->status,
            'sync_status' => $listing->sync_status,
            'match_status' => $listing->match_status,
            'active_mapping' => $this->isActiveMapping($listing),
        ];
    }

    private function isActiveMapping(MarketplaceListing $listing): bool
    {
        return $this->listingExternalId($listing) !== null
            && in_array($listing->sync_status, ['mapped', 'imported'], true)
            && ! in_array($listing->status, ['ended', 'archived', 'replaced', 'inactive', 'deleted', 'not_found'], true)
            && ! in_array($listing->last_api_status, ['ended', 'archived', 'replaced', 'inactive', 'deleted', 'not_found', 'NOT_FOUND_IN_ACTIVE_API'], true);
    }

    /** @return array<string, mixed> */
    private function archiveAttributes(MarketplaceListing $listing, ?string $previousExternalId, string $newExternalId): array
    {
        $rawPayload = is_array($listing->raw_payload) ? $listing->raw_payload : [];
        $rawPayload['manual_mapping_repair_archive'] = [
            'previous_external_id' => $previousExternalId,
            'replaced_by_external_id' => $newExternalId,
            'archived_at' => now()->toISOString(),
            'marketplace_write' => false,
            'sync_triggered' => false,
        ];

        return [
            'status' => 'replaced',
            'sync_status' => 'archived',
            'match_status' => 'replaced',
            'match_reason' => 'admin_manual_link_mapping_replaced:previous_external_id='.$previousExternalId,
            'raw_payload' => $rawPayload,
            'last_api_status' => 'archived',
        ];
    }

    private function findExistingListingByExternalId(string $marketplace, string $externalId, ?int $ignorePartId = null): ?MarketplaceListing
    {
        return MarketplaceListing::query()
            ->whereIn('marketplace', $marketplace === 'allegro' ? ['allegro', 'allegro_main'] : ['ovoko'])
            ->where(function (Builder $query) use ($externalId): void {
                $query->where('external_offer_id', $externalId)
                    ->orWhere('external_listing_id', $externalId);
            })
            ->when($ignorePartId !== null, fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner->whereNull('part_id')->orWhere('part_id', '!=', $ignorePartId)))
            ->orderByDesc('id')
            ->first();
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
