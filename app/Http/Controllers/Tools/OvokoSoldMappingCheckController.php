<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\OvokoPartIdExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OvokoSoldMappingCheckController extends Controller
{
    private const DEFAULT_IDS = [8526,3203,8268,8857,8620,8183,7558,9857,2972,9775,9956,8980,6818,10550,10325,7451,10124,9706,10640,9587,10656,10061,10409,9586,8884,9761,9902,1074,6224,9240,10744,8508,8219,10696,6713,10029,9416,5074,8875,7944,291,10502,9427,7310,659,9216,9560,6762,8925,3001,10423,8809,4533,9818,10130,9396,10564,8355,7419,10857,4036,10819,7087,10221,10614,10897,10319,10714,9770,10102,10903,9886,9748,10547,9557,6141,7515,6778,10678,9034,9488,9865,8182,10648,10573,10422,6722,9067,6844,8807,8197,5962,10908,6777,4286,5614,10757,8171,10795,7320,10702,9481,9031,8472,10809,7019,10469,4513,7418,10936,10953,10212,8816,10559,6195,8095,9535,10060,9766,10037,9532,10930,9655,7900,10431,4341,9051,8680,9637,10220,10598,9266,9887,10588,10990,10742,7911,10599,7752,8054,8130,10546,10233,10351,10088,9704,10991,6977,5815,9921,9271,7893,10628,9020,5762,4303,8776,10284,8138,2059,7124,7725,10101,5636,5747,10027,9160,7887,7466,10941,10294,5067,11021,9480,9211,9280,10845,10609,6859,9422,10844,7546,5941,10571,10521,10643,8131,6067,7144,7619,9250,9196,10365,10622,6758,5413,10929,5484,6957,8437,6340,6276,6277,8137,1602,10874,10405,9623,10122,6585,10501,7267,4260,8463,8390,7890,9801,9128,10444,9291,4770,7601,7308,7577,10216,10735,8677,4097,10921,9006,9164,8038,11029,10519,8900,8373,1246,10688,9555,7007,6718,10611,7575,9387,9982,1777,5694,49,4960,4016,10660,10617,10386,10938,8243,7013,9959,10073,1250,10545,10375,10955,6383,6239,9270,1360,11025,9608,10411,7643,5783,6322,10268,8047,10947,3850,11024,10582,9714,8324,9717,9314,10788,10783,9272,10499,3629,1495,9405,5813,9784,7931,10734,7034,9646,8703,10463,5616,8125,1365,2776,941,9757,7773,8506,5645,7192,7314,9452,9501,8500,8904,9371,9262,10261,6298,6419,10613,9979,3632,1976,8092,8020,11644,10910,10877];

    public function __invoke(Request $request, OvokoPartIdExtractor $extractor): JsonResponse
    {
        $ids = $this->requestedIds($request);
        $warnings = [];
        $errors = [];
        $matchesById = [];

        try {
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
            $errors[] = $this->formatThrowable('unexpected_diagnostic_error', $e);
            $items = collect($ids)->map(fn (string $id): array => $this->buildItem($id, $matchesById[$id] ?? [], [], $warnings))->values();
            $summary = $this->buildSummary($items, []);

            return response()->json($this->payload($ids, $items->all(), $summary, $warnings, $errors));
        }
    }

    private function collectMarketplaceListingMatches(array $ids, array &$warnings, array &$errors): array
    {
        if (! $this->hasTable('marketplace_listings', $warnings, $errors)) {
            return [];
        }

        $required = ['id', 'part_id', 'marketplace'];
        $optional = ['external_offer_id', 'external_listing_id', 'external_inventory_id', 'external_id', 'raw_payload'];
        $available = $this->availableColumns('marketplace_listings', array_merge($required, $optional), $warnings, $errors);

        foreach ($required as $column) {
            if (! in_array($column, $available, true)) {
                $warnings[] = "unavailable source: marketplace_listings missing required column {$column}; skipped marketplace listing mapping";
                return [];
            }
        }

        try {
            $matches = [];
            MarketplaceListing::query()
                ->with('part')
                ->where('marketplace', 'ovoko')
                ->get($available)
                ->each(function (MarketplaceListing $listing) use (&$matches, $ids, $available): void {
                    foreach ($this->listingCandidates($listing, $available) as $candidate) {
                        if (in_array($candidate['value'], $ids, true)) {
                            $matches[$candidate['value']][] = ['part' => $listing->part, 'part_id' => $listing->part_id, 'source' => $candidate['source'], 'value' => $candidate['value'], 'listing_id' => $listing->id];
                        }
                    }
                });

            return $matches;
        } catch (Throwable $e) {
            $errors[] = $this->formatThrowable('marketplace_listings_mapping_error', $e);
            return [];
        }
    }

    private function collectPartMatches(array $ids, array &$matches, OvokoPartIdExtractor $extractor, array &$warnings, array &$errors): void
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
            $query = Part::query()->select($columns);
            $query->where(function ($query) use ($ids, $columns): void {
                $hasExternal = in_array('source_system', $columns, true) && in_array('external_id', $columns, true);
                $hasLegacy = in_array('legacy_payload', $columns, true);

                if ($hasExternal) {
                    $query->where(function ($q) use ($ids): void {
                        $q->whereIn('source_system', ['ovoko', 'rrr'])->whereIn('external_id', $ids);
                    });
                }
                if ($hasLegacy) {
                    $hasExternal ? $query->orWhereNotNull('legacy_payload') : $query->whereNotNull('legacy_payload');
                }
            });

            if (! in_array('source_system', $columns, true) && ! in_array('external_id', $columns, true) && ! in_array('legacy_payload', $columns, true)) {
                $warnings[] = 'unavailable source: parts missing source_system/external_id and legacy_payload; skipped parts mapping';
                return;
            }

            $query->get()->each(function (Part $part) use (&$matches, $ids, $extractor, $columns): void {
                if (in_array('source_system', $columns, true) && in_array('external_id', $columns, true) && in_array((string) $part->external_id, $ids, true) && in_array((string) $part->source_system, ['ovoko', 'rrr'], true)) {
                    $matches[(string) $part->external_id][] = ['part' => $part, 'part_id' => $part->id, 'source' => 'parts.source_system+parts.external_id', 'value' => (string) $part->external_id, 'listing_id' => null];
                }
                if (in_array('legacy_payload', $columns, true)) {
                    $legacy = $extractor->extractWithPath($part->legacy_payload);
                    if (($legacy['id'] ?? null) !== null && in_array((string) $legacy['id'], $ids, true)) {
                        $matches[(string) $legacy['id']][] = ['part' => $part, 'part_id' => $part->id, 'source' => 'parts.legacy_payload.'.($legacy['path'] ?? 'ovoko_part_id'), 'value' => (string) $legacy['id'], 'listing_id' => null];
                    }
                }
            });
        } catch (Throwable $e) {
            $errors[] = $this->formatThrowable('parts_mapping_error', $e);
        }
    }

    private function buildItem(string $id, array $matches, array $localPartUse, array &$warnings): array
    {
        $partIds = $this->uniquePartIds($matches);
        $part = collect($matches)->pluck('part')->filter()->first();
        $status = count($partIds) === 0 ? 'missing' : (count($partIds) > 1 ? 'ambiguous' : (($localPartUse[(string) $partIds[0]] ?? 0) > 1 ? 'duplicate_local_part' : 'found'));
        $planned = $status === 'found' ? ($part?->status === 'sold' ? 'no_change_already_sold' : 'would_mark_sold') : ($status === 'missing' ? 'blocked_missing_mapping' : 'blocked_ambiguous');

        return [
            'ovoko_product_id' => (int) $id,
            'match_status' => $status,
            'local_part_id' => $part?->id,
            'local_part_admin_url' => $part ? url('/admin/parts/'.$part->id.'/edit') : null,
            'local_part_number' => $part?->part_number,
            'local_title' => $part?->name,
            'local_status' => $part?->status,
            'local_status_label' => $this->safeStatusLabel($part, $warnings),
            'local_availability' => $this->safeAvailability($part, $warnings),
            'local_needs_listing' => $part?->needs_listing,
            'local_is_visible_storefront' => $part?->is_visible_storefront,
            'mapping_source' => collect($matches)->pluck('source')->unique()->implode(', ') ?: null,
            'matched_values' => collect($matches)->map(fn (array $m): array => ['source' => $m['source'], 'value' => $m['value'], 'local_part_id' => $m['part_id'], 'marketplace_listing_id' => $m['listing_id']])->unique()->values()->all(),
            'planned_action' => $planned,
            'blockers' => match ($status) { 'missing' => ['missing_mapping'], 'ambiguous' => ['ambiguous_mapping'], 'duplicate_local_part' => ['duplicate_local_part'], default => [] },
            'notes' => $this->notes($status, $partIds),
        ];
    }

    private function listingCandidates(MarketplaceListing $listing, array $columns): array
    {
        $raw = in_array('raw_payload', $columns, true) ? $listing->raw_payload : null;
        if (! is_array($raw)) {
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $raw = is_array($decoded) ? $decoded : [];
        }

        $candidateDefinitions = [
            'external_offer_id' => 'marketplace_listings.external_offer_id',
            'external_listing_id' => 'marketplace_listings.external_listing_id',
            'external_inventory_id' => 'marketplace_listings.external_inventory_id',
            'external_id' => 'marketplace_listings.external_id',
        ];

        $candidates = [];
        foreach ($candidateDefinitions as $column => $source) {
            if (in_array($column, $columns, true)) {
                $candidates[] = ['source' => $source, 'value' => $listing->getAttribute($column)];
            }
        }

        return collect(array_merge($candidates, [
            ['source' => 'marketplace_listings.raw_payload.external_id', 'value' => $raw['external_id'] ?? null],
            ['source' => 'marketplace_listings.raw_payload.ovoko_part_id', 'value' => $raw['ovoko_part_id'] ?? null],
            ['source' => 'marketplace_listings.raw_payload.marketplace_external_id', 'value' => $raw['marketplace_external_id'] ?? null],
            ['source' => 'marketplace_listings.raw_payload.listing_id', 'value' => $raw['listing_id'] ?? null],
            ['source' => 'marketplace_listings.raw_payload.metadata.ovoko_part_id', 'value' => data_get($raw, 'metadata.ovoko_part_id')],
        ]))->map(fn (array $candidate): array => ['source' => $candidate['source'], 'value' => trim((string) $candidate['value'])])->filter(fn (array $candidate): bool => $candidate['value'] !== '' && ! str_starts_with($candidate['value'], 'GPSW-'))->values()->all();
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
            'mapping_tables_fields' => ['marketplace_listings.marketplace=ovoko external_offer_id/external_listing_id/external_inventory_id/external_id/raw_payload ids', 'parts.source_system/external_id', 'parts.legacy_payload ovoko_part_id/_ovoko_part_id'],
            'summary' => $summary,
            'items' => $items,
        ];
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

    private function notes(string $status, array $partIds): string
    {
        return match ($status) {
            'missing' => 'No local mapping found for this Ovoko ID; no action would be possible in stage 2.',
            'ambiguous' => 'More than one local part matched this Ovoko ID: '.implode(',', $partIds),
            'duplicate_local_part' => 'This local part is referenced by more than one requested Ovoko ID; stage 2 must resolve before applying.',
            default => 'Exactly one local part matched; stage 2 would only mark it sold if it is not already sold.',
        };
    }
}
