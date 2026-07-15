<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayEndedListingLocalCleanupRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    public function results(Request $request, EbayEndedListingLocalCleanupRunnerService $service): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $status = (string) $request->query('status', 'all');
        if ((string) $request->query('format', 'json') === 'csv') {
            return $this->csvResponse($service, $status);
        }

        return response()->json($service->results($status));
    }

    public function resultsCsv(Request $request, EbayEndedListingLocalCleanupRunnerService $service): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->csvResponse($service, (string) $request->query('status', 'all'));
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

    private function csvResponse(EbayEndedListingLocalCleanupRunnerService $service, string $status): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $headers = $service->csvHeaders();
        $rows = $service->csvRows($status);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value, Arr::only($row, $headers)));
            }
            fclose($out);
        }, 'ebay-listing-status-audit-runner-results-'.$status.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function respond(Request $request, array $result): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect('/admin/tools/ebay/listing-status-audit-runner')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? 'OK' : ($result['reason'] ?? 'unknown'));
    }
}
