<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Marketplace\AllegroShippingCatalog;
use App\Services\Marketplace\ShippingRateAudit\ShippingAuditRuns;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateApply;
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

    public function apply(Request $request, ShippingRateApply $apply)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate([
            'action' => ['required', 'in:prepare,apply'],
            'run_id' => ['required', 'uuid'],
            'plan_id' => ['required_if:action,apply', 'uuid'],
            'plan_digest' => ['required_if:action,apply', 'string', 'size:64'],
            'confirm' => ['required_if:action,apply', 'string'],
        ]);
        $owner = (int) $request->user()->id;
        $result = $input['action'] === 'prepare'
            ? $apply->prepare($owner, $input['run_id'])
            : $apply->apply($owner, $input['run_id'], $input['plan_id'], $input['plan_digest'], $input['confirm']);

        return response()->json($result, $result['ok'] ? 200 : 409)->header('Cache-Control', 'private, no-store');
    }

    public function runs(Request $request, ShippingAuditRuns $runs, ?string $run = null)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate([
            'action' => ['sometimes', 'in:start,batch,pause,resume,stop,retry,client-event'],
            'event' => ['sometimes', 'array:reason,stage,http_status,duration_ms,response_bytes,online,attempt'],
            'event.reason' => ['required_with:event', 'in:fetch_failed,browser_timeout,invalid_json,http_error,session_expired,protocol_error'],
            'event.stage' => ['required_with:event', 'in:batch,status,resume,start,retry'],
            'event.http_status' => ['nullable', 'integer', 'min:0', 'max:599'],
            'event.duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'event.response_bytes' => ['nullable', 'integer', 'min:0', 'max:50000000'],
            'event.online' => ['nullable', 'boolean'],
            'event.attempt' => ['nullable', 'integer', 'min:0', 'max:10'],
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
                if ($action === 'client-event') {
                    abort_unless(isset($input['event']), 422);
                    $result = $runs->clientEvent($owner, $run, $input['event']);
                } else {
                    $result = $action === 'batch' ? $runs->batch($owner, $run) : $runs->control($owner, $run, $action);
                }
            }

            $response = response()->json($result)->header('Cache-Control', 'private, no-store');

            return $response->header('X-Audit-Response-Bytes', (string) strlen($response->getContent()));
        }
        $status = $runs->status($owner, $run);
        if (! isset($input['format'])) {
            $response = response()->json($status)->header('Cache-Control', 'private, no-store');

            return $response->header('X-Audit-Response-Bytes', (string) strlen($response->getContent()));
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
