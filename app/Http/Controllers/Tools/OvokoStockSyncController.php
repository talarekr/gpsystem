<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OvokoStockSyncController extends Controller
{
    private const CONFIRM = 'ovoko-stock-sync';
    private const ENDPOINT_PATH = '/v2/get/parts';
    private const ACTION = 'ovoko_stock_pull';

    public function dryRun(Request $request): JsonResponse
    {
        return response()->json($this->buildPlan($request, false));
    }

    public function apply(Request $request): JsonResponse
    {
        if ($request->query('confirm') !== self::CONFIRM) {
            return response()->json([
                'ok' => false,
                'dry_run' => false,
                'marketplace_write' => false,
                'blockers' => ['confirm_ovoko_stock_sync_required'],
                'warnings' => ['no_changes_applied'],
            ], 422);
        }

        if (! $request->filled('part_id')) {
            return response()->json([
                'ok' => false,
                'dry_run' => false,
                'marketplace_write' => false,
                'blockers' => ['part_id_required_for_apply'],
                'warnings' => ['single_part_apply_only_no_mass_apply'],
            ], 422);
        }

        $plan = $this->buildPlan($request, true);
        $item = $plan['items'][0] ?? null;
        if (($plan['blockers'] ?? []) !== [] || ! $item || ($item['blockers'] ?? []) !== []) {
            $this->writeLog($item, false, 'blocked', $plan['blockers'] ?? ($item['blockers'] ?? []));
            return response()->json($plan, 422);
        }

        if (($item['action'] ?? null) === 'no_change') {
            $this->writeLog($item, false, 'no_change', []);
            return response()->json($plan + ['applied_count' => 0]);
        }

        $applied = false;
        DB::transaction(function () use ($item, &$applied): void {
            $part = Part::query()->lockForUpdate()->find($item['part_id']);
            if (! $part || (bool) $part->needs_listing || $part->status === 'sold') {
                return;
            }

            $part->forceFill($item['planned_local_state'])->save();
            $applied = true;
        });

        $this->writeLog($item, $applied, $applied ? 'success' : 'blocked', $applied ? [] : ['part_unavailable_or_guard_failed_during_apply']);

        return response()->json($plan + ['applied_count' => $applied ? 1 : 0]);
    }

    private function buildPlan(Request $request, bool $apply): array
    {
        $limit = max(1, min(50, (int) $request->query('limit', 1)));
        $partIds = $request->filled('part_id') ? [(int) $request->query('part_id')] : Part::query()
            ->where('needs_listing', false)
            ->when($request->boolean('only_active'), fn ($q) => $q->storefrontVisible())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $items = array_map(fn (int $partId): array => $this->planPart($partId), $partIds);
        $blockers = array_values(array_unique(Arr::flatten(array_map(fn ($i) => $i['blockers'] ?? [], $items))));

        return [
            'ok' => $blockers === [],
            'dry_run' => ! $apply,
            'marketplace_write' => false,
            'endpoints' => ['dry_run' => '/admin/tools/ovoko-stock-sync-dry-run', 'apply' => '/admin/tools/ovoko-stock-sync-apply?confirm='.self::CONFIRM],
            'apply_requires_part_id' => true,
            'updated_fields' => ['quantity', 'status', 'is_visible_storefront'],
            'items' => $items,
            'blockers' => $blockers,
            'warnings' => ['local_stock_only_no_price_no_publish_no_relist_no_end_no_marketplace_writes'],
        ];
    }

    private function planPart(int $partId): array
    {
        $part = Part::query()->find($partId);
        if (! $part) {
            return ['part_id' => $partId, 'ovoko_id' => null, 'ovoko_mapping_source' => null, 'blockers' => ['missing_local_part'], 'action' => 'blocked'];
        }

        $local = $this->localState($part);
        if ((bool) $part->needs_listing) {
            return ['part_id' => $partId, 'ovoko_id' => null, 'ovoko_mapping_source' => null, 'local' => $local, 'blockers' => ['skipped_do_wystawienia'], 'action' => 'blocked'];
        }
        if ($part->status === 'sold') {
            return ['part_id' => $partId, 'ovoko_id' => null, 'ovoko_mapping_source' => null, 'local' => $local, 'blockers' => ['sold_part_blocked'], 'action' => 'blocked'];
        }

        $mapping = $this->resolveOvokoMapping($part);
        if (! ($mapping['ok'] ?? false)) {
            return ['part_id' => $partId, 'ovoko_id' => null, 'ovoko_mapping_source' => null, 'local' => $local, 'blockers' => [$mapping['blocker'] ?? 'missing_ovoko_mapping'], 'action' => 'blocked'];
        }

        $ovokoId = (string) $mapping['ovoko_id'];
        $ovoko = $this->fetchOvokoPart($ovokoId);
        if (! ($ovoko['ok'] ?? false)) {
            return ['part_id' => $partId, 'ovoko_id' => $ovokoId, 'ovoko_mapping_source' => $mapping['source'], 'local' => $local, 'ovoko' => $ovoko, 'blockers' => [$ovoko['blocker'] ?? 'ovoko_api_failed'], 'action' => 'blocked'];
        }

        $quantity = $this->extractQuantity($ovoko['part'] ?? []);
        if ($quantity === null) {
            return ['part_id' => $partId, 'ovoko_id' => $ovokoId, 'ovoko_mapping_source' => $mapping['source'], 'local' => $local, 'ovoko' => $ovoko, 'blockers' => ['ovoko_stock_not_unambiguous'], 'action' => 'blocked'];
        }

        $planned = [
            'quantity' => $quantity,
            'status' => $quantity > 0 ? (in_array($part->status, ['draft', 'archived'], true) ? $part->status : 'ready') : 'draft',
            'is_visible_storefront' => $quantity > 0,
        ];

        return [
            'part_id' => $partId,
            'ovoko_id' => $ovokoId,
            'ovoko_mapping_source' => $mapping['source'],
            'local' => $local,
            'ovoko' => ['quantity' => $quantity, 'status' => data_get($ovoko, 'part.status'), 'http_status' => $ovoko['http_status'] ?? null],
            'planned_local_state' => $planned,
            'action' => $this->sameState($local, $planned) ? 'no_change' : 'update_local_stock',
            'blockers' => [],
        ];
    }

    /** @return array{ok: bool, ovoko_id?: string, source?: string, blocker?: string} */
    private function resolveOvokoMapping(Part $part): array
    {
        $listingMapping = $this->resolveFromMarketplaceListings($part);
        if (($listingMapping['ovoko_id'] ?? null) !== null) {
            return ['ok' => true, 'ovoko_id' => (string) $listingMapping['ovoko_id'], 'source' => (string) $listingMapping['source']];
        }

        $legacyMapping = app(\App\Services\Marketplace\OvokoPartIdExtractor::class)->extractWithPath($part->legacy_payload);
        if (($legacyMapping['id'] ?? null) !== null) {
            return ['ok' => true, 'ovoko_id' => (string) $legacyMapping['id'], 'source' => 'parts.legacy_payload.'.($legacyMapping['path'] ?? 'ovoko_part_id')];
        }

        return ['ok' => false, 'blocker' => 'missing_ovoko_mapping'];
    }

    /** @return array{ovoko_id: ?string, source: ?string} */
    private function resolveFromMarketplaceListings(Part $part): array
    {
        if (! Schema::hasTable('marketplace_listings')) {
            return ['ovoko_id' => null, 'source' => null];
        }

        $columns = ['id', 'external_offer_id', 'external_listing_id', 'external_inventory_id', 'status', 'last_api_status', 'not_seen_in_active_api_at', 'raw_payload'];
        if (Schema::hasColumn('marketplace_listings', 'external_id')) {
            $columns[] = 'external_id';
        }

        $listings = MarketplaceListing::query()
            ->where('marketplace', 'ovoko')
            ->where('part_id', $part->id)
            ->orderByRaw("CASE WHEN status IN ('published','active','ACTIVE','live') AND (last_api_status IS NULL OR last_api_status NOT IN ('ended','inactive','deleted','archived','not_found')) AND not_seen_in_active_api_at IS NULL THEN 0 ELSE 1 END")
            ->latest('id')
            ->get($columns);

        foreach ($listings as $listing) {
            foreach ($this->listingIdCandidates($listing) as $candidate) {
                if ($candidate['ovoko_id'] !== null) {
                    return $candidate;
                }
            }
        }

        return ['ovoko_id' => null, 'source' => null];
    }

    /** @return array<int, array{ovoko_id: ?string, source: ?string}> */
    private function listingIdCandidates(MarketplaceListing $listing): array
    {
        $raw = is_array($listing->raw_payload) ? $listing->raw_payload : [];

        return [
            ['ovoko_id' => $this->blankId($listing->external_offer_id), 'source' => 'marketplace_listing.external_offer_id'],
            ['ovoko_id' => $this->blankId($listing->external_listing_id), 'source' => 'marketplace_listing.external_listing_id'],
            ['ovoko_id' => Schema::hasColumn('marketplace_listings', 'external_id') ? $this->blankId($listing->getAttribute('external_id')) : null, 'source' => 'marketplace_listing.external_id'],
            ['ovoko_id' => $this->blankId($raw['external_id'] ?? null), 'source' => 'marketplace_listing.raw_payload.external_id'],
            ['ovoko_id' => $this->blankId($raw['ovoko_part_id'] ?? null), 'source' => 'marketplace_listing.raw_payload.ovoko_part_id'],
            ['ovoko_id' => $this->blankId($raw['marketplace_external_id'] ?? null), 'source' => 'marketplace_listing.raw_payload.marketplace_external_id'],
            ['ovoko_id' => $this->blankId($raw['listing_id'] ?? null), 'source' => 'marketplace_listing.raw_payload.listing_id'],
            ['ovoko_id' => $this->blankId(data_get($raw, 'metadata.ovoko_part_id')), 'source' => 'marketplace_listing.raw_payload.metadata.ovoko_part_id'],
        ];
    }

    private function blankId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, 'GPSW-') ? null : $value;
    }

    private function fetchOvokoPart(string $id): array
    {
        $account = MarketplaceAccount::query()->where('code', 'ovoko_main')->first();
        if (! $account || ! $account->api_enabled || blank($account->api_base_url)) return ['ok' => false, 'blocker' => 'ovoko_api_not_configured'];
        $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
        foreach (['username', 'password', 'user_token'] as $key) if (blank($credentials[$key] ?? null)) return ['ok' => false, 'blocker' => 'ovoko_api_credentials_missing'];

        try {
            $endpoint = rtrim((string) $account->api_base_url, '/').self::ENDPOINT_PATH.'?limit=100&page=1';
            $response = Http::asForm()->acceptJson()->timeout(30)->post($endpoint, Arr::only($credentials, ['username', 'password', 'user_token']) + ['part_id' => $id, 'id' => $id]);
            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];
            if (! $response->successful()) return ['ok' => false, 'http_status' => $response->status(), 'blocker' => in_array($response->status(), [401, 403], true) ? 'ovoko_api_auth_failed' : ($response->status() === 429 ? 'ovoko_api_rate_limited' : 'ovoko_api_failed')];
            $rows = $this->extractRows($payload);
            $matches = array_values(array_filter($rows, fn (array $row): bool => (string) $this->extractOvokoId($row) === (string) $id));
            if ($matches === []) return ['ok' => false, 'http_status' => $response->status(), 'blocker' => 'missing_ovoko_product'];
            return ['ok' => true, 'http_status' => $response->status(), 'part' => $matches[0], 'matched_count' => count($matches)];
        } catch (Throwable) {
            return ['ok' => false, 'blocker' => 'ovoko_api_exception'];
        }
    }

    private function extractRows(array $payload): array
    {
        $rows = $payload['list'] ?? $payload['data'] ?? $payload['parts'] ?? [];
        if (isset($payload['id']) || isset($payload['part_id'])) $rows = [$payload];
        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    private function extractOvokoId(array $row): mixed { return $row['id'] ?? $row['part_id'] ?? $row['ovoko_id'] ?? $row['rrr_id'] ?? null; }

    private function extractQuantity(array $row): ?int
    {
        foreach (['quantity', 'stock', 'qty', 'amount', 'available_quantity'] as $field) {
            if (array_key_exists($field, $row) && is_numeric($row[$field])) return max(0, (int) $row[$field]);
        }
        return null;
    }

    private function localState(Part $part): array
    {
        return ['quantity' => (int) $part->quantity, 'status' => $part->status, 'is_visible_storefront' => (bool) $part->is_visible_storefront, 'needs_listing' => (bool) $part->needs_listing];
    }

    private function sameState(array $local, array $planned): bool
    {
        return (int) $local['quantity'] === (int) $planned['quantity'] && (string) $local['status'] === (string) $planned['status'] && (bool) $local['is_visible_storefront'] === (bool) $planned['is_visible_storefront'];
    }

    private function writeLog(?array $item, bool $applied, string $status, array $blockers): void
    {
        MarketplaceSyncLog::query()->create([
            'marketplace' => 'ovoko',
            'part_id' => $item['part_id'] ?? null,
            'action' => self::ACTION,
            'status' => $status,
            'http_status' => data_get($item, 'ovoko.http_status'),
            'message' => $applied ? 'Local stock updated from Ovoko read API.' : 'No local stock change applied.',
            'external_id' => isset($item['ovoko_id']) ? (string) $item['ovoko_id'] : null,
            'payload' => [
                'marketplace_write' => false,
                'dry_run' => false,
                'part_id' => $item['part_id'] ?? null,
                'ovoko_id' => $item['ovoko_id'] ?? null,
                'previous_local_state' => $item['local'] ?? null,
                'fetched_ovoko_state' => $item['ovoko'] ?? null,
                'planned_local_state' => $item['planned_local_state'] ?? null,
                'applied_changes' => $applied ? $item['planned_local_state'] ?? [] : [],
                'blockers' => $blockers,
            ],
            'created_at' => now(),
        ]);
    }
}
