<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\OvokoPartMappingResetRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
        try {
            $result = $service->start($request->only(['mode', 'batch_size', 'delay_seconds', 'confirm']));
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'phase' => 'start', 'error_class' => $e::class, 'message' => $e->getMessage()];
        }

        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);

        $message = ($result['ok'] ?? false)
            ? 'Runner został uruchomiony.'
            : 'Start zablokowany: '.($result['message'] ?? $result['reason'] ?? 'unknown').' (phase: '.($result['phase'] ?? 'start').')';

        return redirect()->route('admin.tools.ovoko.part-mapping-reset-runner.index')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', $message);
    }

    public function debug(OvokoPartMappingResetRunnerService $service): JsonResponse
    {
        $debug = $service->debug();
        $debug['routes_registered'] = collect([
            'admin.tools.ovoko.part-mapping-reset-runner.index',
            'admin.tools.ovoko.part-mapping-reset-runner.status',
            'admin.tools.ovoko.part-mapping-reset-runner.start',
            'admin.tools.ovoko.part-mapping-reset-runner.run-next-batch',
            'admin.tools.ovoko.part-mapping-reset-runner.stop',
            'admin.tools.ovoko.part-mapping-reset-runner.debug',
        ])->every(fn (string $name): bool => Route::has($name));
        $debug['last_exception'] = $this->lastRunnerException();

        return response()->json($debug);
    }

    public function runNextBatch(Request $request, OvokoPartMappingResetRunnerService $service): JsonResponse|RedirectResponse
    {
        $result = $service->runNextBatch($request->only(['confirm']));
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect()->route('admin.tools.ovoko.part-mapping-reset-runner.index')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Batch wykonany.' : ('Batch zablokowany: '.($result['reason'] ?? 'unknown')));
    }

    private function lastRunnerException(): ?array
    {
        $path = storage_path('logs/laravel.log');
        if (! is_readable($path)) return null;

        $tail = substr((string) file_get_contents($path), -20000);
        if (! str_contains($tail, OvokoPartMappingResetRunnerService::MARKER) && ! str_contains($tail, 'Ovoko part mapping reset runner')) return null;

        return ['source' => 'storage/logs/laravel.log', 'excerpt' => substr($tail, -2000)];
    }

    public function stop(Request $request, OvokoPartMappingResetRunnerService $service): JsonResponse|RedirectResponse
    {
        $result = $service->stop($request->only(['confirm']));
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect()->route('admin.tools.ovoko.part-mapping-reset-runner.index')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Runner zatrzymany.' : ('Stop zablokowany: '.($result['reason'] ?? 'unknown')));
    }
}
