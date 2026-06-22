<?php

namespace App\Services\Tools;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class AllegroActiveOfferLinksRefreshService
{
    private const ACCOUNT_CODE = 'allegro_main';
    private const MARKETPLACE = 'allegro';
    private const NOT_FOUND_STATUS = 'NOT_FOUND_IN_ACTIVE_API';

    public function dryRun(int $limit = 5000, string $status = 'ACTIVE'): array
    {
        return $this->report($limit, $status, false);
    }

    public function live(int $limit = 5000, string $status = 'ACTIVE'): array
    {
        return $this->report($limit, $status, true);
    }

    public function uiReport(int $sample = 20): array
    {
        $blockers = $this->tableBlockers(true);
        if ($blockers !== []) {
            return ['ok' => false, 'warnings' => [], 'blockers' => $blockers];
        }

        $sample = max(1, min($sample, 100));
        $partsTotal = Schema::hasTable('parts') ? Part::query()->count() : 0;
        $base = $this->allegroListingsQuery()->whereNotNull('external_offer_id')->where('external_offer_id', '<>', '');
        $active = (clone $base)->where(function ($query): void {
            $query->where('last_api_status', 'ACTIVE')->orWhere('status', 'ACTIVE');
        });
        $notSeen = (clone $base)->where(function ($query): void {
            $query->where('last_api_status', self::NOT_FOUND_STATUS)->orWhere('status', self::NOT_FOUND_STATUS)->orWhere('status', 'INACTIVE');
        });

        return [
            'ok' => true,
            'allegro_green_count' => (clone $active)->count(),
            'allegro_red_count' => max(0, $partsTotal - (clone $active)->count()),
            'allegro_with_link_count' => (clone $base)->count(),
            'allegro_active_listing_count' => (clone $active)->count(),
            'allegro_not_seen_active_count' => (clone $notSeen)->count(),
            'sample_green' => $this->sample((clone $active), $sample),
            'sample_red_with_listing' => $this->sample((clone $notSeen), $sample),
            'sample_red_without_listing' => $this->samplePartsWithoutAllegroListing($sample),
            'warnings' => [],
            'blockers' => [],
        ];
    }

    private function report(int $limit, string $status, bool $live): array
    {
        $limit = max(1, $limit);
        $blockers = $this->tableBlockers($live);
        if ($blockers !== []) return ['ok' => false, 'warnings' => [], 'blockers' => $blockers];

        $api = $this->fetchOffers($limit, $status);
        if (is_int($api['total_count']) && $api['total_count'] > count($api['offers'])) {
            $api['blockers'][] = sprintf(
                'Partial API fetch: fetched %d of %d active offers. Increase limit or fix pagination.',
                count($api['offers']),
                $api['total_count']
            );
        }
        $apiIds = array_values(array_unique(array_filter(Arr::pluck($api['offers'], 'allegro_offer_id'))));
        $apiSet = array_fill_keys($apiIds, true);
        $localRows = $this->allegroListingsQuery()->whereNotNull('external_offer_id')->where('external_offer_id', '<>', '')->get(['id','part_id','external_offer_id','status','last_api_status']);
        $matched = $localRows->filter(fn ($r): bool => isset($apiSet[(string) $r->external_offer_id]));
        $notSeen = $localRows->reject(fn ($r): bool => isset($apiSet[(string) $r->external_offer_id]));
        $localSet = array_fill_keys($localRows->pluck('external_offer_id')->map(fn ($id) => (string) $id)->all(), true);
        $apiNotLocal = array_values(array_filter($api['offers'], fn ($offer): bool => ! isset($localSet[(string) $offer['allegro_offer_id']])));

        $updatedCount = 0;
        if ($live && $api['blockers'] === []) {
            $now = now();
            $updatedCount = DB::transaction(function () use ($matched, $notSeen, $now): int {
                $count = 0;
                if ($matched->isNotEmpty()) {
                    $count += MarketplaceListing::query()->whereIn('id', $matched->pluck('id'))->update(['status' => 'ACTIVE', 'last_api_status' => 'ACTIVE', 'last_seen_at' => $now, 'updated_at' => $now]);
                }
                if ($notSeen->isNotEmpty()) {
                    $count += MarketplaceListing::query()->whereIn('id', $notSeen->pluck('id'))->update(['status' => self::NOT_FOUND_STATUS, 'last_api_status' => self::NOT_FOUND_STATUS, 'not_seen_in_active_api_at' => $now, 'updated_at' => $now]);
                }

                return $count;
            });
        }

        return [
            'ok' => $api['blockers'] === [],
            'api_total_count' => $api['total_count'],
            'api_fetched_count' => count($api['offers']),
            'updated_count' => $updatedCount,
            'local_allegro_listings_total' => $this->allegroListingsQuery()->count(),
            'matched_active_listing_count' => $matched->count(),
            'local_listing_not_seen_active_count' => $notSeen->count(),
            'api_active_offer_not_found_locally_count' => count($apiNotLocal),
            'sample_matched_active_listing' => $this->sampleCollection($matched),
            'sample_local_listing_not_seen_active' => $this->sampleCollection($notSeen),
            'sample_api_active_offer_not_found_locally' => array_slice($apiNotLocal, 0, 10),
            'warnings' => $api['warnings'],
            'blockers' => $api['blockers'],
        ];
    }

