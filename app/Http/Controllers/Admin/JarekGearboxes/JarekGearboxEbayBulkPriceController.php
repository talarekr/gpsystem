<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\JarekGearboxes\JarekGearboxEbayBulkPricePreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class JarekGearboxEbayBulkPriceController extends Controller
{
    public function preview(Request $request, JarekGearboxEbayBulkPricePreviewService $service): JsonResponse
    {
        $percent = (float) $request->query('percent', 0);
        abort_unless($percent === 7.0, 422, 'This audited operation requires percent=7.');

        return response()->json($service->preview($percent));
    }

    public function apply(Request $request): JsonResponse
    {
        $blocked = ['ok' => false, 'applied' => false, 'marketplace_write' => false];
        if ((float) $request->input('percent') !== 7.0) return response()->json($blocked + ['error' => 'percent=7 is required'], 422);
        if ($request->input('confirm') !== 'INCREASE_JAREK_EBAY_PRICES_7_PERCENT') return response()->json($blocked + ['error' => 'explicit confirmation is required'], 403);
        if (blank($request->input('snapshot_id'))) return response()->json($blocked + ['error' => 'an accepted preview snapshot_id is required'], 422);
        $limit = (int) $request->input('limit', 0);
        if ($limit < 1 || $limit > 5) return response()->json($blocked + ['error' => 'canary limit between 1 and 5 is required'], 422);
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ebay_de')->first() : null;
        if (! $account?->api_enabled || ! config('marketplace.external_api_writes_enabled') || ! config('marketplace.ebay_publishing_enabled')) {
            return response()->json($blocked + ['error' => 'eBay write connection is disabled'], 409);
        }

        return response()->json($blocked + ['error' => 'Apply is intentionally not implemented or enabled; obtain separate approval after reviewing the snapshot.'], 501);
    }
}
