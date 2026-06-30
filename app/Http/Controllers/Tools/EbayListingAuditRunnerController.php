<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayListingAuditRunnerService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EbayListingAuditRunnerController extends Controller
{
    public function __invoke(Request $request, EbayListingAuditRunnerService $runner)
    {
        $this->authorizeAdmin($request);

        $result = $runner->startOrContinue(
            channel: (string) $request->query('channel', 'ebay_de'),
            batchSize: (int) $request->query('batch_size', 20),
            runId: $request->query('run_id') ? (string) $request->query('run_id') : null,
            start: $request->boolean('start'),
            cancel: $request->boolean('cancel'),
            confirmedCancel: $request->query('confirm') === 'cancel-ebay-audit-runner',
        );

        if ($request->boolean('auto')) {
            return response($this->html($result), 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }

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

    private function html(array $result): string
    {
        $next = $result['completed'] ? null : e($result['next_url'].'&auto=1');
        $json = e(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $refresh = $next ? '<meta http-equiv="refresh" content="3;url='.$next.'">' : '';
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>eBay listing audit runner</title>'.$refresh.'<style>body{font-family:system-ui;margin:24px}pre{background:#111;color:#eee;padding:16px;overflow:auto}.ok{color:#087f23}.run{color:#b26a00}</style></head><body><h1>eBay listing audit runner</h1><p>Status: <strong class="'.($result['completed']?'ok':'run').'">'.e($result['status']).'</strong></p><p>Run ID: <code>'.e($result['run_id']).'</code></p><p>Postęp: '.e((string)$result['offset_after']).' / '.e((string)$result['total_count']).'</p>'.($next ? '<p>Następna paczka uruchomi się automatycznie za 3 sekundy. <a href="'.$next.'">Uruchom teraz</a></p>' : '<p>Completed. <a href="'.e($result['status_url']).'">Status JSON</a> · <a href="'.e($result['problems_url']).'">Problemy JSON</a></p>').'<pre>'.$json.'</pre></body></html>';
    }
}