    private function tableBlockers(bool $requireTrackingColumns = false): array
    {
        $blockers = array_values(array_filter([
            ! Schema::hasTable('marketplace_accounts') ? 'Missing marketplace_accounts table.' : null,
            ! Schema::hasTable('marketplace_listings') ? 'Missing marketplace_listings table.' : null,
            ! Schema::hasTable('parts') ? 'Missing parts table.' : null,
        ]));
        if ($requireTrackingColumns && Schema::hasTable('marketplace_listings')) {
            foreach (['last_seen_at','last_api_status','not_seen_in_active_api_at'] as $column) {
                if (! Schema::hasColumn('marketplace_listings', $column)) $blockers[] = "Missing marketplace_listings.$column column. Run migrations first.";
            }
        }
        return $blockers;
    }

    private function allegroListingsQuery()
    {
        return MarketplaceListing::query()
            ->whereIn('marketplace', [self::MARKETPLACE, self::ACCOUNT_CODE])
            ->where(function ($query): void {
                $query->whereHas('account', fn ($q) => $q->where('code', self::ACCOUNT_CODE))
                    ->orWhereNull('marketplace_account_id');
            });
    }

    private function sample($query, int $limit): array
    {
        return $query->limit($limit)->get(['id','part_id','external_offer_id','status','last_api_status','last_seen_at','not_seen_in_active_api_at'])->map(fn ($r) => $this->row($r))->all();
    }

    private function sampleCollection($rows): array
    {
        return $rows->take(10)->map(fn ($r) => $this->row($r))->values()->all();
    }

    private function row($r): array
    {
        return ['listing_id' => $r->id, 'part_id' => $r->part_id, 'external_offer_id' => $r->external_offer_id, 'status' => $r->status, 'last_api_status' => $r->last_api_status ?? null];
    }


    private function samplePartsWithoutAllegroListing(int $limit): array
    {
        return Part::query()
            ->whereDoesntHave('marketplaceListings', function ($query): void {
                $query->whereIn('marketplace', [self::MARKETPLACE, self::ACCOUNT_CODE])
                    ->whereNotNull('external_offer_id')
                    ->where('external_offer_id', '<>', '');
            })
            ->limit($limit)
            ->get(['id','part_number','name'])
            ->map(fn (Part $part): array => ['part_id' => $part->id, 'part_number' => $part->part_number, 'name' => $part->name])
            ->all();
    }

    private function fetchOffers(int $limit, string $status): array
    {
        $account = MarketplaceAccount::query()->where('code', self::ACCOUNT_CODE)->first();
        if (! $account) return ['offers'=>[], 'total_count'=>null, 'warnings'=>[], 'blockers'=>['Marketplace account allegro_main was not found.']];
        $token = (string) (($account->api_credentials ?? [])['access_token'] ?? '');
        if ($token === '' || blank($account->api_base_url)) return ['offers'=>[], 'total_count'=>null, 'warnings'=>[], 'blockers'=>['Allegro access token or API base URL is missing.']];

        $offersById = []; $warnings = []; $blockers = []; $offset = 0; $total = null;
        while (count($offersById) < $limit) {
            $remaining = $limit - count($offersById);
            if (is_int($total)) {
                $remaining = min($remaining, max(0, $total - count($offersById)));
            }
            if ($remaining <= 0) break;

            $query = ['limit' => min(1000, $remaining), 'offset' => $offset];
            if ($status !== '') $query['publication.status'] = $status;
            $response = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(20)->get(rtrim((string) $account->api_base_url, '/').'/sale/offers', $query);
            if (! $response->successful()) { $blockers[] = 'Allegro /sale/offers read-only request failed with HTTP '.$response->status().'.'; break; }
            $json = $response->json();
            if (! is_array($json)) { $blockers[] = 'Allegro /sale/offers returned a non-JSON response.'; break; }
            $total ??= is_numeric($json['totalCount'] ?? null) ? (int) $json['totalCount'] : null;
            $page = array_values(array_filter($json['offers'] ?? [], 'is_array'));
            foreach ($page as $row) {
                $offerId = (string) ($row['id'] ?? '');
                if ($offerId === '') continue;
                $offersById[$offerId] = ['allegro_offer_id' => $offerId, 'allegro_title' => (string) ($row['name'] ?? ''), 'allegro_status' => data_get($row, 'publication.status')];
            }
            if (count($page) < $query['limit']) break;
            $offset += count($page);
        }
        return ['offers'=>array_values($offersById), 'total_count'=>$total, 'warnings'=>$warnings, 'blockers'=>$blockers];
    }
}
