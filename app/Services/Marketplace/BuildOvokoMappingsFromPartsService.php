<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuildOvokoMappingsFromPartsService
{
    public function __construct(private readonly OvokoPartIdExtractor $extractor)
    {
    }

    /**
     * Build or preview Ovoko marketplace mappings from parts.legacy_payload.
     *
     * When $dryRun is true this method only reads parts and marketplace_listings.
     * It does not write listings, sync logs, or call any external APIs.
     *
     * @return array<string, mixed>
     */
    public function run(bool $dryRun = false): array
    {
        if (! Schema::hasTable('parts') || ! Schema::hasTable('marketplace_listings')) {
            throw new \RuntimeException('Required tables parts and marketplace_listings must exist.');
        }

        $summary = $this->emptySummary($dryRun);
        $summary['parts_total'] = DB::table('parts')->count();

        $rowsByOvokoId = $this->collectRowsByOvokoId($summary);
        $summary['unique_ovoko_ids'] = count($rowsByOvokoId);

        $duplicateOvokoIds = [];
        $account = $dryRun ? null : MarketplaceAccount::query()->firstOrCreate(
            ['code' => 'ovoko_main'],
            ['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'status' => 'active']
        );

        foreach ($rowsByOvokoId as $ovokoId => $rows) {
            if (count($rows) > 1) {
                $summary['would_conflict']++;
                $summary['duplicates_count'] += count($rows);
                $duplicateOvokoIds[$ovokoId] = count($rows);
                $this->pushSample($summary['sample_conflict'], [
                    'ovoko_part_id' => $ovokoId,
                    'part_ids' => array_column($rows, 'id'),
                    'count' => count($rows),
                ]);

                if (! $dryRun) {
                    $this->writeConflictListing($ovokoId, $rows, $account?->id);
                }

                continue;
            }

            $row = $rows[0];
            $exists = MarketplaceListing::query()
                ->where('marketplace', 'ovoko')
                ->where('external_offer_id', $ovokoId)
                ->exists();

            if ($exists) {
                $summary['would_update']++;
            } else {
                $summary['would_create']++;
                $this->pushSample($summary['sample_create'], [
                    'ovoko_part_id' => $ovokoId,
                    'part_id' => $row['id'],
                    'sku' => $row['sku'],
                    'title' => $row['name'],
                ]);
            }

            if (! $dryRun) {
                $listing = MarketplaceListing::query()->updateOrCreate(
                    ['marketplace' => 'ovoko', 'external_offer_id' => $ovokoId],
                    $this->listingAttributes($row, $ovokoId, $account?->id)
                );

                MarketplaceSyncLog::query()->create([
                    'marketplace' => 'ovoko',
                    'marketplace_listing_id' => $listing->id,
                    'part_id' => $listing->part_id,
                    'action' => 'build_ovoko_mapping_from_parts',
                    'status' => 'success',
                    'message' => 'ovoko_part_id from parts.legacy_payload',
                    'payload' => ['external_offer_id' => $ovokoId, 'part_id' => $listing->part_id],
                    'created_at' => now(),
                ]);
            }
        }

        arsort($duplicateOvokoIds);
        $summary['top_duplicate_ovoko_ids'] = array_slice($duplicateOvokoIds, 0, 20, true);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function emptySummary(bool $dryRun): array
    {
        return [
            'dry_run' => $dryRun,
            'parts_total' => 0,
            'with_ovoko_id' => 0,
            'unique_ovoko_ids' => 0,
            'duplicates_count' => 0,
            'without_ovoko_id' => 0,
            'would_create' => 0,
            'would_update' => 0,
            'would_conflict' => 0,
            'would_skip' => 0,
            'sample_create' => [],
            'sample_conflict' => [],
            'sample_without_ovoko_id' => [],
            'top_duplicate_ovoko_ids' => [],
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, array<int, array<string, mixed>>> */
    private function collectRowsByOvokoId(array &$summary): array
    {
        $rowsByOvokoId = [];
        $columns = ['id', 'sku', 'name', 'price', 'quantity', 'legacy_payload'];

        DB::table('parts')
            ->select($columns)
            ->orderBy('id')
            ->chunkById(500, function ($parts) use (&$rowsByOvokoId, &$summary): void {
                foreach ($parts as $part) {
                    $ovokoId = $this->extractor->extract($part->legacy_payload ?? null);
                    if ($ovokoId === null) {
                        $summary['without_ovoko_id']++;
                        $summary['would_skip']++;
                        $this->pushSample($summary['sample_without_ovoko_id'], [
                            'part_id' => $part->id,
                            'sku' => $part->sku,
                            'title' => $part->name,
                        ]);
                        continue;
                    }

                    $summary['with_ovoko_id']++;
                    $rowsByOvokoId[$ovokoId][] = [
                        'id' => $part->id,
                        'sku' => $part->sku,
                        'name' => $part->name,
                        'price' => $part->price,
                        'quantity' => $part->quantity,
                    ];
                }
            });

        return $rowsByOvokoId;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function listingAttributes(array $row, string $ovokoId, ?int $accountId): array
    {
        return [
            'marketplace_account_id' => $accountId,
            'part_id' => $row['id'],
            'sku' => $row['sku'],
            'title' => $row['name'],
            'price' => is_numeric($row['price']) ? (float) $row['price'] : null,
            'quantity' => is_numeric($row['quantity']) ? (int) $row['quantity'] : null,
            'currency' => 'PLN',
            'status' => 'imported',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
            'match_confidence' => 100,
            'match_reason' => 'ovoko_part_id from parts.legacy_payload',
            'raw_payload' => ['source' => 'parts.legacy_payload', 'ovoko_part_id' => $ovokoId, 'part_id' => $row['id']],
            'last_error' => null,
            'last_synced_at' => now(),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeConflictListing(string $ovokoId, array $rows, ?int $accountId): void
    {
        $listing = MarketplaceListing::query()->updateOrCreate(
            ['marketplace' => 'ovoko', 'external_offer_id' => $ovokoId],
            [
                'marketplace_account_id' => $accountId,
                'part_id' => null,
                'sku' => null,
                'title' => 'Conflict: duplicate Ovoko ID '.$ovokoId,
                'price' => null,
                'quantity' => null,
                'currency' => 'PLN',
                'status' => 'imported',
                'sync_status' => 'conflict',
                'match_status' => 'conflict',
                'match_confidence' => 0,
                'match_reason' => 'duplicate ovoko_part_id in parts.legacy_payload',
                'raw_payload' => ['source' => 'parts.legacy_payload', 'ovoko_part_id' => $ovokoId, 'part_ids' => array_column($rows, 'id'), 'count' => count($rows)],
                'last_error' => 'Duplicate Ovoko ID found in multiple parts; not mapped automatically.',
                'last_synced_at' => now(),
            ]
        );

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'ovoko',
            'marketplace_listing_id' => $listing->id,
            'part_id' => null,
            'action' => 'build_ovoko_mapping_from_parts',
            'status' => 'conflict',
            'message' => 'duplicate ovoko_part_id in parts.legacy_payload',
            'payload' => ['external_offer_id' => $ovokoId, 'part_ids' => array_column($rows, 'id')],
            'created_at' => now(),
        ]);
    }

    /** @param array<int, array<string, mixed>> $samples @param array<string, mixed> $sample */
    private function pushSample(array &$samples, array $sample): void
    {
        if (count($samples) < 20) {
            $samples[] = $sample;
        }
    }
}
