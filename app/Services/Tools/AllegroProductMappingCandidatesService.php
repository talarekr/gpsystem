<?php

namespace App\Services\Tools;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AllegroProductMappingCandidatesService
{
    private const ACCOUNT_CODE = 'allegro_main';
    private const MARKETPLACE = 'allegro';
    private const MAX_LIMIT = 5000;

    public function report(int $limit = 1000, string $status = 'ACTIVE', bool $includeExisting = true): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $warnings = [];
        $blockers = [];

        if (! Schema::hasTable('marketplace_accounts') || ! Schema::hasTable('marketplace_listings') || ! Schema::hasTable('parts')) {
            return ['ok' => false, 'blockers' => ['Required marketplace_accounts, marketplace_listings, and parts tables must exist.']];
        }

        $account = MarketplaceAccount::query()->where('code', self::ACCOUNT_CODE)->first();
        if (! $account) {
            return ['ok' => false, 'blockers' => ['Marketplace account allegro_main was not found.']];
        }

        $offersResult = $this->fetchOffers($account, $limit, $status);
        $warnings = array_merge($warnings, $offersResult['warnings']);
        $blockers = array_merge($blockers, $offersResult['blockers']);
        $offers = $offersResult['offers'];

        $rows = $this->buildRows($offers, $includeExisting, $warnings);
        $summary = $this->summarize($rows);

