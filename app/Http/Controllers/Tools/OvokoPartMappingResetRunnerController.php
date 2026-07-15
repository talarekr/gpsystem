<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\OvokoPartMappingResetRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OvokoPartMappingResetRunnerController extends Controller
{
    public function index(OvokoPartMappingResetRunnerService $service): View
    {
        return view('admin.tools.ovoko.part-mapping-reset-runner', ['status' => $service->status()]);
    }

    public function status(OvokoPartMappingResetRunnerService $service): JsonResponse
    {
        return response()->json($service->status());
    }

    public function start(Request $request, OvokoPartMappingResetRunnerService $service): JsonResponse|RedirectResponse
    {
        $result = $service->start($request->only(['mode', 'batch_size', 'delay_seconds', 'confirm']));
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect()->route('admin.tools.ovoko.part-mapping-reset-runner.index')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Runner został uruchomiony.' : ('Start zablokowany: '.($result['reason'] ?? 'unknown')));
    }

    public function runNextBatch(Request $request, OvokoPartMappingResetRunnerService $service): JsonResponse|RedirectResponse
    {
        $result = $service->runNextBatch($request->only(['confirm']));
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect()->route('admin.tools.ovoko.part-mapping-reset-runner.index')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Batch wykonany.' : ('Batch zablokowany: '.($result['reason'] ?? 'unknown')));
    }

    public function stop(Request $request, OvokoPartMappingResetRunnerService $service): JsonResponse|RedirectResponse
    {
        $result = $service->stop($request->only(['confirm']));
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect()->route('admin.tools.ovoko.part-mapping-reset-runner.index')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Runner zatrzymany.' : ('Stop zablokowany: '.($result['reason'] ?? 'unknown')));
    }
}
