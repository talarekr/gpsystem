<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\Ovoko\OvokoCarModelSyncRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OvokoCarModelsSyncRunnerController extends Controller
{
    public function index(OvokoCarModelSyncRunnerService $service): View
    {
        $status = $service->status();

        return view('admin.tools.ovoko.car-models-sync-runner', [
            'status' => $status,
            'runId' => $status['run_id'] ?? null,
        ]);
    }

    public function start(Request $request, OvokoCarModelSyncRunnerService $service): JsonResponse|RedirectResponse
    {
        $result = $service->start($request->only(['batch_size', 'delay_seconds', 'only_missing', 'confirm']));

        if ($request->expectsJson()) {
            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        return redirect()
            ->route('admin.tools.ovoko.car-models-sync-runner.index')
            ->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Runner został uruchomiony.' : ('Start zablokowany: '.($result['reason'] ?? 'unknown')));
    }

    public function status(OvokoCarModelSyncRunnerService $service): JsonResponse
    {
        return response()->json($service->status());
    }

    public function stop(Request $request, OvokoCarModelSyncRunnerService $service): JsonResponse|RedirectResponse
    {
        $result = $service->stop($request->only(['confirm']));

        if ($request->expectsJson()) {
            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        return redirect()
            ->route('admin.tools.ovoko.car-models-sync-runner.index')
            ->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Runner został zatrzymany.' : ('Stop zablokowany: '.($result['reason'] ?? 'unknown')));
    }

    public function runNextBatch(Request $request, OvokoCarModelSyncRunnerService $service): JsonResponse|RedirectResponse
    {
        if ($request->input('confirm') !== 'run-next-batch-ovoko-car-models-sync-runner') {
            $result = ['ok' => false, 'blocked' => true, 'reason' => 'missing_confirm_token'];
        } else {
            $runId = (int) $request->input('run_id');
            $result = $service->runNextBatch($runId);
        }

        if ($request->expectsJson()) {
            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        return redirect()
            ->route('admin.tools.ovoko.car-models-sync-runner.index')
            ->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Ręczny batch został uruchomiony.' : ('Run next batch zablokowany: '.($result['reason'] ?? 'unknown')));
    }
}
