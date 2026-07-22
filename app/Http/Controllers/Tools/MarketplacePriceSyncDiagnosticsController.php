<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\PriceSync\PartMarketplacePriceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplacePriceSyncDiagnosticsController extends Controller
{
    public function __invoke(Request $request, PartMarketplacePriceSyncService $service): JsonResponse
    {
        $part = $request->integer('part_id') > 0 ? Part::query()->find($request->integer('part_id')) : null;

        return response()->json($service->diagnostics($part));
    }
}
