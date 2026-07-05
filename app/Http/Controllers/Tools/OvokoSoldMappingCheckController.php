<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\OvokoPartIdExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OvokoSoldMappingCheckController extends Controller
{
    private const DEFAULT_IDS = [8526,3203,8268,8857,8620,8183,7558,9857,2972,9775,9956,8980,6818,10550,10325,7451,10124,9706,10640,9587,10656,10061,10409,9586,8884,9761,9902,1074,6224,9240,10744,8508,8219,10696,6713,10029,9416,5074,8875,7944,291,10502,9427,7310,659,9216,9560,6762,8925,3001,10423,8809,4533,9818,10130,9396,10564,8355,7419,10857,4036,10819,7087,10221,10614,10897,10319,10714,9770,10102,10903,9886,9748,10547,9557,6141,7515,6778,10678,9034,9488,9865,8182,10648,10573,10422,6722,9067,6844,8807,8197,5962,10908,6777,4286,5614,10757,8171,10795,7320,10702,9481,9031,8472,10809,7019,10469,4513,7418,10936,10953,10212,8816,10559,6195,8095,9535,10060,9766,10037,9532,10930,9655,7900,10431,4341,9051,8680,9637,10220,10598,9266,9887,10588,10990,10742,7911,10599,7752,8054,8130,10546,10233,10351,10088,9704,10991,6977,5815,9921,9271,7893,10628,9020,5762,4303,8776,10284,8138,2059,7124,7725,10101,5636,5747,10027,9160,7887,7466,10941,10294,5067,11021,9480,9211,9280,10845,10609,6859,9422,10844,7546,5941,10571,10521,10643,8131,6067,7144,7619,9250,9196,10365,10622,6758,5413,10929,5484,6957,8437,6340,6276,6277,8137,1602,10874,10405,9623,10122,6585,10501,7267,4260,8463,8390,7890,9801,9128,10444,9291,4770,7601,7308,7577,10216,10735,8677,4097,10921,9006,9164,8038,11029,10519,8900,8373,1246,10688,9555,7007,6718,10611,7575,9387,9982,1777,5694,49,4960,4016,10660,10617,10386,10938,8243,7013,9959,10073,1250,10545,10375,10955,6383,6239,9270,1360,11025,9608,10411,7643,5783,6322,10268,8047,10947,3850,11024,10582,9714,8324,9717,9314,10788,10783,9272,10499,3629,1495,9405,5813,9784,7931,10734,7034,9646,8703,10463,5616,8125,1365,2776,941,9757,7773,8506,5645,7192,7314,9452,9501,8500,8904,9371,9262,10261,6298,6419,10613,9979,3632,1976,8092,8020,11644,10910,10877];

    public function __invoke(Request $request): JsonResponse
    {
        $ids = [];
        $warnings = [];
        $errors = [];
        $matchesById = [];

        try {
            $ids = $this->requestedIds($request);

            if ($request->filled('debug_step')) {
                return $this->debugStep((string) $request->query('debug_step'), $ids);
            }

            /** @var OvokoPartIdExtractor $extractor */
            $extractor = app(OvokoPartIdExtractor::class);
            $matchesById = $this->collectMarketplaceListingMatches($ids, $warnings, $errors);
            $this->collectPartMatches($ids, $matchesById, $extractor, $warnings, $errors);

            $localPartUse = [];
            foreach ($matchesById as $matches) {
                foreach ($this->uniquePartIds($matches) as $partId) {
                    $localPartUse[(string) $partId] = ($localPartUse[(string) $partId] ?? 0) + 1;
                }
            }

            $items = collect($ids)->map(fn (string $id): array => $this->buildItem($id, $matchesById[$id] ?? [], $localPartUse, $warnings))->values();
            $summary = $this->buildSummary($items, $localPartUse);

            return response()->json($this->payload($ids, $items->all(), $summary, $warnings, $errors));
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'dry_run' => true,
                'local_update' => false,
                'marketplace_write' => false,
                'error_class' => get_class($e),
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace_head' => collect($e->getTrace())->take(5)->values()->all(),
                'errors' => [[
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]],
                'warnings' => [],
                'requested_count' => 0,
                'items' => [],
            ], 200);
        }
    }

    private function collectMarketplaceListingMatches(array $ids, array &$warnings, array &$errors, bool $loadPartDetails = true): array
    {
        if (! $this->hasTable('marketplace_listings', $warnings, $errors)) {
            return [];
        }

        $required = ['id', 'part_id', 'marketplace'];
        $optional = ['external_offer_id', 'external_listing_id', 'external_inventory_id', 'raw_payload'];
        $available = $this->availableColumns('marketplace_listings', array_merge($required, $optional), $warnings, $errors);

        foreach ($required as $column) {
            if (! in_array($column, $available, true)) {
                $warnings[] = "unavailable source: marketplace_listings missing required column {$column}; skipped marketplace listing mapping";
                return [];
            }
        }

        try {
            $matches = [];
            $rows = DB::table('marketplace_listings')
                ->select($available)
                ->where('marketplace', 'ovoko')
                ->get();

            $partIds = $rows->pluck('part_id')->filter()->unique()->values()->all();
            $partsById = $loadPartDetails
                ? Part::query()
                    ->select(['id', 'status', 'part_number', 'name', 'needs_listing', 'is_visible_storefront'])
                    ->whereIn('id', $partIds)
                    ->get()
                    ->keyBy('id')
                : collect();

            foreach ($rows as $listing) {
                foreach ($this->listingCandidatesFromRow($listing, $available) as $candidate) {
                    if (in_array($candidate['value'], $ids, true)) {
                        $matches[$candidate['value']][] = [
                            'part' => $partsById->get($listing->part_id),
                            'part_id' => $listing->part_id,
                            'source' => $candidate['source'],
                            'value' => $candidate['value'],
                            'listing_id' => $listing->id,
                        ];
                    }
                }
            }

            return $matches;
        } catch (Throwable $e) {
            $errors[] = $this->formatThrowable('marketplace_listings_mapping_error', $e);
            return [];
        }
    }

    private function collectPartMatches(array $ids, array &$matches, OvokoPartIdExtractor $extractor, array &$warnings, array &$errors, bool $loadPartDetails = true): void
    {
        if (! $this->hasTable('parts', $warnings, $errors)) {
            return;
        }

        $columns = $this->availableColumns('parts', ['id', 'source_system', 'external_id', 'legacy_payload', 'status', 'part_number', 'name', 'needs_listing', 'is_visible_storefront'], $warnings, $errors);
        if (! in_array('id', $columns, true)) {
            $warnings[] = 'unavailable source: parts missing required column id; skipped parts mapping';
            return;
        }

        try {
            if (! in_array('source_system', $columns, true) && ! in_array('external_id', $columns, true) && ! in_array('legacy_payload', $columns, true)) {
                $warnings[] = 'unavailable source: parts missing source_system/external_id and legacy_payload; skipped parts mapping';
                return;
            }

            if (in_array('source_system', $columns, true) && in_array('external_id', $columns, true)) {
                ($loadPartDetails ? Part::query() : DB::table('parts'))
                    ->select($loadPartDetails ? $columns : array_values(array_intersect($columns, ['id', 'source_system', 'external_id'])))
                    ->whereIn('source_system', ['ovoko', 'rrr'])
                    ->whereIn('external_id', $ids)
                    ->get()
                    ->each(function (object $part) use (&$matches, $ids, $loadPartDetails): void {
                        if (in_array((string) $part->external_id, $ids, true)) {
                            $matches[(string) $part->external_id][] = ['part' => $loadPartDetails ? $part : null, 'part_id' => $part->id, 'source' => 'parts.source_system+parts.external_id', 'value' => (string) $part->external_id, 'listing_id' => null];
                        }
                    });
            }

            if (in_array('legacy_payload', $columns, true)) {
                $this->collectLegacyPartMatches($ids, $matches, $extractor, $errors, $loadPartDetails, 5000);
            }
        } catch (Throwable $e) {
            $errors[] = $this->formatThrowable('parts_mapping_error', $e);
        }
    }

    private function buildItem(string $id, array $matches, array $localPartUse, array &$warnings): array
    {
        $partIds = $this->uniquePartIds($matches);
        $part = collect($matches)->pluck('part')->filter()->first();
        $status = count($partIds) === 0 ? 'missing' : (count($partIds) > 1 ? 'ambiguous' : (($localPartUse[(string) $partIds[0]] ?? 0) > 1 ? 'duplicate_local_part' : 'found'));
        $planned = $status === 'found' ? ($part && $part->status === 'sold' ? 'no_change_already_sold' : 'would_mark_sold') : ($status === 'missing' ? 'blocked_missing_mapping' : 'blocked_ambiguous');

        return [
            'ovoko_product_id' => (int) $id,
            'match_status' => $status,
            'local_part_id' => $part ? $part->id : null,
            'local_part_admin_url' => $part ? url('/admin/parts/'.$part->id.'/edit') : null,
            'local_part_number' => $part ? $part->part_number : null,
            'local_title' => $part ? $part->name : null,
            'local_status' => $part ? $part->status : null,
            'local_status_label' => $this->safeStatusLabel($part, $warnings),
            'local_availability' => $this->safeAvailability($part, $warnings),
            'local_needs_listing' => $part ? $part->needs_listing : null,
            'local_is_visible_storefront' => $part ? $part->is_visible_storefront : null,
            'mapping_source' => collect($matches)->pluck('source')->unique()->implode(', ') ?: null,
            'matched_values' => collect($matches)->map(fn (array $m): array => ['source' => $m['source'], 'value' => $m['value'], 'local_part_id' => $m['part_id'], 'marketplace_listing_id' => $m['listing_id']])->unique()->values()->all(),
            'planned_action' => $planned,
            'blockers' => $this->blockers($status),
            'notes' => $this->notes($status, $partIds),
        ];
    }

    private function listingCandidatesFromRow(object $listing, array $columns): array
    {
        $raw = in_array('raw_payload', $columns, true) ? ($listing->raw_payload ?? null) : null;
        $raw = $this->decodeRawPayload($raw);

        $candidateDefinitions = [
            'external_offer_id' => 'marketplace_listings.external_offer_id',
            'external_listing_id' => 'marketplace_listings.external_listing_id',
            'external_inventory_id' => 'marketplace_listings.external_inventory_id',
        ];

        $candidates = [];
        foreach ($candidateDefinitions as $column => $source) {
            if (in_array($column, $columns, true)) {
                $candidates[] = ['source' => $source, 'value' => $listing->{$column} ?? null];
            }
        }

        return collect(array_merge($candidates, [
            ['source' => 'marketplace_listings.raw_payload.external_id', 'value' => $raw['external_id'] ?? null],
            ['source' => 'marketplace_listings.raw_payload.ovoko_part_id', 'value' => $raw['ovoko_part_id'] ?? null],
            ['source' => 'marketplace_listings.raw_payload.marketplace_external_id', 'value' => $raw['marketplace_external_id'] ?? null],
            ['source' => 'marketplace_listings.raw_payload.listing_id', 'value' => $raw['listing_id'] ?? null],
            ['source' => 'marketplace_listings.raw_payload.metadata.ovoko_part_id', 'value' => data_get($raw, 'metadata.ovoko_part_id')],
        ]))->map(fn (array $candidate): array => ['source' => $candidate['source'], 'value' => trim((string) $candidate['value'])])->filter(fn (array $candidate): bool => $candidate['value'] !== '' && strpos($candidate['value'], 'GPSW-') !== 0)->values()->all();
    }

    private function buildSummary($items, array $localPartUse): array
    {
        return [
            'missing_ovoko_ids' => collect($items)->where('match_status', 'missing')->pluck('ovoko_product_id')->values()->all(),
            'ambiguous_ovoko_ids' => collect($items)->where('match_status', 'ambiguous')->pluck('ovoko_product_id')->values()->all(),
            'duplicate_local_parts' => collect($localPartUse)->filter(fn (int $count): bool => $count > 1)->map(fn (int $count, string $partId): array => ['local_part_id' => (int) $partId, 'requested_ovoko_id_count' => $count])->values()->all(),
            'already_sold_ids' => collect($items)->where('planned_action', 'no_change_already_sold')->pluck('ovoko_product_id')->values()->all(),
            'would_mark_sold_ids' => collect($items)->where('planned_action', 'would_mark_sold')->pluck('ovoko_product_id')->values()->all(),
        ];
    }

    private function payload(array $ids, array $items, array $summary, array $warnings, array $errors): array
    {
        return [
            'ok' => $errors === [] && $warnings === [],
            'dry_run' => true,
            'local_update' => false,
            'marketplace_write' => false,
            'requested_count' => count($ids),
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'mapped_count' => collect($items)->whereIn('match_status', ['found', 'duplicate_local_part'])->count(),
            'missing_count' => count($summary['missing_ovoko_ids']),
            'ambiguous_count' => count($summary['ambiguous_ovoko_ids']),
            'duplicate_local_part_count' => count($summary['duplicate_local_parts']),
            'already_sold_count' => count($summary['already_sold_ids']),
            'not_sold_count' => count($summary['would_mark_sold_ids']),
            'mapping_tables_fields' => ['marketplace_listings.marketplace=ovoko external_offer_id/external_listing_id/external_inventory_id/raw_payload ids', 'parts.source_system/external_id', 'parts.legacy_payload ovoko_part_id/_ovoko_part_id'],
            'summary' => $summary,
            'items' => $items,
        ];
    }

    private function collectLegacyPartMatches(array $ids, array &$matches, OvokoPartIdExtractor $extractor, array &$errors, bool $loadPartDetails = true, int $maxScanned = 5000): array
    {
        $found = collect($matches)->filter(fn (array $idMatches): bool => $idMatches !== [])->keys()->map(fn ($id): string => (string) $id)->all();
        $foundById = array_fill_keys($found, true);
        $scanned = 0;
        $stoppedReason = 'completed';

        $query = $this->legacyCandidateQuery($ids)
            ->select(['id', 'legacy_payload'])
            ->orderBy('id');

        $query->chunkById(200, function ($parts) use ($ids, &$matches, &$errors, $extractor, $loadPartDetails, &$foundById, &$scanned, $maxScanned, &$stoppedReason): bool {
            foreach ($parts as $part) {
                if ($scanned >= $maxScanned) {
                    $stoppedReason = 'max_scanned_reached';
                    return false;
                }

                $scanned++;

                try {
                    $legacy = $extractor->extractWithPath($part->legacy_payload);
                    $legacyId = ($legacy['id'] ?? null) !== null ? (string) $legacy['id'] : null;

                    if ($legacyId !== null && in_array($legacyId, $ids, true)) {
                        $matches[$legacyId][] = [
                            'part' => $loadPartDetails ? Part::query()->select(['id', 'status', 'part_number', 'name', 'needs_listing', 'is_visible_storefront'])->find($part->id) : null,
                            'part_id' => $part->id,
                            'source' => 'parts.legacy_payload.'.($legacy['path'] ?? 'ovoko_part_id'),
                            'value' => $legacyId,
                            'listing_id' => null,
                        ];
                        $foundById[$legacyId] = true;

                        if (count($foundById) >= count($ids)) {
                            $stoppedReason = 'all_ids_found';
                            return false;
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = $this->formatThrowable('parts_legacy_payload_'.$part->id.'_extract_error', $e);
                }
            }

            return true;
        });

        return [
            'partial' => $stoppedReason === 'max_scanned_reached',
            'scanned_count' => $scanned,
            'found_count' => count($foundById),
            'stopped_reason' => $stoppedReason,
        ];
    }

    private function legacyCandidateQuery(array $ids)
    {
        return DB::table('parts')
            ->whereNotNull('legacy_payload')
            ->where(function ($query) use ($ids): void {
                foreach ($this->legacyLikePatterns($ids) as $pattern) {
                    $query->orWhere('legacy_payload', 'like', $pattern);
                }
            });
    }

    private function legacyLikeLookup(array $ids): array
    {
        $patterns = $this->legacyLikePatterns($ids);
        $rows = $this->legacyCandidateQuery($ids)
            ->select(['id', 'legacy_payload'])
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(function (object $row) use ($patterns): array {
                $payload = (string) ($row->legacy_payload ?? '');
                $matched = collect($patterns)->first(fn (string $pattern): bool => str_contains($payload, trim($pattern, '%')));

                return [
                    'part_id' => $row->id,
                    'matched_pattern' => $matched,
                    'legacy_payload_string_length' => strlen($payload),
                ];
            })
            ->values();

        return [
            'patterns' => $patterns,
            'count' => $rows->count(),
            'rows' => $rows,
        ];
    }

    private function legacyLikePatterns(array $ids): array
    {
        return collect($ids)
            ->flatMap(fn (string $id): array => [
                '%"ovoko_part_id":"'.$id.'"%', '%"ovoko_part_id": "'.$id.'"%',
                '%"ovoko_part_id":'.$id.'%', '%"ovoko_part_id": '.$id.'%',
                '%\\"ovoko_part_id\\":\\"'.$id.'\\"%',
            ])
            ->unique()
            ->values()
            ->all();
    }

    private function maxScanned(Request $request): int
    {
        return max(1, min(50000, (int) $request->query('max_scanned', 5000)));
    }

    private function collectFullMatches(array $ids, bool $loadPartDetails = true): array
    {
        $warnings = [];
        $errors = [];
        /** @var OvokoPartIdExtractor $extractor */
        $extractor = app(OvokoPartIdExtractor::class);
        $matches = $this->collectMarketplaceListingMatches($ids, $warnings, $errors, $loadPartDetails);
        $this->collectPartMatches($ids, $matches, $extractor, $warnings, $errors, $loadPartDetails);

        return [$matches, $warnings, $errors];
    }

    private function rawMappings(array $ids, array $matches): array
    {
        return collect($ids)->mapWithKeys(fn (string $id): array => [$id => collect($matches[$id] ?? [])->map(fn (array $match): array => [
            'part_id' => $match['part_id'] ?? null,
            'source' => $match['source'] ?? null,
            'value' => $match['value'] ?? null,
            'marketplace_listing_id' => $match['listing_id'] ?? null,
        ])->unique()->values()->all()])->all();
    }

    private function localPartUse(array $matches): array
    {
        $localPartUse = [];
        foreach ($matches as $idMatches) {
            foreach ($this->uniquePartIds($idMatches) as $partId) {
                $localPartUse[(string) $partId] = ($localPartUse[(string) $partId] ?? 0) + 1;
            }
        }

        return $localPartUse;
    }

    private function mappingStatus(array $matches, array $localPartUse): string
    {
        $partIds = $this->uniquePartIds($matches);

        return count($partIds) === 0 ? 'missing' : (count($partIds) > 1 ? 'ambiguous' : (($localPartUse[(string) $partIds[0]] ?? 0) > 1 ? 'duplicate' : 'mapped'));
    }

    private function debugStep(string $step, array $ids): JsonResponse
    {
        try {
            return match ($step) {

            'full_start' => $this->debugTry($step, fn (): array => [
                'parsed_ids' => $ids,
                'count' => count($ids),
            ]),
            'full_marketplace_lookup' => $this->debugTry($step, function () use ($ids): array {
                $warnings = [];
                $errors = [];
                $matches = $this->collectMarketplaceListingMatches($ids, $warnings, $errors, false);

                return [
                    'mappings' => $this->rawMappings($ids, $matches),
                    'warnings' => array_values(array_unique($warnings)),
                    'errors' => array_values(array_unique($errors)),
                ];
            }),
            'full_parts_lookup' => $this->debugTry($step, function () use ($ids): array {
                $warnings = [];
                $errors = [];
                $matches = [];
                /** @var OvokoPartIdExtractor $extractor */
                $extractor = app(OvokoPartIdExtractor::class);
                $this->collectPartMatches($ids, $matches, $extractor, $warnings, $errors, false);

                return [
                    'mappings' => $this->rawMappings($ids, $matches),
                    'warnings' => array_values(array_unique($warnings)),
                    'errors' => array_values(array_unique($errors)),
                ];
            }),
            'parts_lookup_probe' => $this->debugTry($step, fn (): array => [
                'message' => 'parts lookup debug endpoint is reachable',
            ]),
            'parts_source_external_count' => $this->debugTry($step, fn (): array => [
                'count' => DB::table('parts')
                    ->whereIn('source_system', ['ovoko', 'rrr'])
                    ->whereNotNull('external_id')
                    ->count(),
            ]),
            'parts_source_external_lookup' => $this->debugTry($step, function () use ($ids): array {
                $ids = collect($ids)->map(fn (string $id): string => (string) $id)->take(20)->values()->all();

                return [
                    'sample_ids' => $ids,
                    'rows' => DB::table('parts')
                        ->select('id', 'source_system', 'external_id')
                        ->whereIn('source_system', ['ovoko', 'rrr'])
                        ->whereIn('external_id', $ids)
                        ->limit(50)
                        ->get(),
                ];
            }),
            'parts_legacy_select_one' => $this->debugTry($step, fn (): array => [
                'rows' => DB::table('parts')
                    ->select('id', 'source_system', 'external_id', 'legacy_payload')
                    ->whereNotNull('legacy_payload')
                    ->limit(1)
                    ->get()
                    ->map(fn (object $row): array => $this->legacyPayloadRawRow($row))
                    ->values(),
            ]),
            'parts_legacy_payload_scan_raw' => $this->debugTry($step, fn (): array => [
                'rows' => DB::table('parts')
                    ->select('id', 'legacy_payload')
                    ->whereNotNull('legacy_payload')
                    ->limit(20)
                    ->get()
                    ->map(fn (object $row): array => $this->legacyPayloadRawRow($row))
                    ->values(),
            ]),
            'parts_legacy_payload_json_decode' => $this->debugTry($step, fn (): array => [
                'rows' => DB::table('parts')
                    ->select('id', 'legacy_payload')
                    ->whereNotNull('legacy_payload')
                    ->limit(20)
                    ->get()
                    ->map(fn (object $row): array => $this->legacyPayloadJsonDecodeRow($row))
                    ->values(),
            ]),
            'parts_legacy_extractor_one' => $this->debugTry($step, function (): array {
                $row = DB::table('parts')
                    ->select('id', 'legacy_payload')
                    ->whereNotNull('legacy_payload')
                    ->limit(1)
                    ->first();

                return ['row' => $row ? $this->legacyExtractorRow($row) : null];
            }),
            'parts_legacy_extractor_scan' => $this->debugTry($step, fn (): array => [
                'rows' => DB::table('parts')
                    ->select('id', 'legacy_payload')
                    ->whereNotNull('legacy_payload')
                    ->limit(20)
                    ->get()
                    ->map(fn (object $row): array => $this->legacyExtractorRow($row))
                    ->values(),
            ]),
            'parts_lookup_legacy_like_only' => $this->debugTry($step, function () use ($ids): array {
                return $this->legacyLikeLookup($ids);
            }),
            'parts_lookup_legacy_only' => $this->debugTry($step, function () use ($ids): array {
                /** @var OvokoPartIdExtractor $extractor */
                $extractor = app(OvokoPartIdExtractor::class);
                $matches = [];
                $errors = [];
                $stats = $this->collectLegacyPartMatches($ids, $matches, $extractor, $errors, false, $this->maxScanned(request()));

                return [
                    'mappings' => $this->rawMappings($ids, $matches),
                    'partial' => $stats['partial'],
                    'scanned_count' => $stats['scanned_count'],
                    'found_count' => $stats['found_count'],
                    'stopped_reason' => $stats['stopped_reason'],
                    'errors' => $errors,
                ];
            }),
            'full_merge_mappings' => $this->debugTry($step, function () use ($ids): array {
                [$matches, $warnings, $errors] = $this->collectFullMatches($ids, false);
                $localPartUse = $this->localPartUse($matches);

                return [
                    'mappings' => collect($ids)->mapWithKeys(fn (string $id): array => [$id => [
                        'status' => $this->mappingStatus($matches[$id] ?? [], $localPartUse),
                        'part_ids' => $this->uniquePartIds($matches[$id] ?? []),
                        'matches' => $this->rawMappings([$id], $matches)[$id] ?? [],
                    ]])->all(),
                    'warnings' => array_values(array_unique($warnings)),
                    'errors' => array_values(array_unique($errors)),
                ];
            }),
            'full_load_parts_details' => $this->debugTry($step, function () use ($ids): array {
                [$matches, $warnings, $errors] = $this->collectFullMatches($ids, false);
                $partIds = collect($matches)->flatMap(fn (array $idMatches): array => $this->uniquePartIds($idMatches))->unique()->values()->all();
                $columns = $this->availableColumns('parts', ['id', 'source_system', 'external_id', 'legacy_payload', 'status', 'part_number', 'name', 'needs_listing', 'is_visible_storefront'], $warnings, $errors);

                return [
                    'part_ids' => $partIds,
                    'parts' => $partIds === [] || ! in_array('id', $columns, true) ? [] : DB::table('parts')->select($columns)->whereIn('id', $partIds)->get(),
                    'warnings' => array_values(array_unique($warnings)),
                    'errors' => array_values(array_unique($errors)),
                ];
            }),
            'full_build_items' => $this->debugTry($step, function () use ($ids): array {
                [$matches, $warnings, $errors] = $this->collectFullMatches($ids);
                $localPartUse = $this->localPartUse($matches);
                $items = collect($ids)->map(fn (string $id): array => $this->buildItem($id, $matches[$id] ?? [], $localPartUse, $warnings))->values()->all();

                return [
                    'items' => $items,
                    'warnings' => array_values(array_unique($warnings)),
                    'errors' => array_values(array_unique($errors)),
                ];
            }),
            'full_stats' => $this->debugTry($step, function () use ($ids): array {
                [$matches, $warnings, $errors] = $this->collectFullMatches($ids);
                $localPartUse = $this->localPartUse($matches);
                $items = collect($ids)->map(fn (string $id): array => $this->buildItem($id, $matches[$id] ?? [], $localPartUse, $warnings))->values();
                $summary = $this->buildSummary($items, $localPartUse);

                return [
                    'mapped' => $items->whereIn('match_status', ['found', 'duplicate_local_part'])->count(),
                    'missing' => count($summary['missing_ovoko_ids']),
                    'ambiguous' => count($summary['ambiguous_ovoko_ids']),
                    'duplicate' => count($summary['duplicate_local_parts']),
                    'already_sold' => count($summary['already_sold_ids']),
                    'not_sold' => count($summary['would_mark_sold_ids']),
                    'warnings' => array_values(array_unique($warnings)),
                    'errors' => array_values(array_unique($errors)),
                ];
            }),
            'marketplace_probe' => response()->json(['ok' => true, 'step' => 'marketplace_probe']),
            'marketplace_count' => $this->debugTry($step, fn (): array => [
                'count' => DB::table('marketplace_listings')->count(),
            ]),
            'marketplace_select_one' => $this->debugTry($step, fn (): array => [
                'rows' => DB::table('marketplace_listings')
                    ->select('id', 'part_id', 'marketplace', 'external_offer_id', 'external_listing_id', 'external_inventory_id')
                    ->limit(1)
                    ->get(),
            ]),
            'marketplace_where_marketplace' => $this->debugTry($step, fn (): array => [
                'rows' => DB::table('marketplace_listings')
                    ->select('id', 'part_id', 'marketplace', 'external_offer_id', 'external_listing_id', 'external_inventory_id')
                    ->where('marketplace', 'ovoko')
                    ->limit(1)
                    ->get(),
            ]),
            'marketplace_where_external_fields' => $this->debugTry($step, function () use ($ids): array {
                $ids = collect($ids)->map(fn (string $id): string => (string) $id)->take(20)->values()->all();

                return [
                    'sample_ids' => $ids,
                    'external_offer_id' => DB::table('marketplace_listings')
                        ->select('id', 'part_id', 'marketplace', 'external_offer_id', 'external_listing_id', 'external_inventory_id')
                        ->whereIn('external_offer_id', $ids)
                        ->limit(20)
                        ->get(),
                    'external_listing_id' => DB::table('marketplace_listings')
                        ->select('id', 'part_id', 'marketplace', 'external_offer_id', 'external_listing_id', 'external_inventory_id')
                        ->whereIn('external_listing_id', $ids)
                        ->limit(20)
                        ->get(),
                    'external_inventory_id' => DB::table('marketplace_listings')
                        ->select('id', 'part_id', 'marketplace', 'external_offer_id', 'external_listing_id', 'external_inventory_id')
                        ->whereIn('external_inventory_id', $ids)
                        ->limit(20)
                        ->get(),
                ];
            }),
            'marketplace_payload_scan' => $this->debugTry($step, fn (): array => [
                'rows' => DB::table('marketplace_listings')
                    ->select('id', 'part_id', 'marketplace', 'external_offer_id', 'external_listing_id', 'external_inventory_id', 'raw_payload')
                    ->where('marketplace', 'ovoko')
                    ->limit(50)
                    ->get()
                    ->map(fn (object $row): array => [
                        'id' => $row->id,
                        'part_id' => $row->part_id,
                        'marketplace' => $row->marketplace,
                        'external_offer_id' => $row->external_offer_id,
                        'external_listing_id' => $row->external_listing_id,
                        'external_inventory_id' => $row->external_inventory_id,
                        'raw_payload_type' => gettype($row->raw_payload),
                        'raw_payload_decoded' => $this->decodeRawPayload($row->raw_payload),
                        'raw_payload_json_error' => is_string($row->raw_payload) ? json_last_error_msg() : null,
                    ])
                    ->values(),
            ]),
            default => response()->json([
                'ok' => false,
                'step' => $step,
                'dry_run' => true,
                'local_update' => false,
                'marketplace_write' => false,
                'error_message' => 'Unknown debug_step.',
            ], 200),
        };
        } catch (Throwable $e) {
            return $this->debugThrowableResponse($step, $e);
        }
    }

    private function debugTry(string $step, callable $callback): JsonResponse
    {
        try {
            return response()->json(array_merge([
                'ok' => true,
                'step' => $step,
                'dry_run' => true,
                'local_update' => false,
                'marketplace_write' => false,
            ], $callback()));
        } catch (Throwable $e) {
            return $this->debugThrowableResponse($step, $e);
        }
    }

    private function debugThrowableResponse(string $step, Throwable $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'step' => $step,
            'dry_run' => true,
            'local_update' => false,
            'marketplace_write' => false,
        ] + $this->debugThrowableData($e), 200);
    }

    private function debugThrowableData(Throwable $e): array
    {
        return [
            'error_class' => get_class($e),
            'error_message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace_head' => collect($e->getTrace())->take(5)->values()->all(),
        ];
    }

    private function legacyPayloadRawRow(object $row): array
    {
        $raw = $row->legacy_payload ?? null;

        return [
            'id' => $row->id ?? null,
            'source_system' => $row->source_system ?? null,
            'external_id' => $row->external_id ?? null,
            'legacy_payload_type' => gettype($raw),
            'legacy_payload_string_length' => is_string($raw) ? strlen($raw) : null,
            'legacy_payload_preview' => is_string($raw) ? $this->safePreview($raw) : null,
        ];
    }

    private function legacyPayloadJsonDecodeRow(object $row): array
    {
        $raw = $row->legacy_payload ?? null;
        $decoded = null;
        $jsonError = null;
        $decodedType = null;

        if ($raw === null) {
            $decodedType = 'null';
        } elseif (is_array($raw)) {
            $decoded = $raw;
            $decodedType = 'array';
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $jsonError = json_last_error_msg();
            $decodedType = gettype($decoded);
        } else {
            $decodedType = gettype($raw);
        }

        return [
            'id' => $row->id ?? null,
            'legacy_payload_type' => gettype($raw),
            'legacy_payload_string_length' => is_string($raw) ? strlen($raw) : null,
            'legacy_payload_preview' => is_string($raw) ? $this->safePreview($raw) : null,
            'json_error' => $jsonError,
            'decoded_type' => $decodedType,
            'decoded_is_array' => is_array($decoded),
            'decoded_keys' => is_array($decoded) ? array_slice(array_keys($decoded), 0, 20) : [],
        ];
    }

    private function legacyExtractorRow(object $row): array
    {
        try {
            /** @var OvokoPartIdExtractor $extractor */
            $extractor = app(OvokoPartIdExtractor::class);
            $legacy = $extractor->extractWithPath($row->legacy_payload ?? null);

            return [
                'id' => $row->id ?? null,
                'ok' => true,
                'legacy_payload_type' => gettype($row->legacy_payload ?? null),
                'legacy_payload_string_length' => is_string($row->legacy_payload ?? null) ? strlen($row->legacy_payload) : null,
                'extracted' => $legacy,
            ];
        } catch (Throwable $e) {
            return [
                'id' => $row->id ?? null,
                'ok' => false,
                'legacy_payload_type' => gettype($row->legacy_payload ?? null),
                'legacy_payload_string_length' => is_string($row->legacy_payload ?? null) ? strlen($row->legacy_payload) : null,
            ] + $this->debugThrowableData($e);
        }
    }

    private function safePreview(string $value, int $length = 300): string
    {
        $preview = substr($value, 0, $length);
        if (function_exists('mb_scrub')) {
            return mb_scrub($preview, 'UTF-8');
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $preview) ?? '';
    }

    private function decodeRawPayload(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_object($raw)) {
            return json_decode(json_encode($raw) ?: '[]', true) ?: [];
        }

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function hasTable(string $table, array &$warnings, array &$errors): bool
    {
        try {
            if (Schema::hasTable($table)) return true;
            $warnings[] = "unavailable source: table {$table} does not exist";
        } catch (Throwable $e) {
            $errors[] = $this->formatThrowable("schema_check_{$table}_error", $e);
        }
        return false;
    }

    private function availableColumns(string $table, array $columns, array &$warnings, array &$errors): array
    {
        $available = [];
        foreach ($columns as $column) {
            try {
                if (Schema::hasColumn($table, $column)) {
                    $available[] = $column;
                } else {
                    $warnings[] = "unavailable column: {$table}.{$column}";
                }
            } catch (Throwable $e) {
                $errors[] = $this->formatThrowable("schema_check_{$table}_{$column}_error", $e);
            }
        }
        return $available;
    }

    private function safeStatusLabel(?Part $part, array &$warnings): ?string
    {
        if (! $part) return null;
        try {
            return method_exists($part, 'adminStatusLabel') ? $part->adminStatusLabel() : ($part->status ?: '—');
        } catch (Throwable $e) {
            $warnings[] = 'status label helper failed; returned raw status: '.$e->getMessage();
            return $part->status ?: '—';
        }
    }

    private function safeAvailability(?Part $part, array &$warnings): ?string
    {
        if (! $part) return null;
        try {
            if (method_exists($part, 'adminLocalAvailability')) return $part->adminLocalAvailability();
        } catch (Throwable $e) {
            $warnings[] = 'availability helper failed; returned raw status: '.$e->getMessage();
        }
        return $part->status;
    }

    private function formatThrowable(string $context, Throwable $e): string
    {
        return $context.': '.get_class($e).': '.$e->getMessage();
    }

    private function requestedIds(Request $request): array
    {
        $raw = (string) $request->query('ids', '');
        $ids = $raw === '' ? self::DEFAULT_IDS : preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return collect($ids)->map(fn ($id): string => (string) $id)->filter()->unique()->values()->all();
    }

    private function uniquePartIds(array $matches): array
    {
        return collect($matches)->pluck('part_id')->filter()->unique()->values()->all();
    }

    private function blockers(string $status): array
    {
        if ($status === 'missing') {
            return ['missing_mapping'];
        }
        if ($status === 'ambiguous') {
            return ['ambiguous_mapping'];
        }
        if ($status === 'duplicate_local_part') {
            return ['duplicate_local_part'];
        }
        return [];
    }

    private function notes(string $status, array $partIds): string
    {
        if ($status === 'missing') {
            return 'No local mapping found for this Ovoko ID; no action would be possible in stage 2.';
        }
        if ($status === 'ambiguous') {
            return 'More than one local part matched this Ovoko ID: '.implode(',', $partIds);
        }
        if ($status === 'duplicate_local_part') {
            return 'This local part is referenced by more than one requested Ovoko ID; stage 2 must resolve before applying.';
        }
        return 'Exactly one local part matched; stage 2 would only mark it sold if it is not already sold.';
    }
}
