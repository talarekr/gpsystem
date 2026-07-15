<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayEndedListingLocalCleanupRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EbayEndedListingLocalCleanupRunnerController extends Controller
{
    public function index(EbayEndedListingLocalCleanupRunnerService $service): View
    {
        return view('admin.tools.ebay.listing-status-audit-runner', ['status' => $service->status(), 'marker' => EbayEndedListingLocalCleanupRunnerService::MARKER]);
    }

    public function status(EbayEndedListingLocalCleanupRunnerService $service): JsonResponse
    {
        return response()->json($service->status());
    }

    public function start(Request $request, EbayEndedListingLocalCleanupRunnerService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->start($request->only(['mode', 'batch_size', 'delay_seconds', 'confirm'])));
    }

    public function runNextBatch(Request $request, EbayEndedListingLocalCleanupRunnerService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->runNextBatch());
    }

    public function stop(Request $request, EbayEndedListingLocalCleanupRunnerService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->stop());
    }

    private function respond(Request $request, array $result): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect('/admin/tools/ebay/listing-status-audit-runner')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'OK' : ($result['reason'] ?? 'unknown'));
    }
}
