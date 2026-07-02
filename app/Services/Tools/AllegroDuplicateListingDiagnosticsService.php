<?php

namespace App\Services\Tools;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AllegroDuplicateListingDiagnosticsService
{
    /** @return array<string, mixed> */
    public function report(?int $partId = null): array
    {
        if (! Schema::hasTable('marketplace_listings')) {
            return ['ok' => false, 'write' => false, 'blockers' => ['Missing marketplace_listings table.']];
        }

        return $partId ? $this->partReport($partId) : $this->allDuplicatesReport();
    }

    /** @return array<string, mixed> */
    private function partReport(int $partId): array
    {
        $listings = $this->baseListingsQuery()->where('part_id', $partId)->orderBy('id')->get();
        $nonEnded = $listings->filter(fn (MarketplaceListing $listing): bool => $this->isNonEndedNonFailed($listing));

        return [
            'ok' => true,
            'write' => false,
            'diagnostic' => 'allegro_duplicate_check',
            'part_id' => $partId,
            'local_marketplace_listings_count' => $listings->count(),
            'non_ended_non_failed_count' => $nonEnded->count(),
            'has_duplicate_risk' => $nonEnded->count() > 1,
            'duplicate_listing_ids' => $nonEnded->count() > 1 ? $nonEnded->pluck('id')->values()->all() : [],
            'listings' => $listings->map(fn (MarketplaceListing $listing): array => $this->listingRow($listing, $nonEnded))->values()->all(),
            'safety' => 'Read-only local diagnostics only. No Allegro cleanup/end/relist/publish API calls are performed.',
        ];
    }

    /** @return array<string, mixed> */
    private function allDuplicatesReport(): array
    {
        $rows = $this->baseListingsQuery()->orderBy('part_id')->orderBy('id')->get()->groupBy('part_id')
            ->map(function (Collection $listings, mixed $partId): ?array {
                if ($partId === null || $partId === '') return null;
                $nonEnded = $listings->filter(fn (MarketplaceListing $listing): bool => $this->isNonEndedNonFailed($listing));
                if ($nonEnded->count() <= 1) return null;

                return [
                    'part_id' => (int) $partId,
                    'non_ended_non_failed_count' => $nonEnded->count(),
                    'duplicate_listing_ids' => $nonEnded->pluck('id')->values()->all(),
                    'offer_ids' => $nonEnded->map(fn (MarketplaceListing $listing): ?string => $this->offerId($listing))->filter()->values()->all(),
                    'statuses' => $nonEnded->pluck('status')->values()->all(),
                    'detail_url' => url('/admin/tools/marketplace/allegro-duplicate-check?part_id='.(int) $partId),
                ];
            })->filter()->values();

        return ['ok' => true, 'write' => false, 'diagnostic' => 'allegro_duplicate_check_all', 'duplicate_parts_count' => $rows->count(), 'parts' => $rows->all(), 'safety' => 'Read-only local diagnostics only. No Allegro cleanup/end/relist/publish API calls are performed.'];
    }

    private function baseListingsQuery()
    {
        return MarketplaceListing::query()->where('marketplace', 'allegro');
    }

    /** @return array<string, mixed> */
    private function listingRow(MarketplaceListing $listing, Collection $duplicateSet): array
    {
        $logs = Schema::hasTable('marketplace_sync_logs') ? MarketplaceSyncLog::query()->where('marketplace', 'allegro')->where(function ($q) use ($listing): void {
            $q->where('marketplace_listing_id', $listing->id)->orWhere(function ($q) use ($listing): void {
                $q->where('part_id', $listing->part_id)->where('external_id', $this->offerId($listing));
            });
        })->orderByDesc('created_at')->limit(20)->get() : collect();

        return [
            'listing_id' => $listing->id,
            'part_id' => $listing->part_id,
            'offer_id' => $this->offerId($listing),
            'external_offer_id' => $listing->external_offer_id,
            'external_listing_id' => $listing->external_listing_id,
            'sku' => $listing->sku,
            'title' => $listing->title,
            'status' => $listing->status,
            'last_api_status' => $listing->last_api_status,
            'created_at' => optional($listing->created_at)->toISOString(),
            'updated_at' => optional($listing->updated_at)->toISOString(),
            'looks_like_duplicate' => $duplicateSet->contains('id', $listing->id) && $duplicateSet->count() > 1,
            'manually_ended_or_removed_locally' => ! $this->isNonEndedNonFailed($listing),
            'url' => $listing->url,
            'sync_logs' => $logs->map(fn (MarketplaceSyncLog $log): array => ['id' => $log->id, 'action' => $log->action, 'status' => $log->status, 'http_status' => $log->http_status, 'correlation_id' => $log->request_id, 'external_id' => $log->external_id, 'created_at' => optional($log->created_at)->toISOString()])->values()->all(),
        ];
    }

    private function offerId(MarketplaceListing $listing): ?string
    {
        return filled($listing->external_offer_id) ? (string) $listing->external_offer_id : (filled($listing->external_listing_id) ? (string) $listing->external_listing_id : null);
    }

    private function isNonEndedNonFailed(MarketplaceListing $listing): bool
    {
        $status = strtolower((string) $listing->status);
        $apiStatus = strtolower((string) $listing->last_api_status);
        return ! in_array($status, ['ended', 'failed', 'deleted', 'archived', 'cancelled'], true)
            && ! in_array($apiStatus, ['ended', 'failed', 'deleted', 'archived', 'not_found'], true)
            && filled($this->offerId($listing));
    }
}
