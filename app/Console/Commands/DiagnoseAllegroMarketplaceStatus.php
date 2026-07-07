<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DiagnoseAllegroMarketplaceStatus extends Command
{
    protected $signature = 'marketplace:diagnose-allegro-status
        {--part-id=* : Restrict diagnostics to specific local part IDs}
        {--offer-id= : Explicit Allegro offer ID to check instead of the ID resolved from marketplace_listings}
        {--check-api : Read Allegro API product-offer status for the resolved offer ID}
        {--limit=10 : Maximum number of candidate parts to inspect}
        {--json : Print full rows as JSON instead of a compact table}';

    protected $description = 'Read-only diagnostics for Allegro parts, local listing rows, admin resolver output, and optional Allegro API offer status.';

    public function handle(PartMarketplaceStatusResolver $resolver): int
    {
        foreach (['parts', 'marketplace_listings'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing required table: {$table}");

                return self::FAILURE;
            }
        }

        $limit = max(1, (int) $this->option('limit'));
        $partIds = collect($this->option('part-id'))->filter()->map(fn ($id): int => (int) $id)->values();

        $query = Part::query()
            ->with(['marketplaceListings' => fn ($query) => $query->whereIn('marketplace', ['allegro', 'allegro_main'])->with('account')->orderBy('id')])
            ->orderBy('id');

        if ($partIds->isNotEmpty()) {
            $query->whereIn('id', $partIds->all());
        } else {
            $query->whereHas('marketplaceListings', fn ($query) => $query
                ->whereIn('marketplace', ['allegro', 'allegro_main'])
                ->where(fn ($query) => $query
                    ->whereNotNull('url')
                    ->orWhereNotNull('external_offer_id')
                    ->orWhereNotNull('external_listing_id')))
                ->limit($limit);
        }

        $rows = $query->get()->take($limit)->map(fn (Part $part): array => $this->diagnosePart($part, $resolver))->values()->all();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No Allegro marketplace diagnostics rows were found for the requested input.');

            return self::SUCCESS;
        }

        $this->table(array_keys($this->flattenForTable($rows[0])), array_map(fn (array $row): array => $this->flattenForTable($row), $rows));

        return self::SUCCESS;
    }

    private function diagnosePart(Part $part, PartMarketplaceStatusResolver $resolver): array
    {
        $listingRows = $part->marketplaceListings->map(fn (MarketplaceListing $listing): array => $this->listingRow($listing))->values()->all();
        $resolverRow = collect($resolver->rowsForPart($part))->firstWhere('key', 'allegro') ?: [];
        $offerId = trim((string) ($this->option('offer-id') ?: Arr::get($resolverRow, 'external_offer_id') ?: Arr::get($listingRows, '0.external_offer_id') ?: Arr::get($listingRows, '0.external_listing_id')));

        return [
            'part' => [
                'id' => $part->id,
                'sku' => $part->sku,
                'part_number' => $part->part_number,
                'status' => $part->status,
                'quantity' => $part->quantity,
                'adminLocalAvailability' => $part->adminLocalAvailability(),
            ],
            'marketplace_listings' => $listingRows,
            'resolver_allegro' => [
                'has_link' => (bool) ($resolverRow['has_link'] ?? false),
                'url' => $resolverRow['url'] ?? null,
                'is_active' => (bool) ($resolverRow['is_active'] ?? false),
                'icon' => $resolverRow['icon'] ?? null,
                'display_icon' => $resolverRow['display_icon'] ?? null,
                'reason' => $resolverRow['reason'] ?? null,
            ],
            'allegro_api' => $this->option('check-api') ? $this->apiOfferStatus($offerId) : ['checked' => false, 'offer_id' => $offerId ?: null],
        ];
    }

    private function listingRow(MarketplaceListing $listing): array
    {
        return [
            'id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'channel' => $listing->account?->code,
            'status' => $listing->status,
            'sync_status' => $listing->sync_status,
            'match_status' => $listing->match_status,
            'external_offer_id' => $listing->external_offer_id,
            'external_listing_id' => $listing->external_listing_id,
            'url' => $listing->url,
            'last_api_status' => $listing->last_api_status,
            'last_error' => $listing->last_error,
        ];
    }

    private function apiOfferStatus(?string $offerId): array
    {
        if (! filled($offerId)) {
            return ['checked' => true, 'exists' => false, 'offer_id' => null, 'error' => 'missing_offer_id'];
        }

        try {
            $account = MarketplaceAccount::query()->where('code', 'allegro_main')->first()
                ?: MarketplaceAccount::query()->where('marketplace', 'allegro')->first();
            $response = (new AllegroApiClient('allegro_main', $account))->productOffer($offerId);
            $json = $response['json'] ?? [];
            $publicationStatus = Arr::get($json, 'publication.status');
            $available = Arr::get($json, 'stock.available');

            return [
                'checked' => true,
                'offer_id' => $offerId,
                'exists' => (bool) ($response['ok'] ?? false),
                'http_status' => $response['http_status'] ?? null,
                'publication_status' => $publicationStatus,
                'stock_available' => $available,
                'is_active' => strtoupper((string) $publicationStatus) === 'ACTIVE',
                'is_ended' => strtoupper((string) $publicationStatus) === 'ENDED',
                'selling_mode' => Arr::get($json, 'sellingMode'),
                'request_id' => $response['request_id'] ?? null,
                'error' => ($response['ok'] ?? false) ? null : ($json['message'] ?? $json['error'] ?? 'allegro_api_lookup_failed'),
            ];
        } catch (Throwable $exception) {
            return ['checked' => true, 'offer_id' => $offerId, 'exists' => false, 'error' => $exception::class.': '.$exception->getMessage()];
        }
    }

    private function flattenForTable(array $row): array
    {
        return [
            'part.id' => Arr::get($row, 'part.id'),
            'part.status' => Arr::get($row, 'part.status'),
            'part.quantity' => Arr::get($row, 'part.quantity'),
            'part.adminLocalAvailability' => Arr::get($row, 'part.adminLocalAvailability'),
            'listing.count' => count($row['marketplace_listings'] ?? []),
            'listing.status' => Arr::get($row, 'marketplace_listings.0.status'),
            'listing.sync_status' => Arr::get($row, 'marketplace_listings.0.sync_status'),
            'listing.match_status' => Arr::get($row, 'marketplace_listings.0.match_status'),
            'listing.external_offer_id' => Arr::get($row, 'marketplace_listings.0.external_offer_id'),
            'listing.external_listing_id' => Arr::get($row, 'marketplace_listings.0.external_listing_id'),
            'listing.url' => Arr::get($row, 'marketplace_listings.0.url'),
            'listing.last_api_status' => Arr::get($row, 'marketplace_listings.0.last_api_status'),
            'listing.last_error' => Arr::get($row, 'marketplace_listings.0.last_error'),
            'resolver.has_link' => Arr::get($row, 'resolver_allegro.has_link'),
            'resolver.is_active' => Arr::get($row, 'resolver_allegro.is_active'),
            'resolver.display_icon' => Arr::get($row, 'resolver_allegro.display_icon'),
            'resolver.reason' => Arr::get($row, 'resolver_allegro.reason'),
            'api.checked' => Arr::get($row, 'allegro_api.checked'),
            'api.exists' => Arr::get($row, 'allegro_api.exists'),
            'api.publication_status' => Arr::get($row, 'allegro_api.publication_status'),
            'api.stock_available' => Arr::get($row, 'allegro_api.stock_available'),
            'api.error' => Arr::get($row, 'allegro_api.error'),
        ];
    }
}
