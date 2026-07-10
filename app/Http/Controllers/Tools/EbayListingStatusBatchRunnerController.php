<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayListingStatusBatchRunnerService;
use App\Services\Marketplace\EbayListingStatusPersistentScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

        foreach (['index', 'status', 'start', 'run-next-batch', 'stop', 'diagnose', 'retry-transient', 'retry-diagnose', 'ended-results-diagnose', 'apply-confirmed-ended-preview', 'apply-confirmed-ended', 'start-persistent-scan', 'persistent-scan.status', 'persistent-scan.run-next-batch', 'persistent-scan.stop', 'persistent-scan.diagnose', 'persistent-scan.ended-results'] as $suffix) {
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

    public function retryDiagnose(EbayListingStatusBatchRunnerService $service): JsonResponse
    {
        return response()->json($service->retryDiagnose());
    }

    public function endedResultsDiagnose(EbayListingStatusBatchRunnerService $service): JsonResponse
    {
        return response()->json($service->confirmedEndedDiagnose());
    }

    public function applyConfirmedEndedPreview(EbayListingStatusBatchRunnerService $service): JsonResponse
    {
        return response()->json($service->confirmedEndedPreview());
    }

    public function applyConfirmedEnded(Request $request, EbayListingStatusBatchRunnerService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->applyConfirmedEnded($request->only(['source', 'scan_run_id', 'expected_count', 'dry_run', 'confirm'])), 'Potwierdzone zakończone aukcje eBay zostały oznaczone lokalnie.');
    }


    public function startPersistentScan(Request $request, EbayListingStatusPersistentScanService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->start($request->only(['batch_size', 'delay_seconds', 'scope', 'dry_run', 'stop_on_rate_limit', 'max_attempts_per_item', 'persist_full_report', 'confirm'])), 'Persistent eBay dry-run scan został uruchomiony.');
    }

    public function persistentScanStatus(EbayListingStatusPersistentScanService $service): JsonResponse
    {
        return response()->json($service->status());
    }

    public function persistentScanRunNextBatch(Request $request, EbayListingStatusPersistentScanService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->runNextBatch($request->only(['confirm'])), 'Persistent scan batch został wykonany.');
    }

    public function persistentScanStop(Request $request, EbayListingStatusPersistentScanService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->stop($request->only(['confirm'])), 'Persistent scan został zatrzymany.');
    }

    public function persistentScanDiagnose(EbayListingStatusPersistentScanService $service): JsonResponse
    {
        return response()->json($service->diagnose());
    }

    public function persistentScanEndedResults(Request $request, EbayListingStatusPersistentScanService $service): JsonResponse
    {
        return response()->json($service->endedResults($request->integer('scan_run_id') ?: null));
    }

    public function retryTransient(Request $request, EbayListingStatusBatchRunnerService $service): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $service->retryTransient($request->only(['batch_size', 'delay_seconds', 'max_attempts_per_item', 'scope', 'dry_run', 'confirm'])), 'Retry błędów przejściowych został uruchomiony.');
    }

    private function respond(Request $request, array $result, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect('/admin/tools/ebay/listing-status-sync')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? $message : ('Operacja zablokowana: '.($result['reason'] ?? 'unknown')));
    }
}
