<?php

namespace App\Services\Tools;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use App\Support\Marketplace\AllegroUserAgent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AllegroOfferIdCoverageService
{
    private const TOKEN_ACCOUNT = 'allegro_main';
    private const MARKETPLACE = 'allegro';
    private const MAX_LIMIT = 5000;

    public function localSourcesReport(): array
    {
        $blockers = $this->tableBlockers();
        if ($blockers !== []) return ['ok' => false, 'blockers' => $blockers, 'warnings' => []];

        $sources = $this->localSources();

        return [
            'ok' => true,
            'parts_total' => DB::table('parts')->count(),
            'marketplace_listings_allegro_total' => $this->allegroListingsQuery()->count(),
            'marketplace_listings_with_external_offer_id' => $this->allegroListingsQuery()->whereNotNull('external_offer_id')->where('external_offer_id', '<>', '')->count(),
            'parts_columns_checked' => $sources['columns_checked'],
            'parts_direct_allegro_id_counts' => $sources['direct_counts'],
            'parts_payload_allegro_id_counts' => $sources['payload_counts'],
            'legacy_url_offer_id_count' => $sources['legacy_url_count'],
            'sample_parts_with_direct_allegro_id' => array_slice($sources['direct_samples'], 0, 10),
            'sample_parts_with_payload_allegro_id' => array_slice($sources['payload_samples'], 0, 10),
            'sample_parts_with_legacy_url_offer_id' => array_slice($sources['url_samples'], 0, 10),
            'sample_marketplace_listings' => $this->allegroListingsQuery()->select(['id','part_id','external_offer_id','sku','title','status'])->whereNotNull('external_offer_id')->limit(10)->get(),
            'warnings' => $sources['warnings'],
            'blockers' => [],
        ];
    }

    public function coverageReport(int $limit = 1000, string $status = 'ACTIVE'): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $blockers = $this->tableBlockers();
        if ($blockers !== []) return ['ok' => false, 'blockers' => $blockers, 'warnings' => []];

        $api = $this->fetchOffers($limit, $status);
        $local = $this->localSources();
        $listingIds = $this->listingOfferIds();
        $legacyIds = $local['offer_map'];
        $apiIds = array_values(array_filter(Arr::pluck($api['offers'], 'allegro_offer_id')));
        $apiSet = array_fill_keys($apiIds, true);
        $localAll = array_unique(array_merge(array_keys($listingIds), array_keys($legacyIds)));

        $rows = $this->coverageRows($api['offers'], $listingIds, $legacyIds);