        return $summary + [
            'ok' => $blockers === [],
            'api_fetched_count' => count($offers),
            'api_total_count' => $offersResult['total_count'],
            'local_allegro_listings_total' => MarketplaceListing::query()->where('marketplace', self::MARKETPLACE)->whereHas('account', fn ($q) => $q->where('code', self::ACCOUNT_CODE))->count(),
            'warnings' => $warnings,
            'blockers' => $blockers,
            'candidates' => $rows,
        ];
    }

    public function csvResponse(int $limit = 5000, string $status = 'ACTIVE', bool $includeExisting = true): StreamedResponse
    {
        $report = $this->report($limit, $status, $includeExisting);
        $columns = ['allegro_offer_id','allegro_title','allegro_sku','allegro_price','allegro_quantity','allegro_status','allegro_created_at','allegro_updated_at','listing_exists','current_part_id','current_part_number','current_local_name','match_type','confidence','auto_map_safe','candidate_part_id','candidate_part_number','candidate_name','candidate_sku','candidate_price','candidate_quantity','notes'];

        return response()->streamDownload(function () use ($columns, $report): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            foreach (($report['candidates'] ?? []) as $row) {
                fputcsv($out, array_map(fn ($column) => $row[$column] ?? '', $columns));
            }
            fclose($out);
        }, 'allegro-product-mapping-candidates.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function fetchOffers(MarketplaceAccount $account, int $limit, string $status): array
    {
        $warnings = [];
        $blockers = [];
        $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
        $token = (string) ($credentials['access_token'] ?? '');
        if ($token === '' || blank($account->api_base_url)) {
            return ['offers' => [], 'total_count' => null, 'warnings' => $warnings, 'blockers' => ['Allegro access token or API base URL is missing.']];
        }

        $offers = [];
        $offset = 0;
        $pageLimit = min(1000, $limit);
        $total = null;
        while (count($offers) < $limit) {
            $query = ['limit' => min($pageLimit, $limit - count($offers)), 'offset' => $offset];
            if ($status !== '') {
                $query['publication.status'] = $status;
            }
            $response = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(20)->get(rtrim((string) $account->api_base_url, '/').'/sale/offers', $query);
            if (! $response->successful() && $status !== '' && in_array($response->status(), [400, 422], true)) {
                $warnings[] = 'Allegro rejected publication.status filter; retried this page without status filter.';
                unset($query['publication.status']);
                $response = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(20)->get(rtrim((string) $account->api_base_url, '/').'/sale/offers', $query);
            }
            if (! $response->successful()) {
                $blockers[] = 'Allegro /sale/offers read-only request failed with HTTP '.$response->status().'.';
                break;
            }
            $json = $response->json();
            if (! is_array($json)) {
                $blockers[] = 'Allegro /sale/offers returned a non-JSON response.';
                break;
            }
            $total ??= is_numeric($json['totalCount'] ?? null) ? (int) $json['totalCount'] : null;
            $page = array_values(array_filter($json['offers'] ?? [], 'is_array'));
            foreach ($page as $offer) {
                $offers[] = $this->normalizeOffer($offer);
            }
            if (count($page) < $query['limit']) {
                break;
            }
            $offset += count($page);
        }

        return ['offers' => $offers, 'total_count' => $total, 'warnings' => $warnings, 'blockers' => $blockers];
    }

    private function normalizeOffer(array $row): array
    {
        return [
            'allegro_offer_id' => (string) ($row['id'] ?? ''),
            'allegro_title' => (string) ($row['name'] ?? ''),
            'allegro_sku' => (string) data_get($row, 'external.id', ''),
            'allegro_price' => data_get($row, 'sellingMode.price.amount'),
            'allegro_quantity' => data_get($row, 'stock.available'),
            'allegro_status' => data_get($row, 'publication.status'),
            'allegro_created_at' => $row['createdAt'] ?? $row['created_at'] ?? null,
            'allegro_updated_at' => $row['updatedAt'] ?? $row['updated_at'] ?? null,
        ];
    }

    private function buildRows(array $offers, bool $includeExisting, array &$warnings): array
    {
        $offerIds = array_values(array_filter(Arr::pluck($offers, 'allegro_offer_id')));
        $listings = MarketplaceListing::query()->with('part', 'account')->where('marketplace', self::MARKETPLACE)->whereIn('external_offer_id', $offerIds)->whereHas('account', fn ($q) => $q->where('code', self::ACCOUNT_CODE))->get()->keyBy('external_offer_id');
        $partListingIds = MarketplaceListing::query()->where('marketplace', self::MARKETPLACE)->whereHas('account', fn ($q) => $q->where('code', self::ACCOUNT_CODE))->whereNotNull('part_id')->pluck('external_offer_id', 'part_id')->all();

        $numbers = [];
        foreach ($offers as $offer) foreach ($this->partNumbersFromTitle($offer['allegro_title']) as $number) $numbers[$this->key($number)] = $number;
        $partsByNumber = $this->partsByCodes(array_keys($numbers));
        $partsBySku = $this->partsBySku(array_filter(array_unique(Arr::pluck($offers, 'allegro_sku'))));
        $allParts = Part::query()->select(['id','sku','name','part_number','price','allegro_price','quantity'])->limit(10000)->get();

        $rows = [];
        foreach ($offers as $offer) {
            $listing = $listings->get($offer['allegro_offer_id']);
            if ($listing && ! $includeExisting) continue;
            $rows[] = $this->rowForOffer($offer, $listing, $partsByNumber, $partsBySku, $allParts, $partListingIds);
        }
        return $rows;
    }

    private function rowForOffer(array $offer, ?MarketplaceListing $listing, array $partsByNumber, array $partsBySku, $allParts, array $partListingIds): array
    {
        $base = $offer + ['listing_exists' => $listing !== null, 'current_part_id' => $listing?->part_id, 'current_part_number' => $listing?->part?->part_number, 'current_local_name' => $listing?->part?->name, 'local_sku' => $listing?->part?->sku, 'local_price' => $listing?->part?->price, 'local_quantity' => $listing?->part?->quantity];
        if ($listing) {
            return $base + ['match_type' => 'already_mapped', 'confidence' => 'exact', 'auto_map_safe' => false, 'candidate_part_id' => $listing->part_id, 'candidate_part_number' => $listing->part?->part_number, 'candidate_name' => $listing->part?->name, 'candidate_sku' => $listing->part?->sku, 'candidate_price' => $listing->part?->price, 'candidate_quantity' => $listing->part?->quantity, 'notes' => 'Existing allegro_main marketplace listing found.'];
        }

        $matched = collect();
        foreach ($this->partNumbersFromTitle($offer['allegro_title']) as $number) $matched = $matched->merge($partsByNumber[$this->key($number)] ?? []);
        $matched = $matched->unique('id')->values();
        $matchType = $matched->count() === 1 ? 'exact_part_number' : ($matched->count() > 1 ? 'multiple_part_number_matches' : 'no_match');
        $confidence = $matched->count() === 1 ? 'exact' : ($matched->count() > 1 ? 'ambiguous' : 'none');
        $notes = [];

        if ($matched->isEmpty() && $offer['allegro_sku'] !== '') {
            $skuMatches = collect($partsBySku[$offer['allegro_sku']] ?? [])->unique('id')->values();
            if ($skuMatches->count() === 1) { $matched = $skuMatches; $matchType = 'sku_match'; $confidence = 'likely'; $notes[] = 'SKU match is shown as likely only; SKU is not treated as certain unless reviewed.'; }
            elseif ($skuMatches->count() > 1) { $matched = $skuMatches; $matchType = 'sku_match'; $confidence = 'ambiguous'; $notes[] = 'Allegro SKU matched multiple local products.'; }
        }

        if ($matched->isEmpty()) {
            $candidate = $this->titleCandidate($offer['allegro_title'], $allParts);
            if ($candidate) { $matched = collect([$candidate]); $matchType = 'title_similarity'; $confidence = 'low'; $notes[] = 'Fallback title/name similarity only; not safe for automatic mapping.'; }
        }

        $candidate = $matched->first();
        $autoSafe = $matched->count() === 1 && $matchType === 'exact_part_number' && ! isset($partListingIds[$candidate->id ?? null]);
        if (! $autoSafe && $matchType === 'exact_part_number') $notes[] = 'Candidate part already has another allegro_main marketplace listing.';
        if (! $candidate) $notes[] = 'No local product candidate found.';

        return $base + ['match_type' => $matchType, 'confidence' => $confidence, 'auto_map_safe' => $autoSafe, 'candidate_part_id' => $candidate?->id, 'candidate_part_number' => $candidate?->part_number, 'candidate_name' => $candidate?->name, 'candidate_sku' => $candidate?->sku, 'candidate_price' => $candidate?->allegro_price ?? $candidate?->price, 'candidate_quantity' => $candidate?->quantity, 'notes' => implode(' ', $notes)];
    }

    private function partsByCodes(array $keys): array
    {
        if ($keys === []) return [];
        $parts = Part::query()
            ->select(['id','sku','name','part_number','oem_number','manufacturer_code','price','allegro_price','quantity'])
            ->where(function ($query) use ($keys): void {
                $query->whereIn('part_number', $keys)
                    ->orWhereIn('oem_number', $keys)
                    ->orWhereIn('manufacturer_code', $keys);
            })
            ->get();

        $grouped = [];
        foreach ($parts as $part) {
            foreach ([$part->part_number, $part->oem_number, $part->manufacturer_code] as $code) {
                $code = $this->key($code);
                if ($code !== '') {
                    $grouped[$code][] = $part;
                }
            }
        }

        return $grouped;
    }

    private function partsBySku(array $skus): array
    { return $skus === [] ? [] : Part::query()->select(['id','sku','name','part_number','price','allegro_price','quantity'])->whereIn('sku', $skus)->get()->groupBy('sku')->all(); }

    private function partNumbersFromTitle(string $title): array
    { preg_match_all('/\b[A-Z0-9][A-Z0-9._\-\/]{3,24}\b/i', $title, $m); return array_values(array_unique(array_map(fn ($v) => $this->key($v), $m[0] ?? []))); }
    private function key(?string $value): string { return strtoupper(trim((string) $value)); }

    private function titleCandidate(string $title, $parts): ?Part
    {
        $title = mb_strtolower($title);
        $best = null; $bestScore = 0;
        foreach ($parts as $part) { similar_text($title, mb_strtolower((string) $part->name), $score); if ($score > $bestScore) { $bestScore = $score; $best = $part; } }
        return $bestScore >= 55 ? $best : null;
    }

    private function summarize(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'match_type'));
        return [
            'already_mapped_count' => $counts['already_mapped'] ?? 0,
            'unmapped_count' => count($rows) - ($counts['already_mapped'] ?? 0),
            'exact_candidate_count' => $counts['exact_part_number'] ?? 0,
            'auto_map_safe_count' => count(array_filter($rows, fn ($r) => (bool) ($r['auto_map_safe'] ?? false))),
            'ambiguous_count' => count(array_filter($rows, fn ($r) => in_array($r['confidence'] ?? '', ['ambiguous'], true))),
            'no_match_count' => $counts['no_match'] ?? 0,
            'sample_already_mapped' => array_slice(array_values(array_filter($rows, fn ($r) => ($r['match_type'] ?? '') === 'already_mapped')), 0, 10),
            'sample_auto_map_safe' => array_slice(array_values(array_filter($rows, fn ($r) => (bool) ($r['auto_map_safe'] ?? false))), 0, 10),
            'sample_ambiguous' => array_slice(array_values(array_filter($rows, fn ($r) => ($r['confidence'] ?? '') === 'ambiguous')), 0, 10),
            'sample_no_match' => array_slice(array_values(array_filter($rows, fn ($r) => ($r['match_type'] ?? '') === 'no_match')), 0, 10),
        ];
    }
}
