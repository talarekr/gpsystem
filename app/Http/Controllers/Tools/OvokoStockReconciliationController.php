<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OvokoStockReconciliationController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const SOURCE = 'ovoko_stock_reconciliation';
    private const REASON = 'missing_in_ovoko_active_stock';
    private const ENDPOINT_PATH = '/v2/get/parts';

    public function dryRun(Request $request): JsonResponse
    {
        return response()->json($this->reconcile($request, false));
    }

    public function run(Request $request): JsonResponse
    {
        if (! $request->boolean('confirm', false)) {
            $result = $this->reconcile($request, false);
            $result['blockers'][] = 'confirm_1_required_for_local_update';
            $result['dry_run'] = true;
            return response()->json($result, 422);
        }

        return response()->json($this->reconcile($request, true));
    }

    public function check(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $query = Part::query()->where('needs_review', true);
        $sample = (clone $query)->with(['marketplaceListings'])->limit(25)->get()->map(fn (Part $part): array => $this->partSample($part))->all();
        $storefrontCount = Part::query()->where('needs_review', true)->storefrontVisible()->count();

        return response()->json([
            'ok' => true,
            'needs_review_count' => (clone $query)->count(),
            'visible_in_storefront_count' => $storefrontCount,
            'admin_needs_review_count' => (clone $query)->count(),
            'sample' => $sample,
            'blockers' => $storefrontCount > 0 ? ['needs_review_visible_in_storefront'] : [],
            'warnings' => ['diagnostic_only_no_marketplace_writes'],
        ]);
    }

    private function reconcile(Request $request, bool $write): array
    {
        if (! $this->validToken($request)) return ['ok' => false, 'blockers' => ['invalid_token'], 'warnings' => []];

        $sampleLimit = max(1, min(200, (int) $request->query('sample_limit', 50)));
        $warnings = ['read_only_ovoko_api_no_marketplace_writes'];
        $blockers = [];
        $ovoko = $this->fetchOvokoActiveIds($request);
        if (! ($ovoko['ok'] ?? false)) $blockers[] = $ovoko['error'] ?? 'ovoko_api_failed';

        $activeIds = $ovoko['active_ids'] ?? [];
        $candidates = $this->candidateQuery($request)->get();
        $matched = [];
        $missing = [];
        $conflicts = [];
        $alreadyNeedsReview = 0;

        foreach ($candidates as $part) {
            $listing = $this->ovokoListing($part);
            $ids = $this->listingIds($listing);
            if ((bool) $part->needs_review) $alreadyNeedsReview++;
            if ($ids === []) {
                $conflicts[] = $this->partSample($part) + ['conflict' => 'missing_local_ovoko_external_id'];
                continue;
            }
            if (array_intersect($ids, $activeIds) !== []) {
                $matched[] = $this->partSample($part);
            } else {
                $missing[] = $this->partSample($part) + ['checked_ovoko_ids' => $ids];
            }
        }

        $updated = 0;
        if ($write && $blockers === []) {
            $runAt = now();
            foreach ($missing as $row) {
                $part = Part::query()->find($row['part_id']);
                if (! $part) continue;
                $part->update([
                    'needs_review' => true,
                    'review_reason' => self::REASON,
                    'review_source' => self::SOURCE,
                    'review_detected_at' => $runAt,
                    'review_metadata' => [
                        'ovoko_external_ids' => $row['checked_ovoko_ids'] ?? [],
                        'local_quantity' => $part->quantity,
                        'local_status' => $part->status,
                        'run_at' => $runAt->toISOString(),
                        'note' => 'No active record was found in Ovoko read-only API response.',
                    ],
                ]);
                $updated++;
            }
        }

        return [
            'ok' => $blockers === [],
            'dry_run' => ! $write,
            'local_update_only' => $write,
            'ovoko_api_total_count' => $ovoko['total_count'] ?? null,
            'ovoko_active_ids_count' => count($activeIds),
            'local_candidate_parts_count' => $candidates->count(),
            'matched_active_ovoko_count' => count($matched),
            'missing_in_ovoko_active_count' => count($missing),
            'would_mark_needs_review_count' => count(array_filter($missing, fn ($row) => ! ($row['needs_review'] ?? false))),
            'marked_needs_review_count' => $updated,
            'already_needs_review_count' => $alreadyNeedsReview,
            'conflict_count' => count($conflicts),
            'sample_would_mark_needs_review' => array_slice($missing, 0, $sampleLimit),
            'sample_matched' => array_slice($matched, 0, $sampleLimit),
            'sample_conflicts' => array_slice($conflicts, 0, $sampleLimit),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function fetchOvokoActiveIds(Request $request): array
    {
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first() : null;
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        if (! $account || blank($account->api_base_url) || blank($credentials['username'] ?? null) || blank($credentials['password'] ?? null) || blank($credentials['user_token'] ?? null)) {
            return ['ok' => false, 'error' => 'ovoko_api_credentials_missing', 'active_ids' => []];
        }

        $limit = max(1, min(100, (int) $request->query('limit', 100)));
        $page = max(1, (int) $request->query('page', 1));
        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post(rtrim((string) $account->api_base_url, '/').self::ENDPOINT_PATH.'?limit='.$limit.'&page='.$page, Arr::only($credentials, ['username', 'password', 'user_token']));
            $payload = $response->json() ?: [];
            if (! $response->successful() || ($payload['status_code'] ?? null) !== 'R200') {
                return ['ok' => false, 'error' => 'ovoko_api_business_error', 'active_ids' => []];
            }
            $items = $payload['parts'] ?? $payload['data']['parts'] ?? $payload['data'] ?? [];
            $ids = collect(is_array($items) ? $items : [])->flatMap(fn ($item) => $this->ovokoItemIds((array) $item))->filter()->unique()->values()->all();
            return ['ok' => true, 'active_ids' => $ids, 'total_count' => $payload['total_count'] ?? $payload['data']['total_count'] ?? $payload['total'] ?? null];
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'ovoko_api_exception', 'active_ids' => []];
        }
    }

    private function candidateQuery(Request $request): Builder
    {
        $query = Part::query()->with(['marketplaceListings'])->where('quantity', '>', 0)->where(fn (Builder $q) => $q->whereNull('status')->orWhereNotIn('status', ['sold', 'archived']));
        if (! $request->boolean('include_needs_listing', false)) $query->where(fn (Builder $q) => $q->where('needs_listing', false)->orWhereNull('needs_listing'));
        if (! $request->boolean('include_archived', false)) $query->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', 'archived'));
        if ($request->boolean('only_with_ovoko_mapping', true)) $query->whereHas('marketplaceListings', fn (Builder $q) => $q->where('marketplace', 'ovoko'));
        return $query->orderBy('id')->forPage(max(1, (int) $request->query('page', 1)), max(1, min(1000, (int) $request->query('limit', 100))));
    }

    private function ovokoListing(Part $part): ?MarketplaceListing { return $part->marketplaceListings->firstWhere('marketplace', 'ovoko'); }
    private function listingIds(?MarketplaceListing $listing): array { return collect([$listing?->external_offer_id, $listing?->external_listing_id, $listing?->external_inventory_id, $listing?->sku])->filter()->map(fn ($v) => (string) $v)->unique()->values()->all(); }
    private function ovokoItemIds(array $item): array { return collect([$item['id'] ?? null, $item['part_id'] ?? null, $item['external_id'] ?? null, $item['external_listing_id'] ?? null, $item['sku'] ?? null])->filter()->map(fn ($v) => (string) $v)->unique()->values()->all(); }
    private function partSample(Part $part): array { $listing = $this->ovokoListing($part); return ['part_id' => $part->id, 'name' => $part->name, 'part_number' => $part->part_number, 'sku' => $part->sku, 'quantity' => $part->quantity, 'status' => $part->status, 'needs_review' => (bool) $part->needs_review, 'review_reason' => $part->review_reason, 'ovoko_listing_id' => $listing?->id, 'ovoko_external_id' => $listing?->external_offer_id ?? $listing?->external_listing_id]; }
    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }
    private function invalidTokenResponse(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
}
