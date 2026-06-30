<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use Illuminate\Support\Facades\Schema;

class OvokoListingUrlBackfillService
{
    public function __construct(
        private readonly MarketplaceApiManager $apiManager,
        private readonly OvokoPartIdExtractor $extractor,
    ) {}

    /** @return array{mode:string,summary:array<string,int>,results:array<int,array<string,mixed>>,warnings:array<int,string>} */
    public function run(bool $apply = false, bool $force = false, ?int $partId = null, int $limit = 100, ?string $csvPath = null, ?int $listingId = null, int $maxPages = 3): array
    {
        if (! Schema::hasTable('marketplace_listings')) {
            throw new \RuntimeException('Required table marketplace_listings does not exist.');
        }

        $limit = max(1, $limit);
        $warnings = [];
        $csvRows = $csvPath ? $this->loadCsv($csvPath) : [];
        $client = null;

        if (! $csvPath) {
            try {
                $client = $this->apiManager->client('ovoko');
            } catch (\Throwable $exception) {
                $warnings[] = 'Ovoko API client unavailable; rows without local/CSV shop_url will report missing_shop_url: '.$exception->getMessage();
            }
        }

        $query = MarketplaceListing::query()
            ->with('part:id,external_id,legacy_payload')
            ->where('marketplace', 'ovoko')
            ->whereNotNull('part_id')
            ->orderBy('id')
            ->limit($limit);

        if ($partId !== null) {
            $query->where('part_id', $partId);
        }
        if ($listingId !== null) {
            $query->whereKey($listingId);
        }

        $results = [];
        $summary = ['inspected' => 0, 'updated' => 0, 'would_update' => 0, 'skipped' => 0, 'missing_shop_url' => 0, 'ambiguous' => 0];

        foreach ($query->get() as $listing) {
            $summary['inspected']++;
            $ovokoId = $this->existingOvokoId($listing);
            $existingUrl = $this->blankNull($listing->url);
            $resolved = null;
            $source = 'skipped';
            $action = 'missing_shop_url';
            $diagnostics = $this->emptyDiagnostics();

            if ($existingUrl !== null && ! $force) {
                $action = 'skipped_has_url';
                $summary['skipped']++;
            } elseif ($ovokoId === null) {
                $action = 'missing_ovoko_id';
                $summary['skipped']++;
            } else {
                [$resolved, $source, $action, $diagnostics] = $this->resolveShopUrl($listing, $ovokoId, $csvRows, $client, $maxPages);

                if ($action === 'would_update') {
                    if ($apply) {
                        $listing->url = $resolved;
                        if ($this->blankNull($listing->external_offer_id) === null && $this->blankNull($ovokoId) !== null) {
                            $listing->external_offer_id = $ovokoId;
                        }
                        $listing->save();
                        $action = 'updated';
                        $summary['updated']++;
                    } else {
                        $summary['would_update']++;
                    }
                } elseif ($action === 'ambiguous') {
                    $summary['ambiguous']++;
                } else {
                    $summary['missing_shop_url']++;
                    if (in_array(($diagnostics['ovoko_read_api_rejection_reason'] ?? null), ['part_detail_not_found_on_known_read_only_endpoints_csv_export_required', 'detail_id_mismatch'], true)) {
                        $warning = 'Ovoko read-only API did not return a matching shop_url by part ID or external_id; backfilling older links requires a CSV export from Ovoko.';
                        if (! in_array($warning, $warnings, true)) $warnings[] = $warning;
                    }
                }
            }

            $results[] = [
                'local_part_id' => $listing->part_id,
                'marketplace_listing_id' => $listing->id,
                'existing_ovoko_id' => $ovokoId ?? '',
                'requested_ovoko_id' => $diagnostics['requested_ovoko_id'],
                'requested_external_id' => $diagnostics['requested_external_id'],
                'lookup_by' => $diagnostics['lookup_by'],
                'existing_url' => $existingUrl ?? '',
                'resolved_shop_url' => $resolved,
                'source' => $source,
                'action' => $action,
                'rejected_local_url' => $diagnostics['rejected_local_url'],
                'rejected_local_url_reason' => $diagnostics['rejected_local_url_reason'],
                'accepted_shop_url_host' => $diagnostics['accepted_shop_url_host'],
                'ovoko_read_api_attempted' => $diagnostics['ovoko_read_api_attempted'],
                'ovoko_read_api_endpoint' => $diagnostics['ovoko_read_api_endpoint'],
                'ovoko_read_api_status' => $diagnostics['ovoko_read_api_status'],
                'ovoko_read_api_response_keys' => $diagnostics['ovoko_read_api_response_keys'],
                'ovoko_read_api_shop_url_found' => $diagnostics['ovoko_read_api_shop_url_found'],
                'ovoko_read_api_rejection_reason' => $diagnostics['ovoko_read_api_rejection_reason'],
                'ovoko_read_api_request_fields' => $diagnostics['ovoko_read_api_request_fields'],
                'returned_candidates_count' => $diagnostics['returned_candidates_count'],
                'matched_candidate_index' => $diagnostics['matched_candidate_index'],
                'matched_candidate_id' => $diagnostics['matched_candidate_id'],
                'matched_candidate_external_id' => $diagnostics['matched_candidate_external_id'],
                'matched_candidate_shop_url' => $diagnostics['matched_candidate_shop_url'],
                'mismatch_sample_ids' => $diagnostics['mismatch_sample_ids'],
                'returned_pagination' => $diagnostics['returned_pagination'],
                'returned_pagination_count' => $diagnostics['returned_pagination_count'],
                'ovoko_read_api_attempts' => $diagnostics['ovoko_read_api_attempts'],
                'resolution_attempts' => $diagnostics['resolution_attempts'],
            ];
        }

        return ['mode' => $apply ? 'apply' : 'dry_run', 'summary' => $summary, 'results' => $results, 'warnings' => $warnings];
    }

