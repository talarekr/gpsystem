<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceSupportReadOnlyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceSupportSyncDiagnoseController extends Controller
{
    public function __construct(private readonly MarketplaceSupportReadOnlyService $service) {}

    public function diagnose(Request $request, string $marketplace): JsonResponse
    {
        return response()->json($this->service->diagnose($marketplace, $request->boolean('probe')));
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->service->preview((string) $request->query('marketplace', 'allegro')));
    }
}
