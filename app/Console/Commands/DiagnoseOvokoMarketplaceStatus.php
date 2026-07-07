<?php

namespace App\Console\Commands;

use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DiagnoseOvokoMarketplaceStatus extends Command
{
    protected $signature = 'marketplace:diagnose-ovoko-status
        {--part-id=* : Restrict diagnostics to specific local part IDs}
        {--limit=10 : Maximum number of candidate parts to inspect}
        {--json : Print full rows as JSON instead of a compact table}';

    protected $description = 'Read-only diagnostics for Ovoko parts that have a link but resolve to an inactive admin icon.';

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
            ->with(['marketplaceListings' => fn ($query) => $query->where('marketplace', 'ovoko')->orderBy('id')])
            ->where('status', 'ready')
            ->where('quantity', '>', 0)
            ->whereHas('marketplaceListings', fn ($query) => $query
                ->where('marketplace', 'ovoko')
                ->where(fn ($query) => $query
                    ->whereNotNull('url')
                    ->orWhereNotNull('external_offer_id')
                    ->orWhereNotNull('external_listing_id')));

        if ($partIds->isNotEmpty()) {
            $query->whereIn('id', $partIds->all());
        }

        $rows = [];

        $query->orderBy('id')->chunkById(200, function ($parts) use (&$rows, $resolver, $limit): bool {
            foreach ($parts as $part) {
                $ovokoRow = collect($resolver->rowsForPart($part))->firstWhere('key', 'ovoko');

                if (! $ovokoRow || ! ($ovokoRow['has_link'] ?? false) || ($ovokoRow['is_active'] ?? false)) {
                    continue;
                }

                $listing = $this->preferredDiagnosticListing($part->marketplaceListings);

                $rows[] = [
                    'part.id' => $part->id,
                    'part.status' => $part->status,
                    'part.quantity' => $part->quantity,
                    'part.admin_local_availability' => $part->adminLocalAvailability(),
                    'marketplace_listings.id' => $listing?->id,
                    'marketplace_listings.status' => $listing?->status,
                    'marketplace_listings.sync_status' => $listing?->sync_status,
                    'marketplace_listings.match_status' => $listing?->match_status,
                    'marketplace_listings.last_api_status' => $listing?->last_api_status,
                    'marketplace_listings.last_error' => $listing?->last_error,
                    'marketplace_listings.external_offer_id' => $listing?->external_offer_id,
                    'marketplace_listings.external_listing_id' => $listing?->external_listing_id,
                    'marketplace_listings.url' => $listing?->url,
                    'resolved.has_link' => (bool) $ovokoRow['has_link'],
                    'resolved.is_active' => (bool) $ovokoRow['is_active'],
                    'resolved.icon' => $ovokoRow['icon'] ?? null,
                    'resolved.reason' => $ovokoRow['reason'] ?? null,
                ];

                if (count($rows) >= $limit) {
                    return false;
                }
            }

            return true;
        });

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No ready, in-stock Ovoko parts with a resolved link and inactive icon were found.');

            return self::SUCCESS;
        }

        $this->table(array_keys($rows[0]), $rows);

        return self::SUCCESS;
    }

    /**
     * @param \Illuminate\Support\Collection<int, MarketplaceListing> $listings
     */
    private function preferredDiagnosticListing($listings): ?MarketplaceListing
    {
        return $listings
            ->sortByDesc(fn (MarketplaceListing $listing): int => ($listing->external_offer_id || $listing->external_listing_id || $listing->url) ? 1 : 0)
            ->first();
    }
}
