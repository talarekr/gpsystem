<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\JarekGearboxes\JarekGearboxEbayBulkPricePreviewService;
use App\Services\JarekGearboxes\JarekGearboxEbayPriceFetchService;
use App\Services\JarekGearboxes\JarekGearboxEbayPriceApplyService;
use App\Services\Marketplace\EbayConnectionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class JarekGearboxEbayBulkPriceController extends Controller
{
    public function fetchPreview(Request $request, JarekGearboxEbayPriceFetchService $service): JsonResponse
    {
        return $this->fetchResponse($request, $service, false);
    }

    public function fetchCacheApply(Request $request, JarekGearboxEbayPriceFetchService $service): JsonResponse
    {
        abort_unless($request->input('confirm') === 'FETCH_JAREK_EBAY_PRICES_READ_ONLY_CACHE', 403, 'Explicit cache confirmation is required.');
        return $this->fetchResponse($request, $service, true);
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

    public function applyRunner(EbayConnectionGate $gate)
    {
        return view('admin.jarek-gearboxes.ebay-price-apply-runner', ['ebayConnection' => $gate->status()]);
    }

    public function apply(Request $request, JarekGearboxEbayPriceApplyService $service, EbayConnectionGate $gate): JsonResponse
    {
        $blocked = ['ok' => false, 'applied' => false, 'marketplace_write' => false];
        if ((float) $request->input('percent') !== 7.0) return response()->json($blocked + ['error' => 'percent=7 is required'], 422);
        if ($request->input('confirm') !== 'INCREASE_JAREK_EBAY_PRICES_7_PERCENT') return response()->json($blocked + ['error' => 'explicit confirmation is required'], 403);
        if (blank($request->input('snapshot_id'))) return response()->json($blocked + ['error' => 'an accepted preview snapshot_id is required'], 422);
        $limit = (int) $request->input('limit', 0);
        if ($limit < 1 || $limit > 5) return response()->json($blocked + ['error' => 'canary limit between 1 and 5 is required'], 422);
        $this->channel($request);
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ebay_de')->first() : null;
        if (! $gate->writeEnabled($account)) {
            return response()->json($blocked + ['error' => 'eBay write connection is disabled', 'connection_toggle_url' => route('admin.tools.marketplace.ebay-connection-toggle')], 409);
        }
        if (! config('marketplace.jarek_ebay_price_apply_enabled')) {
            return response()->json($blocked + ['error' => 'Jarek eBay price apply feature is disabled'], 409);
        }
        $selected = array_values(array_unique(array_map('intval', (array) $request->input('selected_ids', []))));
        if (count($selected) > $limit) return response()->json($blocked + ['error' => 'selected_ids exceeds canary limit'], 422);
        $result = $service->apply((string) $request->input('snapshot_id'), $limit, max(0, (int) $request->input('offset', 0)), $selected);
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 409);
    }

    private function channel(Request $request): string { $channel = (string) $request->input('channel', 'ebay_de'); abort_unless($channel === 'ebay_de', 422, 'Only channel=ebay_de is supported.'); return $channel; }
    private function limit(Request $request): int { $limit = (int) $request->input('limit', 50); abort_unless($limit >= 1 && $limit <= 100, 422, 'limit must be between 1 and 100.'); return $limit; }

    private function fetchResponse(Request $request, JarekGearboxEbayPriceFetchService $service, bool $cache): JsonResponse
    {
        $channel = $this->channel($request);
        $limit = $this->limit($request);
        $offset = max(0, (int) $request->input('offset', 0));

        // A full 100-offer read can legitimately exceed common 30/60-second PHP defaults.
        // Keep this narrowly scoped to the read/cache endpoints; marketplace writes never use it.
        if (function_exists('set_time_limit')) {
            set_time_limit(190);
        }

        try {
            $result = $service->fetch(
                $channel,
                $limit,
                $offset,
                $request->boolean('only_active', true),
                $request->boolean('only_missing_local_price'),
                $cache,
            );

            return response()->json($result);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'error_type' => 'server',
                'error' => 'eBay price fetch batch failed.',
                'retryable' => true,
                'recommended_batch_size' => 50,
                'limit' => $limit,
                'offset' => $offset,
                'local_write' => $cache,
                'marketplace_write' => false,
            ], 500);
        }
    }
}
