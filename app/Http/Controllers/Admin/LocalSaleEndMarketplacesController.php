<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\LocalSaleEndMarketplacesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalSaleEndMarketplacesController extends Controller
{
    public function dryRun(Part $part, LocalSaleEndMarketplacesService $service): JsonResponse
    {
        return response()->json($service->dryRun($part));
    }

    public function apply(Request $request, Part $part, LocalSaleEndMarketplacesService $service): JsonResponse
    {
        if ($request->query('confirm') !== 'local-sale-end-marketplaces') {
            return response()->json(['ok' => false, 'message' => 'Missing required confirm=local-sale-end-marketplaces.'], 422);
        }

        return response()->json($service->apply($part));
    }
}
