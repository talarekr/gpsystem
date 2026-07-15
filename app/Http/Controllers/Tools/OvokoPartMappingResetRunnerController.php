<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\OvokoPartMappingResetRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class OvokoPartMappingResetRunnerController extends Controller
{
    public function index(OvokoPartMappingResetRunnerService $service): View
    {
        $statusError = null;

        try {
            $status = $service->status();
        } catch (\Throwable $e) {
            $status = $this->fallbackStatus($e, 'panel_status');
            $statusError = $this->exceptionPayload($e, 'panel_status');
        }

        return view('admin.tools.ovoko.part-mapping-reset-runner', [
            'status' => $status,
            'statusError' => $statusError,
        ]);
    }

    public function status(OvokoPartMappingResetRunnerService $service): JsonResponse
    {
        try {
            return response()->json($service->status());
        } catch (\Throwable $e) {
            return response()->json($this->fallbackStatus($e, 'status'), 200);
        }
    }

    public function start(Request $request, OvokoPartMappingResetRunnerService $service): JsonResponse|RedirectResponse
    {
        try {
            $result = $service->start($request->only(['mode', 'batch_size', 'delay_seconds', 'confirm']));
        } catch (\Throwable $e) {
            $result = $this->exceptionPayload($e, 'unknown');
        }

        if ($this->shouldReturnJson($request)) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);

        $message = ($result['ok'] ?? false)
            ? 'Runner został uruchomiony.'
            : 'Start zablokowany: '.($result['message'] ?? $result['reason'] ?? 'unknown').' (phase: '.($result['phase'] ?? 'start').')';

        return redirect()->route('admin.tools.ovoko.part-mapping-reset-runner.index')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', $message);
    }

    public function debug(OvokoPartMappingResetRunnerService $service): JsonResponse
    {
        try {
            $debug = $service->debug();
        } catch (\Throwable $e) {
            $debug = [
                'ok' => false,
                'marker' => OvokoPartMappingResetRunnerService::MARKER,
                'route_reached' => true,
                'service_class_loaded' => class_exists(OvokoPartMappingResetRunnerService::class),
                'service_class' => OvokoPartMappingResetRunnerService::class,
                'state_cache_key' => 'local:admin-tools/ovoko-part-mapping-reset-runner.json',
                'current_state' => $this->fallbackStatus($e, 'debug'),
                'can_query_candidates' => false,
                'candidate_query_error' => $this->exceptionPayload($e, 'debug'),
                'safety_flags' => ['read_only' => true, 'no_mutation' => true, 'no_ovoko_request' => true],
            ];
        }

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
        try {
            $result = $service->runNextBatch($request->only(['confirm']));
        } catch (\Throwable $e) {
            $result = $this->exceptionPayload($e, 'run_next_batch');
        }
        if ($this->shouldReturnJson($request)) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect()->route('admin.tools.ovoko.part-mapping-reset-runner.index')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Batch wykonany.' : ('Batch zablokowany: '.($result['message'] ?? $result['reason'] ?? 'unknown')));
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
        try {
            $result = $service->stop($request->only(['confirm']));
        } catch (\Throwable $e) {
            $result = $this->exceptionPayload($e, 'stop');
        }
        if ($this->shouldReturnJson($request)) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect()->route('admin.tools.ovoko.part-mapping-reset-runner.index')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'Runner zatrzymany.' : ('Stop zablokowany: '.($result['message'] ?? $result['reason'] ?? 'unknown')));
    }

    private function shouldReturnJson(Request $request): bool
    {
        return $request->expectsJson() || $request->boolean('json');
    }

    private function fallbackStatus(\Throwable $e, string $phase): array
    {
        Log::error('Ovoko part mapping reset runner controller fallback', [
            'marker' => OvokoPartMappingResetRunnerService::MARKER,
            'phase' => $phase,
            'exception' => $e,
        ]);

        return [
            'ok' => false,
            'marker' => OvokoPartMappingResetRunnerService::MARKER,
            'status' => 'idle',
            'mode' => 'dry_run',
            'total_candidates' => 0,
            'processed' => 0,
            'reset_count' => 0,
            'dry_run_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'remaining' => 0,
            'batch_size' => 10,
            'delay_seconds' => 2,
            'last_batch_results' => [],
            'phase' => $phase,
            'error_class' => $e::class,
            'message' => $e->getMessage(),
        ];
    }

    private function exceptionPayload(\Throwable $e, string $phase): array
    {
        return [
            'ok' => false,
            'marker' => OvokoPartMappingResetRunnerService::MARKER,
            'phase' => $phase,
            'error_class' => $e::class,
            'message' => $e->getMessage(),
        ];
    }
}
