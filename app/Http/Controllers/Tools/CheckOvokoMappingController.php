<?php

namespace App\Http\Controllers\Tools;

use App\Filament\Resources\MarketplaceListingResource;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\AllegroOfferExtractor;
use App\Services\Marketplace\EbayListingExtractor;
use App\Services\Marketplace\OvokoPartIdExtractor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $payload = [
            'ok' => ! in_array(false, $tables, true),
            'tables' => $tables,
            'models' => ['MarketplaceAccount'=>class_exists(MarketplaceAccount::class), 'MarketplaceListing'=>class_exists(MarketplaceListing::class), 'MarketplaceSyncLog'=>class_exists(MarketplaceSyncLog::class)],
            'accounts_count' => $tables['marketplace_accounts'] ? MarketplaceAccount::query()->count() : 0,
            'ovoko_listings_count' => $tables['marketplace_listings'] ? MarketplaceListing::query()->where('marketplace', 'ovoko')->count() : 0,
            'marketplace_totals' => $this->marketplaceTotals(),
            'channel_account_totals' => $this->channelAccountTotals(),
            'ovoko_mapped_count' => $counts('mapped'), 'ovoko_unmatched_count' => $counts('unmatched'), 'ovoko_conflict_count' => $counts('conflict'), 'ovoko_ignored_count' => $counts('ignored'), 'ovoko_sync_error_count' => $counts('sync_error'),
            'samples_mapped' => $this->samples('mapped'), 'samples_unmatched' => $this->samples('unmatched'), 'samples_conflict' => $this->samples('conflict'),
            'import_command_exists' => array_key_exists('marketplace:import-ovoko-mapping', Artisan::all()),
            'build_from_parts_command_exists' => array_key_exists('marketplace:build-ovoko-mappings-from-parts', Artisan::all()),
            'unmapped_export_available' => $this->latestUnmappedExport() !== null,
            'latest_unmapped_export' => $this->latestUnmappedExport(),
            'manual_import_command_exists' => array_key_exists('marketplace:import-ovoko-manual-mapping', Artisan::all()),
            'allegro_build_from_parts_command_exists' => array_key_exists('marketplace:build-allegro-mappings-from-parts', Artisan::all()),
            'ebay_build_from_parts_command_exists' => array_key_exists('marketplace:build-ebay-mappings-from-parts', Artisan::all()),
            'command_availability' => [
                'marketplace:import-ovoko-mapping' => array_key_exists('marketplace:import-ovoko-mapping', Artisan::all()),
                'marketplace:build-ovoko-mappings-from-parts' => array_key_exists('marketplace:build-ovoko-mappings-from-parts', Artisan::all()),
                'marketplace:build-allegro-mappings-from-parts' => array_key_exists('marketplace:build-allegro-mappings-from-parts', Artisan::all()),
                'marketplace:build-ebay-mappings-from-parts' => array_key_exists('marketplace:build-ebay-mappings-from-parts', Artisan::all()),
            ],
            'duplicate_external_offer_ids' => $this->duplicateExternalOfferIds(),
            'duplicates_by_marketplace' => $this->duplicatesByMarketplace(),
            'recent_sync_logs' => $tables['marketplace_sync_logs'] ? MarketplaceSyncLog::query()->where('marketplace', 'ovoko')->latest('created_at')->limit(10)->get(['id','marketplace_listing_id','part_id','action','status','message','created_at']) : [],
            'admin_ovoko_url' => MarketplaceListingResource::getUrl('index'),
            'admin_marketplace_url' => MarketplaceListingResource::getUrl('index'),
        ];

        if ((string) request()->query('coverage', '1') !== '0') {
            $payload['laravel_ovoko_id_coverage'] = $this->safeLaravelOvokoIdCoverage();
            $payload['laravel_allegro_id_coverage'] = $this->safeLaravelAllegroIdCoverage();
            $payload['laravel_ebay_de_coverage'] = $this->safeLaravelEbayCoverage('ebay_de');
            $payload['laravel_ebay_fr_coverage'] = $this->safeLaravelEbayCoverage('ebay_fr');
        }

        return response()->json($payload);
    }


    private function safeLaravelEbayCoverage(string $channel): array
    {
        try {
            $extractor = app(EbayListingExtractor::class);
            $detected = []; $samples = []; $without = 0;
            if (! Schema::hasTable('parts') || ! Schema::hasColumn('parts', 'legacy_payload')) return ['ok'=>false, 'table_exists'=>Schema::hasTable('parts')];
            DB::table('parts')->select(['id','sku','name','legacy_payload'])->orderBy('id')->chunkById(500, function ($parts) use (&$detected, &$samples, &$without, $extractor, $channel): void {
                foreach ($parts as $part) {
                    $listing = $extractor->extract($part->legacy_payload ?? null, $channel);
                    if ($listing === null) { $without++; continue; }
                    $detected[] = $listing['external_offer_id'];
                    if (count($samples) < 20) $samples[] = ['part_id'=>$part->id,'sku'=>$part->sku,'name'=>$part->name,'listing'=>$listing];
                }
            });
            $counts = array_count_values($detected); $duplicates = array_filter($counts, fn (int $count): bool => $count > 1); arsort($duplicates);
            return ['ok'=>true,'channel'=>$channel,'detected_ebay_ids_count'=>count($detected),'detected_ebay_ids_unique_count'=>count($counts),'detected_ebay_ids_duplicates_count'=>array_sum($duplicates),'without_detected_ebay_id_count'=>$without,'top_duplicate_ebay_ids'=>array_slice($duplicates, 0, 20, true),'samples'=>$samples];
        } catch (\Throwable $e) { return ['ok'=>false,'channel'=>$channel,'exception_class'=>$e::class,'exception_message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]; }
    }

    /** @return array<string, mixed> */
    private function safeLaravelAllegroIdCoverage(): array
    {
        try {
            $extractor = app(AllegroOfferExtractor::class);
            $detected = []; $samples = []; $without = 0;
            if (! Schema::hasTable('parts') || ! Schema::hasColumn('parts', 'legacy_payload')) return ['ok'=>false, 'table_exists'=>Schema::hasTable('parts')];
            DB::table('parts')->select(['id','sku','name','legacy_payload'])->orderBy('id')->chunkById(500, function ($parts) use (&$detected, &$samples, &$without, $extractor): void {
                foreach ($parts as $part) {
                    $offers = $extractor->extract($part->legacy_payload ?? null);
                    if ($offers === []) { $without++; continue; }
                    foreach ($offers as $offer) $detected[] = $offer['offer_id'];
                    if (count($samples) < 20) $samples[] = ['part_id'=>$part->id,'sku'=>$part->sku,'name'=>$part->name,'offers'=>$offers];
                }
            });
            $counts = array_count_values($detected); $duplicates = array_filter($counts, fn (int $count): bool => $count > 1); arsort($duplicates);
            return ['ok'=>true,'detected_allegro_ids_count'=>count($detected),'detected_allegro_ids_unique_count'=>count($counts),'detected_allegro_ids_duplicates_count'=>array_sum($duplicates),'without_detected_allegro_id_count'=>$without,'top_duplicate_allegro_ids'=>array_slice($duplicates, 0, 20, true),'samples'=>$samples,'known_allegro_keys'=>$extractor->knownKeys()];
        } catch (\Throwable $e) { return ['ok'=>false,'exception_class'=>$e::class,'exception_message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]; }
    }

    private function marketplaceTotals(): array
    {
        if (! Schema::hasTable('marketplace_listings')) return [];
        return MarketplaceListing::query()->select('marketplace', DB::raw('count(*) as total'), DB::raw("sum(sync_status = 'mapped') as mapped"), DB::raw("sum(sync_status = 'conflict') as conflict"), DB::raw("sum(sync_status = 'unmatched') as unmatched"), DB::raw("sum(sync_status = 'ignored') as ignored"), DB::raw("sum(sync_status = 'sync_error') as sync_error"))->whereIn('marketplace', ['ovoko','allegro','ebay_de','ebay_fr'])->groupBy('marketplace')->get()->all();
    }

    private function channelAccountTotals(): array
    {
        if (! Schema::hasTable('marketplace_listings')) return [];
        return MarketplaceListing::query()->leftJoin('marketplace_accounts', 'marketplace_accounts.id', '=', 'marketplace_listings.marketplace_account_id')->select('marketplace_listings.marketplace','marketplace_accounts.code','marketplace_accounts.name', DB::raw('count(*) as total'), DB::raw("sum(marketplace_listings.sync_status = 'mapped') as mapped"), DB::raw("sum(marketplace_listings.sync_status = 'conflict') as conflict"), DB::raw("sum(marketplace_listings.sync_status = 'unmatched') as unmatched"), DB::raw("sum(marketplace_listings.sync_status = 'ignored') as ignored"), DB::raw("sum(marketplace_listings.sync_status = 'sync_error') as sync_error"))->whereIn('marketplace_listings.marketplace', ['ovoko','allegro','ebay_de','ebay_fr'])->groupBy('marketplace_listings.marketplace','marketplace_accounts.code','marketplace_accounts.name')->get()->all();
    }

    private function duplicatesByMarketplace(): array
    {
        if (! Schema::hasTable('marketplace_listings')) return [];
        return MarketplaceListing::query()->select('marketplace','external_offer_id', DB::raw('count(*) as listings_count'))->whereIn('marketplace', ['ovoko','allegro','ebay_de','ebay_fr'])->whereNotNull('external_offer_id')->groupBy('marketplace','external_offer_id')->havingRaw('count(*) > 1')->orderByDesc('listings_count')->limit(50)->get()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function safeLaravelOvokoIdCoverage(): array
    {
        try {
            return $this->laravelOvokoIdCoverage();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
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
                'without_detected_ovoko_id_count' => 0,
                'top_duplicate_ovoko_ids' => [],
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

        $samples = [];
        $detectedIds = [];
        $withoutDetectedId = 0;
        $extractor = app(OvokoPartIdExtractor::class);

        if ($columns['legacy_payload'] ?? false) {
            $select = ['id', 'name', 'legacy_payload'];
            foreach (['sku', 'part_number', 'external_id', 'source_system'] as $column) {
                if ($columns[$column] ?? false) {
                    $select[] = $column;
                }
            }

            DB::table('parts')
                ->select($select)
                ->orderBy('id')
                ->chunkById(500, function ($parts) use (&$samples, &$detectedIds, &$withoutDetectedId, $extractor): void {
                    foreach ($parts as $part) {
                        $ovokoId = $extractor->extract($part->legacy_payload ?? null);
                        if ($ovokoId === null) {
                            $withoutDetectedId++;
                            continue;
                        }

                        $detectedIds[] = $ovokoId;
                        if (count($samples) < 20) {
                            $samples[] = [
                                'part_id' => $part->id,
                                'name' => $part->name,
                                'sku' => $part->sku ?? null,
                                'part_number' => $part->part_number ?? null,
                                'external_id' => $part->external_id ?? null,
                                'source_system' => $part->source_system ?? null,
                                'detected_ovoko_part_id' => $ovokoId,
                            ];
                        }
                    }
                });
        }

        $idCounts = array_count_values($detectedIds);
        $duplicates = array_filter($idCounts, fn (int $count): bool => $count > 1);
        arsort($duplicates);
        $uniqueDetectedIds = count($idCounts);

        return [
            'ok' => true,
            'table_exists' => true,
            'columns' => $columns,
            'counts' => $counts,
            'samples' => $samples,
            'detected_ovoko_ids_count' => count($detectedIds),
            'detected_ovoko_ids_unique_count' => $uniqueDetectedIds,
            'detected_ovoko_ids_duplicates_count' => array_sum($duplicates),
            'top_duplicate_ovoko_ids' => array_slice($duplicates, 0, 20, true),
            'without_detected_ovoko_id_count' => $withoutDetectedId,
            'known_ovoko_id_paths' => $extractor->knownPaths(),
            'recommendation' => $this->ovokoMappingRecommendation($columns, $counts, $uniqueDetectedIds),
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
            ->limit(20)
            ->get()
            ->map(function ($part): array {
                $legacyPayload = $this->payloadToString($part->legacy_payload ?? null);

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

    private function payloadToString(mixed $payload): string
    {
        if ($payload === null) {
            return '';
        }

        if (is_scalar($payload) || $payload instanceof \Stringable) {
            return (string) $payload;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
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

    private function latestUnmappedExport(): ?array
    {
        $files = collect(Storage::disk('local')->files('exports'))
            ->filter(fn (string $file): bool => str_starts_with(basename($file), 'ovoko_unmapped_') && str_ends_with($file, '.csv'))
            ->sortByDesc(fn (string $file): int => Storage::disk('local')->lastModified($file))
            ->values();

        $latest = $files->first();
        if (! is_string($latest)) {
            return null;
        }

        return [
            'file' => Storage::disk('local')->path($latest),
            'download_url' => url('/storage/'.$latest),
            'generated_at' => date(DATE_ATOM, Storage::disk('local')->lastModified($latest)),
            'size_bytes' => Storage::disk('local')->size($latest),
        ];
    }

    private function duplicateExternalOfferIds(): array
    {
        if (! Schema::hasTable('marketplace_listings')) return [];

        return MarketplaceListing::query()
            ->select('external_offer_id', DB::raw('count(*) as listings_count'))
            ->whereIn('marketplace', ['ovoko','allegro','ebay_de','ebay_fr'])
            ->whereNotNull('external_offer_id')
            ->groupBy('external_offer_id')
            ->havingRaw('count(*) > 1')
            ->orderByDesc('listings_count')
            ->limit(20)
            ->get()
            ->all();
    }

    private function samples(string $status): array
    {
        if (! Schema::hasTable('marketplace_listings')) return [];
        return MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', $status)->limit(5)->get(['id','external_offer_id','part_id','sku','title','match_status','sync_status','match_confidence','match_reason'])->all();
    }
}
