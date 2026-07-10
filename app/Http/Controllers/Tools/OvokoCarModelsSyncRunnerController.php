<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\Ovoko\OvokoCarModelSyncRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvokoCarModelsSyncRunnerController extends Controller
{
    public function start(Request $request, OvokoCarModelSyncRunnerService $service): JsonResponse
    {
        $result = $service->start($request->only(['batch_size', 'delay_seconds', 'only_missing', 'confirm']));
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function status(OvokoCarModelSyncRunnerService $service): JsonResponse
    {
        return response()->json($service->status());
    }

    public function stop(Request $request, OvokoCarModelSyncRunnerService $service): JsonResponse
    {
        $result = $service->stop($request->only(['confirm']));
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function runNextBatch(Request $request, OvokoCarModelSyncRunnerService $service): JsonResponse
    {
        $runId = (int) $request->input('run_id');
        $result = $service->runNextBatch($runId);
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
