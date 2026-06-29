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
    public function run(bool $apply = false, bool $force = false, ?int $partId = null, int $limit = 100, ?string $csvPath = null): array
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

        $results = [];
        $summary = ['inspected' => 0, 'updated' => 0, 'would_update' => 0, 'skipped' => 0, 'missing_shop_url' => 0, 'ambiguous' => 0];

        foreach ($query->get() as $listing) {
            $summary['inspected']++;
            $ovokoId = $this->existingOvokoId($listing);
            $existingUrl = $this->blankNull($listing->url);
            $resolved = null;
            $source = 'skipped';
            $action = 'missing_shop_url';
            $diagnostics = [
                'rejected_local_url' => null,
                'rejected_local_url_reason' => null,
                'accepted_shop_url_host' => null,
            ];

            if ($existingUrl !== null && ! $force) {
                $action = 'skipped_has_url';
                $summary['skipped']++;
            } elseif ($ovokoId === null) {
                $action = 'missing_ovoko_id';
                $summary['skipped']++;
            } else {
                [$resolved, $source, $action, $diagnostics] = $this->resolveShopUrl($listing, $ovokoId, $csvRows, $client);

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
                }
            }

            $results[] = [
                'local_part_id' => $listing->part_id,
                'marketplace_listing_id' => $listing->id,
                'existing_ovoko_id' => $ovokoId ?? '',
                'existing_url' => $existingUrl ?? '',
                'resolved_shop_url' => $resolved,
                'source' => $source,
                'action' => $action,
                'rejected_local_url' => $diagnostics['rejected_local_url'],
                'rejected_local_url_reason' => $diagnostics['rejected_local_url_reason'],
                'accepted_shop_url_host' => $diagnostics['accepted_shop_url_host'],
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

    private function resolveShopUrl(MarketplaceListing $listing, string $ovokoId, array $csvRows, mixed $client): array
    {
        $diagnostics = [
            'rejected_local_url' => null,
            'rejected_local_url_reason' => null,
            'accepted_shop_url_host' => null,
        ];

        $local = $this->firstUrl($listing->raw_payload ?? []);
        if ($local !== null) {
            $validation = $this->validateShopUrl($local);
            if ($validation['valid']) {
                $diagnostics['accepted_shop_url_host'] = $validation['host'];
                return [$local, 'local', 'would_update', $diagnostics];
            }

            $diagnostics['rejected_local_url'] = $local;
            $diagnostics['rejected_local_url_reason'] = $validation['reason'];
        }

        if ($csvRows !== []) {
            $match = $this->matchCsv($csvRows, $listing, $ovokoId);
            if (($match['ambiguous'] ?? false) === true) return [null, 'csv', 'ambiguous', $diagnostics];
            if (($match['shop_url'] ?? null) !== null) {
                $validation = $this->validateShopUrl($match['shop_url']);
                if ($validation['valid']) {
                    $diagnostics['accepted_shop_url_host'] = $validation['host'];
                    return [$match['shop_url'], 'csv', 'would_update', $diagnostics];
                }

                return [null, 'skipped/local_invalid', 'missing_shop_url', $diagnostics];
            }
        }

        if ($client !== null && method_exists($client, 'fetchPartRawById')) {
            try {
                $result = $client->fetchPartRawById($ovokoId);
                $url = $this->firstUrl($result['raw'] ?? []) ?? $this->blankNull($result['normalized']['url'] ?? null);
                if (($result['api_ok'] ?? false) && $url !== null) {
                    $validation = $this->validateShopUrl($url);
                    if ($validation['valid']) {
                        $diagnostics['accepted_shop_url_host'] = $validation['host'];
                        return [$url, 'ovoko_read_api', 'would_update', $diagnostics];
                    }
                }
            } catch (\Throwable) {
                // Read-only lookup failed; report missing_shop_url without writing or retrying through mutating endpoints.
            }
        }

        return [null, $diagnostics['rejected_local_url'] !== null ? 'skipped/local_invalid' : ($csvRows !== [] ? 'csv' : 'ovoko_read_api'), 'missing_shop_url', $diagnostics];
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
