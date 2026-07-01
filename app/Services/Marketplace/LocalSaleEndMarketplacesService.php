<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\Api\EbayApiClient;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class LocalSaleEndMarketplacesService
{
    private const ACTION = 'local_sale_end_listing';
    private const ENDED_STATUSES = ['ended', 'inactive', 'sold', 'archived', 'deleted', 'completed', 'closed'];

    public function dryRun(Part $part): array
    {
        return $this->buildSummary($part, false);
    }

    public function apply(Part $part): array
    {
        $summary = DB::transaction(function () use ($part): array {
            /** @var Part $locked */
            $locked = Part::query()->whereKey($part->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => 'sold', 'quantity' => 0, 'is_visible_storefront' => false, 'needs_listing' => false])->save();

            return $this->buildSummary($locked->fresh(), true);
        });

        foreach ($summary['marketplace_listings'] as $item) {
            if (($item['action'] ?? null) !== 'would_end') {
                $summary['skipped_marketplaces'][] = $item;
                continue;
            }

            $listing = MarketplaceListing::query()->find($item['marketplace_listing_id']);
            if (! $listing || $this->isEnded($listing)) {
                $summary['skipped_marketplaces'][] = $item + ['reason' => 'already_ended_or_missing'];
                continue;
            }

            $result = $this->endListing($listing, (string) $item['external_id']);
            $this->writeLog($listing, (string) $item['external_id'], $result);

            if ($result['ok']) {
                $listing->forceFill(['status' => 'ended', 'quantity' => 0, 'sync_status' => 'ended', 'last_api_status' => 'ended', 'last_error' => null, 'last_synced_at' => now()])->save();
                $summary['ended_marketplaces'][] = $item + ['response_summary' => $result['response_summary']];
            } else {
                $listing->forceFill(['last_error' => $result['message'] ?? 'Marketplace end listing failed', 'last_synced_at' => now()])->save();
                $summary['failed_marketplaces'][] = $item + ['response_summary' => $result['response_summary'], 'message' => $result['message'] ?? null];
            }
        }

        $summary['local_product_sold'] = true;
        $summary['storefront_available'] = false;
        $summary['marketplace_write'] = true;

        return $summary;
    }

    private function buildSummary(Part $part, bool $apply): array
    {
        $items = $this->listingItems($part);

        return [
            'ok' => true,
            'part_id' => $part->id,
            'dry_run' => ! $apply,
            'local_product_sold' => $apply ? true : ($part->status === 'sold'),
            'would_set_local_product_sold' => $part->status !== 'sold',
            'would_set_quantity_zero' => (int) $part->quantity !== 0,
            'storefront_available' => $part->fresh()?->newQuery()->whereKey($part->id)->storefrontVisible()->exists() ?? false,
            'would_storefront_available_after_apply' => false,
            'marketplace_write' => $apply,
            'marketplace_listings' => $items,
            'ended_marketplaces' => [],
            'failed_marketplaces' => [],
            'skipped_marketplaces' => array_values(array_filter($items, fn ($item) => ($item['action'] ?? null) !== 'would_end')),
            'blockers' => array_values(array_filter($items, fn ($item) => filled($item['blocker'] ?? null))),
        ];
    }

    private function listingItems(Part $part): array
    {
        return MarketplaceListing::query()->where('part_id', $part->id)->get()->map(function (MarketplaceListing $listing): array {
            $externalId = $this->externalId($listing);
            $ended = $this->isEnded($listing);
            $blocker = blank($externalId) ? 'missing_external_id' : null;

            return [
                'marketplace' => $listing->marketplace,
                'marketplace_listing_id' => $listing->id,
                'external_id' => $externalId,
                'mapping_ready' => filled($externalId),
                'status' => $listing->status,
                'blocker' => $blocker,
                'action' => $ended ? 'skip' : ($blocker ? 'blocked' : 'would_end'),
                'reason' => $ended ? 'already_ended_or_inactive' : $blocker,
            ];
        })->all();
    }

    private function isEnded(MarketplaceListing $listing): bool
    {
        $status = strtolower((string) ($listing->status ?? $listing->last_api_status ?? ''));
        return in_array($status, self::ENDED_STATUSES, true) || str_contains($status, 'end');
    }

    private function externalId(MarketplaceListing $listing): ?string
    {
        return match (true) {
            $listing->marketplace === 'allegro' => $listing->external_offer_id ?: $listing->external_listing_id,
            $listing->marketplace === 'ovoko' => $listing->external_listing_id ?: $listing->external_offer_id ?: Arr::get($listing->raw_payload ?: [], 'metadata.ovoko_part_id'),
            str_starts_with((string) $listing->marketplace, 'ebay') => $listing->external_offer_id ?: $listing->external_listing_id ?: $listing->sku,
            default => $listing->external_offer_id ?: $listing->external_listing_id,
        } ?: null;
    }

    private function endListing(MarketplaceListing $listing, string $externalId): array
    {
        $account = $listing->account ?: MarketplaceAccount::query()->where('marketplace', $listing->marketplace)->first();

        return match (true) {
            $listing->marketplace === 'allegro' => (new AllegroApiClient('allegro_main', $account))->endOffer($externalId),
            $listing->marketplace === 'ovoko' => (new OvokoApiClient('ovoko', $account))->deactivatePart($externalId),
            str_starts_with((string) $listing->marketplace, 'ebay') => (new EbayApiClient($listing->marketplace, $account))->endOffer($externalId, $listing->sku),
            default => ['ok' => false, 'message' => 'Unsupported marketplace', 'response_summary' => ['reason' => 'unsupported_marketplace']],
        };
    }

    private function writeLog(MarketplaceListing $listing, string $externalId, array $result): void
    {
        MarketplaceSyncLog::query()->create([
            'marketplace' => $listing->marketplace,
            'marketplace_listing_id' => $listing->id,
            'part_id' => $listing->part_id,
            'action' => self::ACTION,
            'status' => ($result['ok'] ?? false) ? 'success' : 'error',
            'http_status' => $result['http_status'] ?? null,
            'message' => $result['message'] ?? null,
            'external_id' => $externalId,
            'payload' => [
                'marketplace_write' => true,
                'triggered_by' => 'local_sale',
                'request_summary' => $result['request_summary'] ?? [],
                'response_summary' => $result['response_summary'] ?? [],
            ],
            'created_at' => now(),
        ]);
    }
}
