<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\JarekGearboxes\JarekGearboxEbayBulkPricePreviewService;
use App\Services\JarekGearboxes\JarekGearboxEbayPriceFetchService;
use App\Services\JarekGearboxes\JarekGearboxEbayPriceApplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class JarekGearboxEbayBulkPriceController extends Controller
{
    public function fetchPreview(Request $request, JarekGearboxEbayPriceFetchService $service): JsonResponse
    {
        return response()->json($service->fetch($this->channel($request), $this->limit($request), max(0, (int) $request->query('offset', 0)), $request->boolean('only_active', true), $request->boolean('only_missing_local_price'), false));
    }

    public function fetchCacheApply(Request $request, JarekGearboxEbayPriceFetchService $service): JsonResponse
    {
        abort_unless($request->input('confirm') === 'FETCH_JAREK_EBAY_PRICES_READ_ONLY_CACHE', 403, 'Explicit cache confirmation is required.');
        return response()->json($service->fetch($this->channel($request), $this->limit($request), max(0, (int) $request->input('offset', 0)), $request->boolean('only_active', true), $request->boolean('only_missing_local_price'), true));
    }
    public function preview(Request $request, JarekGearboxEbayBulkPricePreviewService $service): JsonResponse
    {
        $percent = (float) $request->query('percent', 0);
        abort_unless($percent === 7.0, 422, 'This audited operation requires percent=7.');

        return response()->json($service->preview($percent, $this->channel($request)));
    }

    public function fetchRunner()
    {
        return view('admin.jarek-gearboxes.ebay-price-fetch-runner');
    }

    public function applyRunner()
    {
        return view('admin.jarek-gearboxes.ebay-price-apply-runner');
    }

    public function apply(Request $request, JarekGearboxEbayPriceApplyService $service): JsonResponse
    {
        $blocked = ['ok' => false, 'applied' => false, 'marketplace_write' => false];
        if ((float) $request->input('percent') !== 7.0) return response()->json($blocked + ['error' => 'percent=7 is required'], 422);
        if ($request->input('confirm') !== 'INCREASE_JAREK_EBAY_PRICES_7_PERCENT') return response()->json($blocked + ['error' => 'explicit confirmation is required'], 403);
        if (blank($request->input('snapshot_id'))) return response()->json($blocked + ['error' => 'an accepted preview snapshot_id is required'], 422);
        $limit = (int) $request->input('limit', 0);
        if ($limit < 1 || $limit > 5) return response()->json($blocked + ['error' => 'canary limit between 1 and 5 is required'], 422);
        $this->channel($request);
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ebay_de')->first() : null;
        if (! $account?->api_enabled || ! config('marketplace.external_api_writes_enabled') || ! config('marketplace.jarek_ebay_price_apply_enabled')) {
            return response()->json($blocked + ['error' => 'eBay write connection is disabled'], 409);
        }
        $selected = array_values(array_unique(array_map('intval', (array) $request->input('selected_ids', []))));
        if (count($selected) > $limit) return response()->json($blocked + ['error' => 'selected_ids exceeds canary limit'], 422);
        $result = $service->apply((string) $request->input('snapshot_id'), $limit, max(0, (int) $request->input('offset', 0)), $selected);
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 409);
    }

    private function channel(Request $request): string { $channel = (string) $request->input('channel', 'ebay_de'); abort_unless($channel === 'ebay_de', 422, 'Only channel=ebay_de is supported.'); return $channel; }
    private function limit(Request $request): int { $limit = (int) $request->input('limit', 50); abort_unless($limit >= 1 && $limit <= 100, 422, 'limit must be between 1 and 100.'); return $limit; }
}