        return [
            'ok' => $api['blockers'] === [],
            'api_total_count' => $api['total_count'],
            'api_fetched_count' => count($api['offers']),
            'marketplace_listings_allegro_total' => $this->allegroListingsQuery()->count(),
            'local_offer_ids_total_unique' => count($localAll),
            'matched_in_marketplace_listings_count' => count(array_intersect($apiIds, array_keys($listingIds))),
            'matched_in_parts_legacy_count' => count(array_intersect($apiIds, array_keys($legacyIds))),
            'api_not_found_locally_count' => count(array_filter($rows, fn ($r) => ! $r['found_in_marketplace_listing'] && ! $r['found_in_parts_legacy'])),
            'local_not_seen_in_api_count' => count(array_filter($localAll, fn ($id) => ! isset($apiSet[$id]))),
            'sample_api_matched_marketplace_listing' => array_slice(array_values(array_filter($rows, fn ($r) => $r['found_in_marketplace_listing'])), 0, 10),
            'sample_api_matched_parts_legacy' => array_slice(array_values(array_filter($rows, fn ($r) => $r['found_in_parts_legacy'])), 0, 10),
            'sample_api_not_found_locally' => array_slice(array_values(array_filter($rows, fn ($r) => ! $r['found_in_marketplace_listing'] && ! $r['found_in_parts_legacy'])), 0, 10),
            'sample_local_not_seen_in_api' => array_slice(array_values(array_map(fn ($id) => ['allegro_offer_id' => $id, 'sources' => $legacyIds[$id] ?? [], 'marketplace_listing' => $listingIds[$id] ?? null], array_filter($localAll, fn ($id) => ! isset($apiSet[$id])))), 0, 10),
            'warnings' => array_merge($api['warnings'], $local['warnings']),
            'blockers' => $api['blockers'],
        ];
    }

    public function csvResponse(int $limit = 5000, string $status = 'ACTIVE'): StreamedResponse
    {
        $api = $this->fetchOffers(max(1, min($limit, self::MAX_LIMIT)), $status);
        $local = $this->localSources();
        $rows = $this->coverageRows($api['offers'], $this->listingOfferIds(), $local['offer_map']);
        $columns = ['allegro_offer_id','allegro_title','allegro_sku','allegro_status','allegro_price','allegro_quantity','found_in_marketplace_listing','found_in_parts_legacy','part_id','part_number','local_name','source_field','source_payload_path','has_marketplace_listing','can_create_marketplace_listing_candidate','notes'];

        return response()->streamDownload(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'w'); fputcsv($out, $columns);
            foreach ($rows as $row) fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', $columns));
            fclose($out);
        }, 'allegro-offer-id-coverage.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function coverageRows(array $offers, array $listingIds, array $legacyIds): array
    {
        $rows = [];
        foreach ($offers as $offer) {
            $id = $offer['allegro_offer_id']; $legacy = $legacyIds[$id] ?? []; $first = $legacy[0] ?? [];
            $uniquePartIds = array_values(array_unique(array_column($legacy, 'part_id')));
            $hasListing = isset($listingIds[$id]);
            $partHasOther = count($uniquePartIds) === 1 && $this->partHasOtherAllegroListing((int) $uniquePartIds[0], $id);
            $canCreate = count($uniquePartIds) === 1 && ! $hasListing && ! $partHasOther;
            $rows[] = $offer + [
                'title' => $offer['allegro_title'], 'sku' => $offer['allegro_sku'], 'status' => $offer['allegro_status'], 'price' => $offer['allegro_price'], 'quantity' => $offer['allegro_quantity'],
                'found_in_marketplace_listing' => $hasListing, 'found_in_parts_legacy' => $legacy !== [],
                'part_id' => $first['part_id'] ?? null, 'part_number' => $first['part_number'] ?? null, 'name' => $first['name'] ?? null, 'local_name' => $first['name'] ?? null,
                'source_field' => $first['source_field'] ?? null, 'source_payload_path' => $first['source_payload_path'] ?? null,
                'has_marketplace_listing' => $hasListing, 'can_create_marketplace_listing_candidate' => $canCreate,
                'reason' => (!$hasListing && $legacy === []) ? 'no_local_offer_id_found' : null,
                'notes' => (!$hasListing && $legacy === []) ? 'no_local_offer_id_found' : ($partHasOther ? 'part_has_other_allegro_main_listing' : ''),
            ];
        }
        return $rows;
    }

    private function localSources(): array
    {
        $columns = Schema::getColumnListing('parts');
        $directColumns = array_values(array_filter($columns, fn ($c) => str_contains(strtolower($c), 'allegro') && preg_match('/(offer|listing|auction|id)/i', $c) && ! preg_match('/price|status|category|currency|parameters|imported/i', $c)));
        $payloadColumns = array_values(array_filter(['legacy_payload'], fn ($c) => in_array($c, $columns, true)));
        $urlColumns = array_values(array_filter($columns, fn ($c) => preg_match('/(legacy_)?(offer_)?url|permalink/i', $c)));
        $select = array_values(array_unique(array_merge(['id'], array_intersect(['part_number','name'], $columns), $directColumns, $payloadColumns, $urlColumns)));
        $out = ['columns_checked' => ['parts' => $columns, 'direct_allegro_id_columns' => $directColumns, 'payload_columns' => $payloadColumns, 'url_columns' => $urlColumns], 'direct_counts' => [], 'payload_counts' => [], 'legacy_url_count' => 0, 'direct_samples' => [], 'payload_samples' => [], 'url_samples' => [], 'offer_map' => [], 'warnings' => []];
        foreach ($directColumns as $c) $out['direct_counts'][$c] = 0;
        foreach ($payloadColumns as $c) $out['payload_counts'][$c] = 0;

        DB::table('parts')->select($select)->orderBy('id')->chunkById(500, function ($parts) use (&$out, $directColumns, $payloadColumns, $urlColumns): void {
            foreach ($parts as $part) {
                foreach ($directColumns as $c) if ($id = $this->cleanOfferId($part->{$c} ?? null)) { $out['direct_counts'][$c]++; $this->addSource($out, $id, $part, $c, null); $this->sample($out['direct_samples'], $id, $part, $c, null); }
                foreach ($payloadColumns as $c) foreach ($this->extractPayloadIds($part->{$c} ?? null) as $hit) { $out['payload_counts'][$c]++; $this->addSource($out, $hit['id'], $part, null, $c.'.'.$hit['path']); $this->sample($out['payload_samples'], $hit['id'], $part, null, $c.'.'.$hit['path']); }
                foreach ($urlColumns as $c) if ($id = $this->offerIdFromUrl($part->{$c} ?? null)) { $out['legacy_url_count']++; $this->addSource($out, $id, $part, $c, null); $this->sample($out['url_samples'], $id, $part, $c, null); }
            }
        });
        return $out;
    }

    private function addSource(array &$out, string $id, object $part, ?string $field, ?string $path): void { $out['offer_map'][$id][] = ['part_id' => $part->id, 'part_number' => $part->part_number ?? null, 'name' => $part->name ?? null, 'source_field' => $field, 'source_payload_path' => $path]; }
    private function sample(array &$samples, string $id, object $part, ?string $field, ?string $path): void { if (count($samples) < 10) $samples[] = ['allegro_offer_id' => $id, 'part_id' => $part->id, 'part_number' => $part->part_number ?? null, 'name' => $part->name ?? null, 'source_field' => $field, 'source_payload_path' => $path]; }
    private function tableBlockers(): array { return array_values(array_filter([! Schema::hasTable('parts') ? 'Missing parts table.' : null, ! Schema::hasTable('marketplace_listings') ? 'Missing marketplace_listings table.' : null])); }
    private function allegroListingsQuery() { return MarketplaceListing::query()->where('marketplace', self::MARKETPLACE)->whereHas('account', fn ($q) => $q->where('code', self::TOKEN_ACCOUNT)); }
    private function listingOfferIds(): array { return $this->allegroListingsQuery()->whereNotNull('external_offer_id')->get(['id','part_id','external_offer_id'])->keyBy('external_offer_id')->map(fn ($r) => ['listing_id' => $r->id, 'part_id' => $r->part_id])->all(); }
    private function partHasOtherAllegroListing(int $partId, string $offerId): bool { return $this->allegroListingsQuery()->where('part_id', $partId)->where('external_offer_id', '<>', $offerId)->exists(); }

    private function fetchOffers(int $limit, string $status): array
    {
        $account = MarketplaceAccount::query()->where('code', self::TOKEN_ACCOUNT)->first();
        if (! $account) return ['offers'=>[], 'total_count'=>null, 'warnings'=>[], 'blockers'=>['Marketplace account allegro_main was not found.']];
        $token = (string) (($account->api_credentials ?? [])['access_token'] ?? '');
        if ($token === '' || blank($account->api_base_url)) return ['offers'=>[], 'total_count'=>null, 'warnings'=>[], 'blockers'=>['Allegro access token or API base URL is missing.']];
        $offers = []; $warnings = []; $blockers = []; $offset = 0; $total = null;
        while (count($offers) < $limit) {
            $query = ['limit' => min(1000, $limit - count($offers)), 'offset' => $offset]; if ($status !== '') $query['publication.status'] = $status;
            $response = AllegroUserAgent::request()->withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(20)->get(rtrim((string) $account->api_base_url, '/').'/sale/offers', $query);
            if (! $response->successful()) { $blockers[] = 'Allegro /sale/offers read-only request failed with HTTP '.$response->status().'.'; break; }
            $json = $response->json(); if (! is_array($json)) { $blockers[] = 'Allegro /sale/offers returned a non-JSON response.'; break; }
            $total ??= is_numeric($json['totalCount'] ?? null) ? (int) $json['totalCount'] : null;
            $page = array_values(array_filter($json['offers'] ?? [], 'is_array'));
            foreach ($page as $row) $offers[] = ['allegro_offer_id'=>(string)($row['id'] ?? ''), 'allegro_title'=>(string)($row['name'] ?? ''), 'allegro_sku'=>(string)data_get($row, 'external.id', ''), 'allegro_status'=>data_get($row, 'publication.status'), 'allegro_price'=>data_get($row, 'sellingMode.price.amount'), 'allegro_quantity'=>data_get($row, 'stock.available')];
            if (count($page) < $query['limit']) break; $offset += count($page);
        }
        return ['offers'=>$offers, 'total_count'=>$total, 'warnings'=>$warnings, 'blockers'=>$blockers];
    }

    private function extractPayloadIds(mixed $payload): array { $data = is_string($payload) ? json_decode($payload, true) : $payload; $hits = []; if (is_array($data)) $this->walkPayload($data, '', $hits); return $hits; }
    private function walkPayload(array $data, string $prefix, array &$hits): void { foreach ($data as $k => $v) { $path = $prefix === '' ? (string) $k : $prefix.'.'.$k; if (is_array($v)) $this->walkPayload($v, $path, $hits); elseif (preg_match('/allegro/i', $path) && preg_match('/offer|id|auction|url/i', $path)) { if ($id = ($this->offerIdFromUrl($v) ?? $this->cleanOfferId($v))) $hits[] = ['id' => $id, 'path' => $path]; } } }
    private function offerIdFromUrl(mixed $value): ?string { return is_scalar($value) && preg_match('~/oferta/(?:[^/\s-]+-)?(\d{6,})~', (string) $value, $m) ? $m[1] : null; }
    private function cleanOfferId(mixed $value): ?string { if (! is_scalar($value)) return null; $value = trim((string) $value); return preg_match('/^\d{6,}$/', $value) ? $value : null; }
}
