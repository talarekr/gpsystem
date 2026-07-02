<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOvokoStockSyncRunBatch;
use App\Models\OvokoStockSyncRun;
use App\Models\Part;
use App\Services\Marketplace\OvokoStockSyncRunProcessor;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OvokoStockSyncRunnerController extends Controller
{
    public function index(): View
    {
        $diagnostics = $this->diagnostics();
        $activeRun = null;
        $latestRun = null;

        if ($diagnostics['db_table_exists']) {
            try {
                $activeRun = OvokoStockSyncRun::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();
                $latestRun = OvokoStockSyncRun::query()->latest('id')->first();
            } catch (Throwable $e) {
                $diagnostics['last_error'] = $e->getMessage();
                $diagnostics['blockers'][] = 'ovoko_stock_sync_runs_query_failed';
            }
        }

        return view('admin.tools.ovoko-stock-sync-runner', [
            'activeRun' => $activeRun,
            'latestRun' => $latestRun,
            'batchSize' => OvokoStockSyncRun::BATCH_SIZE,
            'diagnostics' => $diagnostics,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'dry-run');
        if (! in_array($mode, ['dry-run', 'apply'], true)) return response()->json(['ok' => false, 'blockers' => ['invalid_mode']], 422);
        $requiredConfirm = $mode === 'apply' ? 'ovoko-stock-sync-runner-apply' : 'ovoko-stock-sync-runner';
        if ($request->query('confirm') !== $requiredConfirm) return response()->json(['ok' => false, 'mode' => $mode, 'marketplace_write' => false, 'blockers' => ['confirm_'.$requiredConfirm.'_required']], 422);
        $diagnostics = $this->diagnostics();
        if (! $diagnostics['db_table_exists']) return response()->json(['ok' => false, 'mode' => $mode, 'marketplace_write' => false, 'blockers' => ['missing_ovoko_stock_sync_runs_table'], 'diagnostics' => $diagnostics], 503);

        $running = OvokoStockSyncRun::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();
        if ($running) return response()->json(['ok' => false, 'marketplace_write' => false, 'blockers' => ['ovoko_stock_sync_already_running'], 'run' => $running->summary()], 409);

        $run = OvokoStockSyncRun::query()->create([
            'mode' => $mode,
            'status' => 'queued',
            'batch_size' => OvokoStockSyncRun::BATCH_SIZE,
            'total_candidates' => Part::query()->where('needs_listing', false)->count(),
            'recent_results' => [],
            'top_blockers' => [],
        ]);

        ProcessOvokoStockSyncRunBatch::dispatch((int) $run->id);

        return response()->json(['ok' => true, 'run_id' => $run->id, 'status' => $run->fresh()->status, 'mode' => $mode, 'batch_size' => OvokoStockSyncRun::BATCH_SIZE, 'status_url' => route('admin.tools.ovoko-stock-sync-runner.status', ['run' => $run->id], false), 'marketplace_write' => false]);
    }

    public function startBrowser(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'dry-run');
        if (! in_array($mode, ['dry-run', 'apply'], true)) return response()->json(['ok' => false, 'blockers' => ['invalid_mode']], 422);
        $requiredConfirm = $mode === 'apply' ? 'ovoko-stock-sync-runner-apply' : 'ovoko-stock-sync-runner';
        if ($request->query('confirm') !== $requiredConfirm) return response()->json(['ok' => false, 'mode' => $mode, 'marketplace_write' => false, 'blockers' => ['confirm_'.$requiredConfirm.'_required']], 422);
        $diagnostics = $this->diagnostics();
        if (! $diagnostics['db_table_exists']) return response()->json(['ok' => false, 'mode' => $mode, 'marketplace_write' => false, 'blockers' => ['missing_ovoko_stock_sync_runs_table'], 'diagnostics' => $diagnostics], 503);

        $running = OvokoStockSyncRun::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();
        if ($running) return response()->json(['ok' => false, 'marketplace_write' => false, 'blockers' => ['ovoko_stock_sync_already_running'], 'run' => $running->summary()], 409);

        $run = OvokoStockSyncRun::query()->create([
            'mode' => $mode,
            'status' => 'queued',
            'batch_size' => OvokoStockSyncRun::BATCH_SIZE,
            'total_candidates' => Part::query()->where('needs_listing', false)->count(),
            'recent_results' => [],
            'top_blockers' => [],
        ]);

        return response()->json(['ok' => true, 'run_id' => $run->id, 'status' => $run->status, 'mode' => $mode, 'batch_size' => OvokoStockSyncRun::BATCH_SIZE, 'status_url' => route('admin.tools.ovoko-stock-sync-runner.status', ['run' => $run->id], false), 'tick_url' => route('admin.tools.ovoko-stock-sync-runner.tick', ['run' => $run->id, 'confirm' => 'ovoko-stock-sync-runner-tick'], false), 'marketplace_write' => false]);
    }

    public function tick(Request $request, int $run, OvokoStockSyncRunProcessor $processor): JsonResponse
    {
        if ($request->query('confirm') !== 'ovoko-stock-sync-runner-tick') return response()->json(['ok' => false, 'blockers' => ['confirm_ovoko_stock_sync_runner_tick_required'], 'marketplace_write' => false], 422);
        [$model, $blocker] = $this->findRunOrBlocker($run);
        if ($blocker) return $blocker;

        return response()->json($processor->tick($model));
    }

    public function status(int $run): JsonResponse
    {
        [$model, $blocker] = $this->findRunOrBlocker($run);
        if ($blocker) return $blocker;

        return response()->json(['ok' => true, 'diagnostics' => $this->diagnostics()] + $model->summary());
    }

    public function cancel(Request $request, int $run): JsonResponse
    {
        if ($request->query('confirm') !== 'cancel-ovoko-stock-sync-runner') return response()->json(['ok' => false, 'blockers' => ['confirm_cancel_ovoko_stock_sync_runner_required'], 'marketplace_write' => false], 422);
        [$model, $blocker] = $this->findRunOrBlocker($run);
        if ($blocker) return $blocker;
        if (in_array($model->status, ['completed', 'failed', 'cancelled'], true)) return response()->json(['ok' => true] + $model->summary());
        $model->forceFill(['cancel_requested_at' => now()])->save();
        return response()->json(['ok' => true] + $model->fresh()->summary());
    }

    public function diagnosticsEndpoint(): JsonResponse
    {
        return response()->json(['ok' => true, 'diagnostics' => $this->diagnostics()]);
    }

    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'controller_loaded' => true,
            'php_version' => PHP_VERSION,
            'app_env' => app()->environment(),
            'route_loaded' => true,
        ]);
    }

    public function debugMinimal(): JsonResponse
    {
        $checks = [];
        $errors = [];
        $tableExists = false;

        $this->debugCheck($checks, $errors, 'controller_class_exists', function (): bool {
            return class_exists(self::class);
        });

        $this->debugCheck($checks, $errors, 'view_exists', function (): bool {
            return view()->exists('admin.tools.ovoko-stock-sync-runner');
        });

        $this->debugCheck($checks, $errors, 'ovoko_stock_sync_runs_table_exists', function () use (&$tableExists): bool {
            $tableExists = Schema::hasTable('ovoko_stock_sync_runs');

            return $tableExists;
        });

        $this->debugCheck($checks, $errors, 'ovoko_stock_sync_runs_count_select', function () use (&$tableExists): array {
            if (! $tableExists) {
                return ['skipped' => true, 'reason' => 'missing_ovoko_stock_sync_runs_table'];
            }

            return ['count' => DB::table('ovoko_stock_sync_runs')->count()];
        });

        $this->debugCheck($checks, $errors, 'cache_lock_available', function (): bool {
            $lock = Cache::lock('ovoko-stock-sync-runner-debug-minimal', 5);
            $acquired = (bool) $lock->get();
            if ($acquired) {
                $lock->release();
            }

            return $acquired;
        });

        $this->debugCheck($checks, $errors, 'active_run', function () use (&$tableExists): array {
            if (! $tableExists) {
                return ['skipped' => true, 'reason' => 'missing_ovoko_stock_sync_runs_table'];
            }

            $run = OvokoStockSyncRun::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();

            return ['exists' => (bool) $run, 'run' => $run?->summary()];
        });

        return response()->json([
            'ok' => true,
            'checks' => $checks,
            'errors' => $errors,
            'marketplace_write' => false,
        ]);
    }

    private function debugCheck(array &$checks, array &$errors, string $name, callable $callback): void
    {
        try {
            $checks[$name] = [
                'ok' => true,
                'result' => $callback(),
            ];
        } catch (Throwable $e) {
            $checks[$name] = ['ok' => false];
            $errors[$name] = [
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
    }

    private function findRunOrBlocker(int|string $runId): array
    {
        $diagnostics = $this->diagnostics();
        if (! $diagnostics['db_table_exists']) {
            return [null, response()->json(['ok' => false, 'blockers' => ['missing_ovoko_stock_sync_runs_table'], 'diagnostics' => $diagnostics, 'marketplace_write' => false], 503)];
        }

        $run = OvokoStockSyncRun::query()->find($runId);
        if (! $run) {
            return [null, response()->json(['ok' => false, 'blockers' => ['ovoko_stock_sync_run_not_found'], 'diagnostics' => $diagnostics, 'marketplace_write' => false], 404)];
        }

        return [$run, null];
    }

    private function diagnostics(): array
    {
        $lastError = null;
        $blockers = [];
        $tableExists = false;
        $activeRunExists = false;
        $cacheLockAvailable = false;

        try {
            $tableExists = Schema::hasTable('ovoko_stock_sync_runs');
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            $blockers[] = 'ovoko_stock_sync_runs_table_check_failed';
        }

        if (! $tableExists) {
            $blockers[] = 'missing_ovoko_stock_sync_runs_table';
        } else {
            try {
                $activeRunExists = OvokoStockSyncRun::query()->whereIn('status', ['queued', 'running'])->exists();
                $lastError = OvokoStockSyncRun::query()->whereNotNull('last_error')->latest('id')->value('last_error') ?: $lastError;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $blockers[] = 'ovoko_stock_sync_runs_query_failed';
            }
        }

        try {
            $lock = Cache::lock('ovoko-stock-sync-runner-diagnostics', 5);
            $cacheLockAvailable = (bool) $lock->get();
            if ($cacheLockAvailable) $lock->release();
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            $blockers[] = 'cache_lock_unavailable';
        }

        return [
            'db_table_exists' => $tableExists,
            'active_run_exists' => $activeRunExists,
            'queue_required' => false,
            'cache_lock_available' => $cacheLockAvailable,
            'last_error' => $lastError,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }
}
