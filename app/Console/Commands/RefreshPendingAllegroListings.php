<?php

namespace App\Console\Commands;

use App\Models\MarketplaceListing;
use App\Services\Marketplace\AllegroListingStatusRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RefreshPendingAllegroListings extends Command
{
    protected $signature = 'allegro:refresh-pending-listings
        {--limit=50 : Maximum pending listings to refresh}
        {--older-than-minutes=2 : Only refresh publication_pending listings older than this many minutes}
        {--dry-run : Show candidates without calling Allegro API or writing local status}';

    protected $description = 'Fallback cron refresh for Allegro publication_pending listings with an external offer ID.';

    public function handle(AllegroListingStatusRefreshService $service): int
    {
        if (! Schema::hasTable('marketplace_listings')) {
            $this->error('Missing marketplace_listings table.');

            return self::FAILURE;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $olderThanMinutes = max(0, (int) $this->option('older-than-minutes'));
        $dryRun = (bool) $this->option('dry-run');

        $query = MarketplaceListing::query()
            ->where('marketplace', 'allegro')
            ->where('status', 'publication_pending')
            ->whereNotNull('external_offer_id')
            ->where('external_offer_id', '<>', '')
            ->when($olderThanMinutes > 0, fn ($query) => $query->where('updated_at', '<=', now()->subMinutes($olderThanMinutes)))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        $listings = $query->get();
        $rows = [];

        foreach ($listings as $listing) {
            $row = [
                'listing_id' => $listing->id,
                'part_id' => $listing->part_id,
                'offer_id' => $listing->external_offer_id,
                'before_status' => $listing->status,
                'before_last_api_status' => $listing->last_api_status,
            ];

            if (! $dryRun) {
                $result = $service->refresh($listing, null, true);
                $row += [
                    'ok' => (bool) ($result['ok'] ?? false),
                    'api_publication_status' => data_get($result, 'api.publication_status'),
                    'after_status' => data_get($result, 'after.status'),
                    'after_last_api_status' => data_get($result, 'after.last_api_status'),
                ];
            }

            $rows[] = $row;
        }

        $this->line(json_encode([
            'dry_run' => $dryRun,
            'filters' => ['marketplace' => 'allegro', 'status' => 'publication_pending', 'external_offer_id_required' => true, 'older_than_minutes' => $olderThanMinutes, 'limit' => $limit],
            'count' => count($rows),
            'rows' => $rows,
            'safety' => ['publish' => false, 'end_offers' => false, 'delete_links' => false, 'part_status_changed' => false],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
