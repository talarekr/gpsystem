<?php

namespace App\Http\Controllers\Tools;

use App\Filament\Resources\PartResource;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\OvokoPartIdExtractor;
use App\Services\Marketplace\OvokoListingUrlBackfillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DebugOvokoPartMatchController extends Controller
{
    public function __invoke(Request $request, OvokoPartIdExtractor $extractor, OvokoListingUrlBackfillService $backfill)
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $ovokoPartId = trim((string) $request->query('ovoko_part_id', ''));
        $partId = $request->filled('part_id') ? (int) $request->query('part_id') : null;
        if ($ovokoPartId === '' && $partId === null) {
            return response()->json(['ok' => false, 'error_message' => 'Missing ovoko_part_id or part_id.'], 422);
        }

        if (! Schema::hasTable('parts') || ! Schema::hasTable('marketplace_listings')) {
            return response()->json(['ok' => false, 'error_message' => 'Required tables parts and marketplace_listings must exist.'], 200);
        }

        $listings = MarketplaceListing::query()
            ->where('marketplace', 'ovoko')
            ->when($ovokoPartId !== '', fn ($query) => $query->where('external_offer_id', $ovokoPartId))
            ->when($partId !== null, fn ($query) => $query->where('part_id', $partId))
            ->get(['id', 'part_id', 'match_status', 'sync_status', 'title', 'match_reason', 'external_offer_id']);

        $parts = $ovokoPartId !== '' ? $this->partsWithOvokoId($ovokoPartId, $extractor) : [];
        $diagnosticPart = $partId !== null ? Part::query()->find($partId) : null;
        $titleSamples = $this->titleSamples($extractor);
        $conflictListings = $listings->filter(fn (MarketplaceListing $listing): bool => $listing->sync_status === 'conflict' || $listing->match_status === 'conflict')->values();

        return response()->json([
            'ok' => true,
            'dry_run' => true,
            'read_only' => true,
            'ovoko_part_id' => $ovokoPartId,
            'marketplace_listing' => [
                'listing_exists' => $listings->isNotEmpty(),
                'listing_id' => $listings->first()?->id,
                'part_id' => $listings->first()?->part_id,
                'match_status' => $listings->first()?->match_status,
                'sync_status' => $listings->first()?->sync_status,
                'title' => $listings->first()?->title,
                'match_reason' => $listings->first()?->match_reason,
                'all_matching_listings' => $listings->map(fn (MarketplaceListing $listing): array => $this->listingPayload($listing))->values(),
            ],
            'part_id_diagnostics' => $diagnosticPart ? [
                'part_id' => $diagnosticPart->id,
                'legacy_ovoko_id_sources' => $backfill->partOvokoIdSources($diagnosticPart),
                'selected_legacy_ovoko_id' => $backfill->ovokoIdFromPart($diagnosticPart),
            ] : null,
            'parts_legacy_payload' => [
                'known_ovoko_id_paths' => $extractor->knownPaths(),
                'found_in_parts_count' => count($parts),
                'found_parts' => $parts,
            ],
            'marketplace_conflict' => [
                'has_conflict_listing' => $conflictListings->isNotEmpty(),
                'conflict_listings' => $conflictListings->map(fn (MarketplaceListing $listing): array => $this->listingPayload($listing))->values(),
                'duplicate_parts_with_same_ovoko_id' => count($parts) > 1,
                'duplicate_parts_count' => count($parts),
            ],
            'alternative_search' => [
                'terms' => ['Antena GPS', '4M0035503R', 'AUDI A1', 'REKIN'],
                'samples' => $titleSamples,
            ],
            'recommendation' => $this->recommendation($listings->isNotEmpty(), count($parts), $conflictListings->isNotEmpty()),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function partsWithOvokoId(string $ovokoPartId, OvokoPartIdExtractor $extractor): array
    {
        $found = [];
        Part::query()->select(['id', 'name', 'sku', 'part_number', 'quantity', 'status', 'is_visible_storefront', 'needs_listing', 'legacy_payload'])->orderBy('id')->chunkById(500, function ($parts) use (&$found, $ovokoPartId, $extractor): void {
            foreach ($parts as $part) {
                $match = $extractor->extractWithPath($part->legacy_payload ?? null);
                if (($match['id'] ?? null) !== $ovokoPartId) {
                    continue;
                }
                $found[] = $this->partPayload($part, $match);
            }
        });

        return $found;
    }

    /** @return array<int, array<string, mixed>> */
    private function titleSamples(OvokoPartIdExtractor $extractor): array
    {
        $terms = ['Antena GPS', '4M0035503R', 'AUDI A1', 'REKIN'];
        return Part::query()
            ->select(['id', 'name', 'part_number', 'quantity', 'status', 'legacy_payload'])
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('name', 'like', '%'.$term.'%')->orWhere('part_number', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%');
                }
            })
            ->orderBy('id')
            ->limit(25)
            ->get()
            ->map(function (Part $part) use ($extractor): array {
                $match = $extractor->extractWithPath($part->legacy_payload ?? null);
                return [
                    'part_id' => $part->id,
                    'title' => $part->name,
                    'part_number' => $part->part_number,
                    'quantity' => $part->quantity,
                    'status' => $part->status,
                    'detected_ovoko_part_id' => $match['id'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array{id:?string,path:?string} $match */
    private function partPayload(Part $part, array $match): array
    {
        return [
            'part_id' => $part->id,
            'title' => $part->name,
            'sku' => $part->sku,
            'part_number' => $part->part_number,
            'quantity' => $part->quantity,
            'status' => $part->status,
            'is_visible_storefront' => $part->is_visible_storefront,
            'needs_listing' => $part->needs_listing,
            'detected_ovoko_part_id' => $match['id'] ?? null,
            'detected_path' => $match['path'] ?? null,
            'admin_edit_url' => PartResource::getUrl('edit', ['record' => $part]),
        ];
    }

    private function listingPayload(MarketplaceListing $listing): array
    {
        return ['listing_id' => $listing->id, 'part_id' => $listing->part_id, 'external_offer_id' => $listing->external_offer_id, 'match_status' => $listing->match_status, 'sync_status' => $listing->sync_status, 'title' => $listing->title, 'match_reason' => $listing->match_reason];
    }

    private function recommendation(bool $hasListing, int $partsCount, bool $hasConflict): string
    {
        if ($hasConflict || $partsCount > 1) return 'duplicate_conflict';
        if (! $hasListing && $partsCount === 1) return 'listing_missing_but_part_has_ovoko_id';
        if (! $hasListing && $partsCount === 0) return 'part_missing_from_laravel';
        if ($hasListing && $partsCount === 0) return 'manual_mapping_needed';
        return 'manual_mapping_needed';
    }
}
