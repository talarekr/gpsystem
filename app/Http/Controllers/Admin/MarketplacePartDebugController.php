<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\PartMarketplaceRelistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplacePartDebugController extends Controller
{
    public function __invoke(Request $request, PartMarketplaceRelistService $service, PartMarketplaceStatusResolver $resolver): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        if ($partId <= 0) return response()->json(['ok' => false, 'message' => 'Missing required part_id.'], 422);
        $part = Part::query()->with('marketplaceListings')->findOrFail($partId);

        return response()->json($service->diagnostic($part) + [
            'part_marketplace_status_resolver' => $resolver->rowsForPart($part),
        ]);
    }
}
