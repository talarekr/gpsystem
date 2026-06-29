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

            if ($existingUrl !== null && ! $force) {
                $action = 'skipped_has_url';
                $summary['skipped']++;
            } elseif ($ovokoId === null) {
                $action = 'missing_ovoko_id';
                $summary['skipped']++;
            } else {
                [$resolved, $source, $action] = $this->resolveShopUrl($listing, $ovokoId, $csvRows, $client);

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
                'resolved_shop_url' => $resolved ?? '',
                'source' => $source,
                'action' => $action,
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
        $local = $this->firstUrl($listing->raw_payload ?? []);
        if ($local !== null) return [$local, 'local', 'would_update'];

        if ($csvRows !== []) {
            $match = $this->matchCsv($csvRows, $listing, $ovokoId);
            if (($match['ambiguous'] ?? false) === true) return [null, 'csv', 'ambiguous'];
            if (($match['shop_url'] ?? null) !== null) return [$match['shop_url'], 'csv', 'would_update'];
        }

        if ($client !== null && method_exists($client, 'fetchPartRawById')) {
            try {
                $result = $client->fetchPartRawById($ovokoId);
                $url = $this->firstUrl($result['raw'] ?? []) ?? $this->blankNull($result['normalized']['url'] ?? null);
                if (($result['api_ok'] ?? false) && $url !== null) return [$url, 'ovoko_read_api', 'would_update'];
            } catch (\Throwable) {
                // Read-only lookup failed; report missing_shop_url without writing or retrying through mutating endpoints.
            }
        }

        return [null, $csvRows !== [] ? 'csv' : 'ovoko_read_api', 'missing_shop_url'];
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
