<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceCategoryTreeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceCategoryTreeImportController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function dryRunImport(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->previewOrImport(false));
    }

    public function debugFetch(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->debugFetch($request->boolean('verbose')));
    }

    public function import(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing local import without confirm=1.', 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false], 422);
        try {
            return response()->json([
                'ok' => false,
                'error_message' => 'Large marketplace category tree imports must use the batch autorunner endpoint.',
                'autorunner_url' => url('/tools/marketplace-category-tree-import-autorun').'?token='.self::TOKEN,
                'local_update' => false,
                'ovoko_write' => false,
                'allegro_write' => false,
                'ebay_write' => false,
            ], 409);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error_message' => $e->getMessage(), 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false], 500);
        }
    }

    public function autorun(Request $request, MarketplaceCategoryTreeImportService $service)
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        $runId = (string) $request->query('run_id', '');
        $latest = $runId === '' ? $service->latestAutorun() : null;
        $status = $runId !== '' ? $service->statusAutorun($runId) : ($latest ? $service->statusAutorun((string) $latest['run_id']) : ['ok' => true, 'status' => 'idle']);
        if ($request->expectsJson() || $request->query('json')) return response()->json($status);

        $token = self::TOKEN;
        $startUrl = url('/tools/start-marketplace-category-tree-import-autorun').'?token='.$token.'&confirm=1&batch_size=200&channel=all&include_raw_payload=0';
        $resumeUrl = data_get($status, 'next_url');
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Marketplace category tree import autorun</title><style>body{font-family:Arial,sans-serif;margin:32px;}pre{background:#f6f8fa;padding:16px;border-radius:8px;overflow:auto}.actions a{display:inline-block;margin-right:12px;padding:8px 12px;background:#2563eb;color:white;text-decoration:none;border-radius:6px}</style></head><body>'
            .'<h1>Marketplace category tree import autorun</h1><div class="actions"><a href="'.e($startUrl).'">Start</a>'
            .($resumeUrl ? '<a href="'.e($resumeUrl).'">Resume active run</a>' : '')
            .'</div><h2>Status</h2><pre>'.e(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</pre></body></html>';
        return response($html);
    }

    public function startAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing autorun start without confirm=1.'], 422);
        try {
            return response()->json($service->startAutorun((string) $request->query('channel', 'all'), (int) $request->query('batch_size', 200), $request->boolean('include_raw_payload'), (int) $request->query('time_limit', 10)));
        } catch (\Throwable $e) { return response()->json(['ok' => false, 'error_message' => $e->getMessage()], 500); }
    }

    public function runAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->tickAutorun((string) $request->query('run_id')));
    }

    public function statusAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->statusAutorun((string) $request->query('run_id')));
    }

    public function resetAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing reset without confirm=1.'], 422);
        return response()->json($service->resetAutorun((string) $request->query('run_id')));
    }

    public function resultsAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->resultsAutorun((string) $request->query('run_id')));
    }

    public function debugAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->debugAutorun());
    }

    public function dryRunBackfill(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->backfillEbayDe(false));
    }

    public function backfill(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing local backfill without confirm=1.', 'local_update' => false], 422);
        return response()->json($service->backfillEbayDe(true));
    }

    private function denyBadToken(Request $request): ?JsonResponse
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', '')) ? null : response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }
}
