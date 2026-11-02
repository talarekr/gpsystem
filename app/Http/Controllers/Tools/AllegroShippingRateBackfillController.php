<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Marketplace\AllegroShippingCatalog;
use App\Services\Marketplace\ShippingRateAudit\ShippingAuditRuns;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateAuditService;
use Illuminate\Http\Request;

final class AllegroShippingRateBackfillController extends Controller
{
    public function __invoke(Request $request, ShippingRateAuditService $audit)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate([
            'mode' => ['sometimes', 'in:catalog,batch,diagnose'],
            'after' => ['sometimes', 'integer', 'min:0'],
            'through' => ['sometimes', 'integer', 'min:0'],
        ]);
        $response = isset($input['mode'])
            ? response()->json(($input['mode'] === 'diagnose') ? $audit->diagnoseMainAccount() : $audit->run((int) ($input['after'] ?? 0), isset($input['through']) ? (int) $input['through'] : null, $input['mode'] === 'catalog'))
            : response()->view('admin.tools.allegro.shipping-rate-backfill');

        return $response->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, noarchive')->header('Referrer-Policy', 'no-referrer');
    }

    public function runs(Request $request, ShippingAuditRuns $runs, ?string $run = null)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate([
            'action' => ['sometimes', 'in:start,batch,pause,resume,stop,retry'],
            'format' => ['sometimes', 'in:json,csv'], 'only' => ['sometimes', 'in:all,proposals,mapping'],
        ]);
        $owner = (int) $request->user()->id;
        if ($request->isMethod('post')) {
            $action = $input['action'] ?? null;
            abort_unless($action, 422);
            if ($action === 'start') {
                abort_if($run !== null, 422);
                $result = $runs->start($owner);
            } else {
                abort_unless($run, 422);
                $result = $action === 'batch' ? $runs->batch($owner, $run) : $runs->control($owner, $run, $action);
            }

            return response()->json($result)->header('Cache-Control', 'private, no-store');
        }
        $status = $runs->status($owner, $run);
        if (! isset($input['format'])) {
            return response()->json($status)->header('Cache-Control', 'private, no-store');
        }
        abort_unless($run, 422);
        abort_if($status['run']['state'] === 'running' || $status['run']['busy'], 409, 'Pause scan before export.');
        $format = $input['format'];
        $only = $input['only'] ?? 'all';

        return response()->streamDownload(function () use ($runs, $owner, $run, $status, $format, $only): void {
            if ($format === 'json') {
                echo '{"checkpoint":'.json_encode($status, JSON_THROW_ON_ERROR).',"rows":[';
                $separator = '';
                foreach ($runs->results($owner, $run) as $row) {
                    echo $separator.json_encode($row, JSON_THROW_ON_ERROR);
                    $separator = ',';
                }
                echo ']}';

                return;
            }
            $out = fopen('php://output', 'w');
            $fields = ['part_id', 'sku', 'part_number', 'allegro_offer_id', 'marketplace_account_id', 'allegro_account_code', 'provider_offer_status', 'shipping_rate_id', 'shipping_rate_name', 'current_allegro_shipping_rate_name', 'proposed_allegro_shipping_rate_name', 'reason_code', 'action', 'provider_read_unavailable', 'observed_at'];
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [...$fields, 'run_id', 'run_state', 'safe_read_errors'], ';', '"', '');
            foreach ($runs->results($owner, $run) as $row) {
                if ($only === 'proposals' && ($row['action'] ?? '') !== 'SET_EMPTY_FIELD') {
                    continue;
                }
                if ($only === 'mapping' && ($row['action'] ?? '') !== 'BLOCK_MISSING_MAPPING') {
                    continue;
                }
                $values = array_map(fn ($key) => $row[$key] ?? '', $fields);
                $values[] = $run;
                $values[] = $status['run']['state'];
                $values[] = json_encode($row['offers'] ?? [], JSON_THROW_ON_ERROR);
                $values = array_map(static function ($value): string {
                    $value = (string) $value;

                    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
                }, $values);
                fputcsv($out, $values, ';', '"', '');
            }
            fclose($out);
        }, 'allegro-shipping-audit-'.$run.'.'.$format, ['Content-Type' => $format === 'json' ? 'application/json' : 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function synchronize(Request $request, AllegroShippingCatalog $catalog)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);

        return response()->json($catalog->synchronize())->header('Cache-Control', 'private, no-store');
    }
}
