<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayListingStatusBatchRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EbayListingStatusBatchRunnerController extends Controller
{
    public const PAGE_MARKER = 'ebay_listing_status_batch_runner_admin_page_v1';

    public function index(EbayListingStatusBatchRunnerService $service): View
    {
        return view('admin.tools.ebay.listing-status-sync', ['status' => $service->status(), 'pageMarker' => self::PAGE_MARKER]);
    }

    public function status(EbayListingStatusBatchRunnerService $service): JsonResponse
    {
        return response()->json($service->status());
    }

    public function start(Request $request, EbayListingStatusBatchRunnerService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->start($request->only(['batch_size', 'delay_seconds', 'scope', 'dry_run', 'confirm'])), 'Runner dry-run został uruchomiony.');
    }

    public function runNextBatch(Request $request, EbayListingStatusBatchRunnerService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->runNextBatch($request->only(['confirm'])), 'Batch dry-run został wykonany.');
    }

    public function stop(Request $request, EbayListingStatusBatchRunnerService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->stop($request->only(['confirm'])), 'Runner został zatrzymany.');
    }

    private function respond(Request $request, array $result, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect('/admin/tools/ebay/listing-status-sync')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? $message : ('Operacja zablokowana: '.($result['reason'] ?? 'unknown')));
    }
}
