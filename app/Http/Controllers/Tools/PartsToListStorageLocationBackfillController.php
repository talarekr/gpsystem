<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\PartsToListStorageLocationBackfillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartsToListStorageLocationBackfillController extends Controller
{
    public function dryRun(Request $request, PartsToListStorageLocationBackfillService $service): JsonResponse
    {
        return response()->json($service->dryRun($this->limit($request, 100)));
    }

    public function apply(Request $request, PartsToListStorageLocationBackfillService $service): JsonResponse
    {
        return response()->json($service->apply($this->limit($request, 10), $request->query('confirm') === PartsToListStorageLocationBackfillService::CONFIRM));
    }

    public function results(Request $request, PartsToListStorageLocationBackfillService $service): JsonResponse
    {
        return response()->json($service->latestResults($this->limit($request, 20)));
    }

    private function limit(Request $request, int $default): int
    {
        return max(1, min(1000, (int) $request->query('limit', $default)));
    }
}
