<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayListingAuditRunnerService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class EbayListingAuditRunnerController extends Controller
{
    public function __invoke(Request $request, EbayListingAuditRunnerService $runner)
    {
        $this->authorizeAdmin($request);

        if ($request->isMethod('get') && $request->query->count() === 0) {
            return view('admin.tools.ebay.listing-audit-runner', [
                'defaultChannel' => 'ebay_de',
                'defaultBatchSize' => 20,
                'defaultDelayMs' => 5000,
                'batchEndpoint' => route('admin.tools.ebay.listing-audit-runner'),
            ]);
        }

        $result = $runner->startOrContinue(
            channel: (string) $request->query('channel', $request->input('channel', 'ebay_de')),
            batchSize: (int) $request->query('batch_size', $request->input('batch_size', 20)),
            runId: $request->query('run_id') ? (string) $request->query('run_id') : null,
            start: $request->boolean('start'),
            cancel: $request->boolean('cancel'),
            confirmedCancel: $request->query('confirm') === 'cancel-ebay-audit-runner',
            apply: $request->isMethod('post') && $request->input('confirm') === 'mark-ebay-ended-historical',
        );

        return response()->json(['ok' => true] + $result);
    }

    public function status(Request $request, EbayListingAuditRunnerService $runner)
    {
        $this->authorizeAdmin($request);
        $runId = (string) $request->query('run_id', '');
        abort_unless($runId !== '', 422, 'run_id is required.');
        $result = $runner->status($runId);
        abort_unless($result !== null, 404, 'Audit runner not found or expired.');
        if ($request->boolean('problems')) return response()->json(['ok' => true, 'run_id' => $runId, 'completed' => $result['completed'], 'problem_samples' => $result['problem_samples']]);
        return response()->json(['ok' => true] + $result);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessPanel(Filament::getPanel('admin')), 403);
    }

}
