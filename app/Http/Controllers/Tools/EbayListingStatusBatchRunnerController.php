<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayListingStatusBatchRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\View;
use Throwable;

class EbayListingStatusBatchRunnerController extends Controller
{
    public const PAGE_MARKER = 'ebay_listing_status_batch_runner_admin_page_v1';

    public function index(EbayListingStatusBatchRunnerService $service): View
    {
        return view('admin.tools.ebay.listing-status-sync', [
            'status' => $service->status(),
            'pageMarker' => self::PAGE_MARKER,
        ]);
    }

    public function diagnose(Request $request): JsonResponse|View
    {
        $diagnostics = [
            'controller_loadable' => class_exists(self::class),
            'service_resolvable' => false,
            'view_exists' => ViewFacade::exists('admin.tools.ebay.listing-status-sync'),
            'route_names_exist' => [],
            'cache_driver' => config('cache.default'),
            'current_runner_state_readable' => false,
            'exception_class' => null,
            'exception_message' => null,
            'exception_file' => null,
            'exception_line' => null,
        ];

        foreach (['index', 'status', 'start', 'run-next-batch', 'stop', 'diagnose'] as $suffix) {
            $name = 'admin.tools.ebay.listing-status-sync.'.$suffix;
            $diagnostics['route_names_exist'][$name] = Route::has($name);
        }

        try {
            app(EbayListingStatusBatchRunnerService::class);
            $diagnostics['service_resolvable'] = true;
            $state = Cache::get(EbayListingStatusBatchRunnerService::CACHE_KEY);
            $diagnostics['current_runner_state_readable'] = $state === null || is_array($state);
        } catch (Throwable $e) {
            $diagnostics['exception_class'] = $e::class;
            $diagnostics['exception_message'] = $e->getMessage();
            $diagnostics['exception_file'] = $e->getFile();
            $diagnostics['exception_line'] = $e->getLine();
        }

        if ($request->boolean('json')) {
            return response()->json($diagnostics);
        }

        return view('admin.tools.ebay.listing-status-sync-diagnose', ['diagnostics' => $diagnostics]);
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

    public function endedProducts(EbayListingStatusBatchRunnerService $service): JsonResponse
    {
        return response()->json($service->endedProducts());
    }

    public function endedProductsCsv(EbayListingStatusBatchRunnerService $service): Response
    {
        return response($service->endedProductsCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=ebay-ended-products.csv',
        ]);
    }

    private function respond(Request $request, array $result, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect('/admin/tools/ebay/listing-status-sync')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? $message : ('Operacja zablokowana: '.($result['reason'] ?? 'unknown')));
    }
}
