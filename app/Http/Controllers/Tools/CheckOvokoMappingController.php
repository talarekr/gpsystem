<?php

namespace App\Http\Controllers\Tools;

use App\Filament\Resources\MarketplaceListingResource;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckOvokoMappingController extends Controller
{
    public function __invoke()
    {
        if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }
        $tables = ['marketplace_accounts'=>Schema::hasTable('marketplace_accounts'), 'marketplace_listings'=>Schema::hasTable('marketplace_listings'), 'marketplace_sync_logs'=>Schema::hasTable('marketplace_sync_logs')];
        $counts = fn ($status) => $tables['marketplace_listings'] ? MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', $status)->count() : 0;
        return response()->json([
            'ok' => ! in_array(false, $tables, true),
            'tables' => $tables,
            'models' => ['MarketplaceAccount'=>class_exists(MarketplaceAccount::class), 'MarketplaceListing'=>class_exists(MarketplaceListing::class), 'MarketplaceSyncLog'=>class_exists(MarketplaceSyncLog::class)],
            'accounts_count' => $tables['marketplace_accounts'] ? MarketplaceAccount::query()->count() : 0,
            'ovoko_listings_count' => $tables['marketplace_listings'] ? MarketplaceListing::query()->where('marketplace', 'ovoko')->count() : 0,
            'mapped_count' => $counts('mapped'), 'unmatched_count' => $counts('unmatched'), 'conflict_count' => $counts('conflict'), 'ignored_count' => $counts('ignored'), 'sync_error_count' => $counts('sync_error'),
            'samples_mapped' => $this->samples('mapped'), 'samples_unmatched' => $this->samples('unmatched'), 'samples_conflict' => $this->samples('conflict'),
            'import_command_exists' => array_key_exists('marketplace:import-ovoko-mapping', Artisan::all()),
            'recent_sync_logs' => $tables['marketplace_sync_logs'] ? MarketplaceSyncLog::query()->where('marketplace', 'ovoko')->latest('created_at')->limit(10)->get(['id','marketplace_listing_id','part_id','action','status','message','created_at']) : [],
            'admin_ovoko_url' => MarketplaceListingResource::getUrl('index'),
            'laravel_ovoko_id_coverage' => $this->laravelOvokoIdCoverage(),
        ]);
    }

    /**
     * Read-only diagnostics to verify whether Ovoko identifiers are already present
     * in migrated Laravel parts before importing woo_ovoko_mapping.csv.
     *
     * @return array<string, mixed>
     */
    private function laravelOvokoIdCoverage(): array
    {
        $columnsToCheck = [
            'ovoko_part_id',
            'rrr_part_id',
            'external_id',
            'source_system',
            'legacy_payload',
            'vehicle_snapshot',
            'sku',
            'part_number',
        ];

        if (! Schema::hasTable('parts')) {
            return [
                'ok' => false,
                'table_exists' => false,
                'columns' => array_fill_keys($columnsToCheck, false),
                'counts' => [],
                'samples' => [],
                'detected_ovoko_ids_count' => 0,
                'detected_ovoko_ids_unique_count' => 0,
                'detected_ovoko_ids_duplicates_count' => 0,
                'recommendation' => 'Tabela parts nie istnieje, więc nie można zbudować mapowania Ovoko z obecnej bazy Laravel. CSV jest nadal potrzebny.',
            ];
        }

        $columns = [];
        foreach ($columnsToCheck as $column) {
            $columns[$column] = Schema::hasColumn('parts', $column);
        }

        $counts = [
            'parts_total' => DB::table('parts')->count(),
            'legacy_payload_contains__ovoko_part_id' => $this->countLike('legacy_payload', '%_ovoko_part_id%', $columns),
            'legacy_payload_contains_ovoko_part_id' => $this->countLike('legacy_payload', '%ovoko_part_id%', $columns),
            'legacy_payload_contains__ovoko_raw_payload' => $this->countLike('legacy_payload', '%_ovoko_raw_payload%', $columns),
            'legacy_payload_contains__ovoko_status' => $this->countLike('legacy_payload', '%_ovoko_status%', $columns),
            'legacy_payload_contains_rrr' => $this->countLike('legacy_payload', '%rrr%', $columns),
            'source_system_contains_ovoko' => $this->countLike('source_system', '%ovoko%', $columns),
            'external_id_not_empty' => $this->countNotEmpty('external_id', $columns),
            'sku_not_empty' => $this->countNotEmpty('sku', $columns),
        ];

        $samples = $this->ovokoIdSamples($columns);
        $detectedIds = $this->detectedOvokoIds($columns);
        $uniqueDetectedIds = array_unique($detectedIds);

        return [
            'ok' => true,
            'table_exists' => true,
            'columns' => $columns,
            'counts' => $counts,
            'samples' => $samples,
            'detected_ovoko_ids_count' => count($detectedIds),
            'detected_ovoko_ids_unique_count' => count($uniqueDetectedIds),
            'detected_ovoko_ids_duplicates_count' => count($detectedIds) - count($uniqueDetectedIds),
            'recommendation' => $this->ovokoMappingRecommendation($columns, $counts, count($uniqueDetectedIds)),
        ];
    }

    /** @param array<string, bool> $columns */
    private function countLike(string $column, string $pattern, array $columns): int
    {
        if (! ($columns[$column] ?? false)) {
            return 0;
        }

        return DB::table('parts')->where($column, 'like', $pattern)->count();
    }

    /** @param array<string, bool> $columns */
    private function countNotEmpty(string $column, array $columns): int
    {
        if (! ($columns[$column] ?? false)) {
            return 0;
        }

        return DB::table('parts')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->count();
    }

    /**
     * @param array<string, bool> $columns
     * @return array<int, array<string, mixed>>
     */
    private function ovokoIdSamples(array $columns): array
    {
        if (! ($columns['legacy_payload'] ?? false)) {
            return [];
        }

        $select = ['id', 'name'];
        foreach (['sku', 'part_number', 'external_id', 'source_system', 'legacy_payload'] as $column) {
            if ($columns[$column] ?? false) {
                $select[] = $column;
            }
        }

        return DB::table('parts')
            ->select($select)
            ->where(function ($query): void {
                $query->where('legacy_payload', 'like', '%ovoko%')
                    ->orWhere('legacy_payload', 'like', '%rrr%');
            })
            ->limit(200)
            ->get()
            ->map(function ($part): array {
                $legacyPayload = (string) ($part->legacy_payload ?? '');

                return [
                    'part_id' => $part->id,
                    'name' => $part->name,
                    'sku' => $part->sku ?? null,
                    'part_number' => $part->part_number ?? null,
                    'external_id' => $part->external_id ?? null,
                    'source_system' => $part->source_system ?? null,
                    'detected_ovoko_part_id' => $this->detectPayloadId($legacyPayload, ['_ovoko_part_id', 'ovoko_part_id']),
                    'detected_rrr_part_id' => $this->detectPayloadId($legacyPayload, ['rrr_part_id', '_rrr_part_id', 'rrr']),
                    'legacy_payload_excerpt' => mb_substr($legacyPayload, 0, 500),
                ];
            })
            ->filter(fn (array $sample): bool => $sample['detected_ovoko_part_id'] !== null || $sample['detected_rrr_part_id'] !== null)
            ->take(20)
            ->values()
            ->all();
    }

    /** @param array<string, bool> $columns */
    private function detectedOvokoIds(array $columns): array
    {
        if (! ($columns['legacy_payload'] ?? false)) {
            return [];
        }

        return DB::table('parts')
            ->where('legacy_payload', 'like', '%ovoko_part_id%')
            ->pluck('legacy_payload')
            ->map(fn ($payload): ?string => $this->detectPayloadId((string) $payload, ['_ovoko_part_id', 'ovoko_part_id']))
            ->filter()
            ->values()
            ->all();
    }

    /** @param array<int, string> $keys */
    private function detectPayloadId(string $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $quotedKey = preg_quote($key, '/');
            if (preg_match('/["\']'.$quotedKey.'["\']\s*[:=]\s*["\']?([A-Za-z0-9_-]+)/i', $payload, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /** @param array<string, bool> $columns */
    private function ovokoMappingRecommendation(array $columns, array $counts, int $uniqueDetectedIds): array
    {
        $canUseLegacyOvoko = ($columns['legacy_payload'] ?? false) && $uniqueDetectedIds > 0;
        $hasExternalIds = ($columns['external_id'] ?? false) && ($counts['external_id_not_empty'] ?? 0) > 0;
        $hasSku = ($columns['sku'] ?? false) && ($counts['sku_not_empty'] ?? 0) > 0;

        return [
            'can_build_mapping_without_csv' => $canUseLegacyOvoko,
            'csv_still_needed' => ! $canUseLegacyOvoko,
            'best_key' => $canUseLegacyOvoko ? 'legacy_payload ovoko_part_id' : ($hasExternalIds ? 'external_id' : ($hasSku ? 'sku' : 'woo_product_id')),
            'notes' => $canUseLegacyOvoko
                ? 'W legacy_payload wykryto Ovoko ID, więc można przygotować mapowanie bez CSV dla rekordów z wykrytym identyfikatorem. CSV nadal może być przydatny do uzupełnienia braków i walidacji duplikatów.'
                : 'Nie wykryto wystarczających Ovoko ID w legacy_payload. CSV pozostaje potrzebny; jako fallback można ocenić external_id, sku albo woo_product_id, zależnie od zgodności z eksportem WooCommerce.',
        ];
    }

    private function samples(string $status): array
    {
        if (! Schema::hasTable('marketplace_listings')) return [];
        return MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', $status)->limit(5)->get(['id','external_offer_id','part_id','sku','title','match_status','sync_status','match_confidence','match_reason'])->all();
    }
}
