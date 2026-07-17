<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\AllegroGpsrAuditRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AllegroGpsrAuditRunnerController extends Controller
{
    public const PAGE_MARKER = 'allegro_gpsr_audit_runner_admin_page_v1';

    public function index(AllegroGpsrAuditRunnerService $service): View
    {
        return view('admin.tools.allegro.gpsr-audit-runner', ['status' => $service->status(), 'pageMarker' => self::PAGE_MARKER]);
    }

    public function diagnose(AllegroGpsrAuditRunnerService $service): JsonResponse
    {
        return response()->json(['ok' => true, 'read_only' => true, 'candidate_diagnostics' => $service->candidateRows()['diagnostics'], 'classes' => AllegroGpsrAuditRunnerService::CLASSES]);
    }

    public function status(AllegroGpsrAuditRunnerService $service): JsonResponse { return response()->json($service->status()); }
    public function start(Request $request, AllegroGpsrAuditRunnerService $service): JsonResponse|RedirectResponse { return $this->respond($request, $service->start($request->only(['confirm','mode','batch_size','delay_seconds'])), 'Allegro GPSR audit started.'); }
    public function runNextBatch(Request $request, AllegroGpsrAuditRunnerService $service): JsonResponse|RedirectResponse { return $this->respond($request, $service->runNextBatch($request->only(['confirm'])), 'Allegro GPSR audit batch completed.'); }
    public function autoRun(Request $request, AllegroGpsrAuditRunnerService $service): JsonResponse|RedirectResponse { return $this->respond($request, $service->autoRun($request->only(['max_batches'])), 'Allegro GPSR audit auto-run completed.'); }
    public function stop(Request $request, AllegroGpsrAuditRunnerService $service): JsonResponse|RedirectResponse { return $this->respond($request, $service->stop($request->only(['confirm'])), 'Allegro GPSR audit stopped.'); }
    public function exportJson(AllegroGpsrAuditRunnerService $service): JsonResponse { return response()->json($service->jsonExport()); }
    public function exportCsv(AllegroGpsrAuditRunnerService $service): Response { return response($service->csvExport(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=allegro-gpsr-audit.csv']); }

    private function respond(Request $request, array $result, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        return redirect('/admin/tools/allegro/gpsr-audit-runner')->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false) ? $message : ('Operation blocked: '.($result['reason'] ?? 'unknown')));
    }
}