    private function existingOvokoId(MarketplaceListing $listing): ?string
    {
        foreach (['external_offer_id', 'external_listing_id', 'external_inventory_id', 'tracking_id'] as $column) {
            if (Schema::hasColumn('marketplace_listings', $column)) {
                $value = $this->blankNull($listing->{$column} ?? null);
                if ($value !== null) return $value;
            }
        }

        return $this->extractor->extract($listing->part?->legacy_payload ?? null);
    }

    private function resolveShopUrl(MarketplaceListing $listing, string $ovokoId, array $csvRows, mixed $client, int $maxPages): array
    {
        $diagnostics = $this->emptyDiagnostics();
        $externalId = $this->blankNull($listing->part?->external_id ?? null) ?? 'gps-part-'.(string) $listing->part_id;
        $diagnostics['requested_ovoko_id'] = $ovokoId;
        $diagnostics['requested_external_id'] = $externalId;
        $diagnostics['lookup_by'] = $externalId !== null ? 'both' : 'ovoko_id';

        $local = $this->firstUrl($listing->raw_payload ?? []);
        if ($local !== null) {
            $diagnostics['resolution_attempts'][] = 'local';
            $validation = $this->validateShopUrl($local);
            if ($validation['valid']) {
                $diagnostics['accepted_shop_url_host'] = $validation['host'];
                return [$local, 'local', 'would_update', $diagnostics];
            }

            $diagnostics['rejected_local_url'] = $local;
            $diagnostics['rejected_local_url_reason'] = $validation['reason'];
        }

        if ($client !== null && (method_exists($client, 'fetchPartRawByLookup') || method_exists($client, 'fetchPartRawById'))) {
            $diagnostics['resolution_attempts'][] = 'ovoko_read_api';
            $diagnostics['ovoko_read_api_attempted'] = true;

            try {
                $result = method_exists($client, 'fetchPartRawByLookup')
                    ? $client->fetchPartRawByLookup($ovokoId, $externalId, $maxPages)
                    : $client->fetchPartRawById($ovokoId, $maxPages);
                $diagnostics['ovoko_read_api_endpoint'] = $result['endpoint_used'] ?? data_get($result, 'attempts.0.endpoint');
                $diagnostics['ovoko_read_api_status'] = $result['api_status_code'] ?? $result['http_status'] ?? data_get($result, 'attempts.0.api_status_code') ?? data_get($result, 'attempts.0.http_status');
                $diagnostics['ovoko_read_api_response_keys'] = $result['response_top_level_keys'] ?? data_get($result, 'attempts.0.top_level_keys') ?? [];
                $diagnostics['ovoko_read_api_request_fields'] = $result['request_fields'] ?? data_get($result, 'attempts.0.request_fields') ?? [];
                $diagnostics['ovoko_read_api_attempts'] = $result['attempts'] ?? [];
                foreach (['returned_candidates_count','matched_candidate_index','matched_candidate_id','matched_candidate_external_id','matched_candidate_shop_url','mismatch_sample_ids','returned_pagination','returned_pagination_count'] as $key) {
                    $diagnostics[$key] = $result[$key] ?? $diagnostics[$key];
                }

                $url = $this->firstUrl($result['raw'] ?? []) ?? $this->blankNull($result['normalized']['url'] ?? null);
                $diagnostics['ovoko_read_api_shop_url_found'] = $url !== null;

                if (($result['api_ok'] ?? false) && $url !== null) {
                    $validation = $this->validateShopUrl($url);
                    if ($validation['valid']) {
                        $diagnostics['accepted_shop_url_host'] = $validation['host'];
                        return [$url, 'ovoko_read_api', 'would_update', $diagnostics];
                    }
                    $diagnostics['ovoko_read_api_rejection_reason'] = $validation['reason'];
                } elseif (! ($result['api_ok'] ?? false)) {
                    $diagnostics['ovoko_read_api_rejection_reason'] = $result['error'] ?? 'api_not_ok';
                }
            } catch (\Throwable $exception) {
                $diagnostics['ovoko_read_api_rejection_reason'] = $exception->getMessage();
            }
        }

        if ($csvRows !== []) {
            $diagnostics['resolution_attempts'][] = 'csv';
            $match = $this->matchCsv($csvRows, $listing, $ovokoId);
            if (($match['ambiguous'] ?? false) === true) return [null, 'csv', 'ambiguous', $diagnostics];
            if (($match['shop_url'] ?? null) !== null) {
                $validation = $this->validateShopUrl($match['shop_url']);
                if ($validation['valid']) {
                    $diagnostics['accepted_shop_url_host'] = $validation['host'];
                    return [$match['shop_url'], 'csv', 'would_update', $diagnostics];
                }

                return [null, 'csv', 'missing_shop_url', $diagnostics];
            }
        }

        return [null, $diagnostics['rejected_local_url'] !== null ? 'skipped/local_invalid' : ($diagnostics['ovoko_read_api_attempted'] ? 'ovoko_read_api' : ($csvRows !== [] ? 'csv' : 'skipped')), 'missing_shop_url', $diagnostics];
    }

