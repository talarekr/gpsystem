<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class OvokoLinkedProductsCheckController extends Controller
{
    private const DEFAULT_IDS = [11691,11690,11689,11688,11687,11686,11685,11684,11683,11682,11681,11680,11679,11678,11677,11675,11674,11673,11672,11671,11670,11669,11668,11664,11656,11653,11649,11647,11645,11644,11640,11639,11638,11636,11635,11633,11631,11630,11629,11628,11627,11626,11625,11624,11623,11621,11619,11618,11617,11613,11612,11609,11572,11552,11543,11538,11502,11485,11196,11191,11068,11061,11060,11059,11058,11057];

    public function __invoke(Request $request): JsonResponse
    {
        $ids = $this->requestedIds($request);
        $listings = MarketplaceListing::query()
            ->with(['part.storageLocation'])
            ->where('marketplace', 'ovoko')
            ->where(function ($query) use ($ids): void {
                $query->whereIn('external_offer_id', $ids)->orWhereIn('external_listing_id', $ids);
            })
            ->get();

        $byOvokoId = [];
        foreach ($listings as $listing) {
            foreach (['external_offer_id', 'external_listing_id'] as $field) {
                $value = trim((string) $listing->{$field});
                if ($value !== '' && in_array($value, $ids, true)) {
                    $byOvokoId[$value][] = [$listing, 'marketplace_listings.'.$field];
                }
            }
        }

        $localPartUse = [];
        foreach ($byOvokoId as $matches) {
            foreach ($matches as [$listing]) {
                if ($listing->part_id) {
                    $localPartUse[(string) $listing->part_id] = ($localPartUse[(string) $listing->part_id] ?? 0) + 1;
                }
            }
        }

        $items = [];
        foreach ($ids as $id) {
            $matches = $byOvokoId[$id] ?? [];
            $partIds = collect($matches)->map(fn ($m) => $m[0]->part_id)->filter()->unique()->values();
            $listing = $matches[0][0] ?? null;
            $part = $listing?->part;
            $status = 'missing';
            if (count($matches) > 1 || $partIds->count() > 1) {
                $status = 'ambiguous';
            } elseif ($part && ($localPartUse[(string) $part->id] ?? 0) > 1) {
                $status = 'duplicate_local_part';
            } elseif ($part) {
                $status = 'found';
            }
            $items[] = [
                'ovoko_product_id' => $id,
                'match_status' => $status,
                'local_part_id' => $part?->id,
                'local_part_number' => $part?->part_number,
                'local_title' => $part?->name,
                'local_status' => $part?->status,
                'local_needs_listing' => $part?->needs_listing,
                'local_storage_location' => $part?->storageLocation?->name,
                'mapping_source' => collect($matches)->pluck(1)->unique()->implode(', ') ?: null,
                'ovoko_local_cache_available' => $listing !== null,
                'notes' => $this->notes($status, $matches, $partIds->all()),
            ];
        }

        $summary = [
            'missing_ovoko_product_ids' => collect($items)->where('match_status', 'missing')->pluck('ovoko_product_id')->values()->all(),
            'ambiguous_ovoko_product_ids' => collect($items)->where('match_status', 'ambiguous')->pluck('ovoko_product_id')->values()->all(),
            'duplicate_local_part_ids' => collect($localPartUse)->filter(fn ($count) => $count > 1)->keys()->map(fn ($id) => (int) $id)->values()->all(),
            'parts_to_list_ovoko_product_ids' => collect($items)->where('local_needs_listing', true)->pluck('ovoko_product_id')->values()->all(),
        ];

        $payload = [
            'ok' => true,
            'dry_run' => true,
            'local_update' => false,
            'marketplace_write' => false,
            'requested_count' => count($ids),
            'found_count' => collect($items)->where('match_status', 'found')->count(),
            'missing_count' => count($summary['missing_ovoko_product_ids']),
            'ambiguous_count' => count($summary['ambiguous_ovoko_product_ids']),
            'duplicate_local_part_count' => count($summary['duplicate_local_part_ids']),
            'in_parts_to_list_count' => collect($items)->where('local_needs_listing', true)->count(),
            'not_in_parts_to_list_count' => collect($items)->whereNotNull('local_part_id')->where('local_needs_listing', '!==', true)->count(),
            'mapping_tables_fields' => ['marketplace_listings.marketplace=ovoko', 'marketplace_listings.external_offer_id', 'marketplace_listings.external_listing_id', 'marketplace_listings.part_id'],
            'summary' => $summary,
            'items' => $items,
        ];

        if (Schema::hasTable('marketplace_sync_logs')) {
            MarketplaceSyncLog::query()->create(['marketplace' => 'ovoko', 'action' => 'linked_products_check', 'status' => 'success', 'message' => 'Dry-run Ovoko linked products diagnostic', 'payload' => $payload, 'created_at' => now()]);
        }

        return response()->json($payload);
    }

    private function requestedIds(Request $request): array
    {
        $raw = (string) $request->query('ids', '');
        $ids = $raw === '' ? self::DEFAULT_IDS : preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return collect($ids)->map(fn ($id) => (string) $id)->filter()->unique()->values()->all();
    }

    private function notes(string $status, array $matches, array $partIds): string
    {
        return match ($status) {
            'missing' => 'No ovoko marketplace listing found for this external ID.',
            'ambiguous' => 'More than one mapping row or local part found: '.implode(',', $partIds),
            'duplicate_local_part' => 'This local part is referenced by more than one requested Ovoko ID.',
            default => 'Single Ovoko marketplace listing maps to one local part.',
        };
    }
}
