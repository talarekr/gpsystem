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

class OvokoStockSyncRunnerController extends Controller
{
    public function index(): View
    {
        $activeRun = OvokoStockSyncRun::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();
        $latestRun = OvokoStockSyncRun::query()->latest('id')->first();

        return view('admin.tools.ovoko-stock-sync-runner', [
            'activeRun' => $activeRun,
            'latestRun' => $latestRun,
            'batchSize' => OvokoStockSyncRun::BATCH_SIZE,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'dry-run');
        if (! in_array($mode, ['dry-run', 'apply'], true)) return response()->json(['ok' => false, 'blockers' => ['invalid_mode']], 422);
        $requiredConfirm = $mode === 'apply' ? 'ovoko-stock-sync-runner-apply' : 'ovoko-stock-sync-runner';
        if ($request->query('confirm') !== $requiredConfirm) return response()->json(['ok' => false, 'mode' => $mode, 'marketplace_write' => false, 'blockers' => ['confirm_'.$requiredConfirm.'_required']], 422);

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

    public function tick(Request $request, OvokoStockSyncRun $run, OvokoStockSyncRunProcessor $processor): JsonResponse
    {
        if ($request->query('confirm') !== 'ovoko-stock-sync-runner-tick') return response()->json(['ok' => false, 'blockers' => ['confirm_ovoko_stock_sync_runner_tick_required'], 'marketplace_write' => false], 422);

        return response()->json($processor->tick($run));
    }

    public function status(OvokoStockSyncRun $run): JsonResponse
    {
        return response()->json(['ok' => true] + $run->summary());
    }

    public function cancel(Request $request, OvokoStockSyncRun $run): JsonResponse
    {
        if ($request->query('confirm') !== 'cancel-ovoko-stock-sync-runner') return response()->json(['ok' => false, 'blockers' => ['confirm_cancel_ovoko_stock_sync_runner_required'], 'marketplace_write' => false], 422);
        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) return response()->json(['ok' => true] + $run->summary());
        $run->forceFill(['cancel_requested_at' => now()])->save();
        return response()->json(['ok' => true] + $run->fresh()->summary());
    }
}