    private function emptyDiagnostics(): array
    {
        return [
            'rejected_local_url' => null,
            'rejected_local_url_reason' => null,
            'accepted_shop_url_host' => null,
            'ovoko_read_api_attempted' => false,
            'ovoko_read_api_endpoint' => null,
            'ovoko_read_api_status' => null,
            'ovoko_read_api_response_keys' => [],
            'ovoko_read_api_shop_url_found' => false,
            'ovoko_read_api_rejection_reason' => null,
            'ovoko_read_api_request_fields' => [],
            'ovoko_read_api_attempts' => [],
            'resolution_attempts' => [],
            'requested_ovoko_id' => null,
            'requested_external_id' => null,
            'lookup_by' => null,
            'returned_candidates_count' => 0,
            'matched_candidate_index' => null,
            'matched_candidate_id' => null,
            'matched_candidate_external_id' => null,
            'matched_candidate_shop_url' => null,
            'mismatch_sample_ids' => [],
            'returned_pagination' => null,
            'returned_pagination_count' => null,
        ];
    }

    private function loadCsv(string $path): array
    {
        if (! is_readable($path)) {
            throw new \RuntimeException('CSV file is not readable: '.$path);
        }
        $handle = fopen($path, 'rb');
        $headers = fgetcsv($handle) ?: [];
        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($headers, array_pad($data, count($headers), null));
            if (is_array($row)) $rows[] = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
        }
        fclose($handle);
        return $rows;
    }

    private function matchCsv(array $rows, MarketplaceListing $listing, string $ovokoId): array
    {
        $externalId = $this->blankNull($listing->part?->external_id ?? null);
        foreach ([
            fn ($r) => $externalId !== null && $this->blankNull($r['external_id'] ?? null) === $externalId,
            fn ($r) => $this->blankNull($r['local_part_id'] ?? null) === (string) $listing->part_id && $this->blankNull($r['ovoko_part_id'] ?? null) === $ovokoId,
            fn ($r) => $this->blankNull($r['ovoko_part_id'] ?? null) === $ovokoId,
            fn ($r) => $this->blankNull($r['local_part_id'] ?? null) === (string) $listing->part_id,
        ] as $matcher) {
            $matches = array_values(array_filter($rows, $matcher));
            if (count($matches) > 1) return ['ambiguous' => true];
            if (count($matches) === 1) return ['shop_url' => $this->blankNull($matches[0]['shop_url'] ?? null)];
        }
        return [];
    }

    private function validateShopUrl(string $url): array
    {
        $parts = parse_url($url);
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;
        $path = $parts['path'] ?? '';

        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $host === null) {
            return ['valid' => false, 'reason' => 'invalid_url', 'host' => $host];
        }

        if ($host === 'gpswiss.pl' && str_starts_with($path, '/storage/parts/photos/')) {
            return ['valid' => false, 'reason' => 'image_url_not_listing_url', 'host' => $host];
        }

        if (preg_match('/\.(?:jpe?g|png|gif|webp|avif)(?:$|[?#])/i', $url) === 1) {
            return ['valid' => false, 'reason' => 'image_url_not_listing_url', 'host' => $host];
        }

        if (! $this->isOvokoMarketplaceHost($host)) {
            return ['valid' => false, 'reason' => 'invalid_host', 'host' => $host];
        }

        return ['valid' => true, 'reason' => null, 'host' => $host];
    }

    private function isOvokoMarketplaceHost(string $host): bool
    {
        return $host === 'ovoko.pl'
            || str_ends_with($host, '.ovoko.pl')
            || $host === 'ovoko.com'
            || str_ends_with($host, '.ovoko.com')
            || $host === 'rrr.lt'
            || str_ends_with($host, '.rrr.lt');
    }

    private function firstUrl(mixed $payload): ?string
    {
        if (! is_array($payload)) return null;
        foreach (['shop_url', 'url', 'link'] as $key) {
            $value = $this->blankNull($payload[$key] ?? null);
            if ($value !== null) return $value;
        }
        foreach ($payload as $value) {
            $found = $this->firstUrl($value);
            if ($found !== null) return $found;
        }
        return null;
    }

    private function blankNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
