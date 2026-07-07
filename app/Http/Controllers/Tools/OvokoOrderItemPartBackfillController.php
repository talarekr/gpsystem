<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\OvokoOrderItemPartMappingService;
use Illuminate\Http\Request;

class OvokoOrderItemPartBackfillController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function admin(Request $request, OvokoOrderItemPartMappingService $service)
    {
        if ($request->isMethod('get') && ! $request->hasAny(['part_id', 'ovoko_order_id', 'marketplace_item_id'])) {
            return $this->html(null, $request);
        }

        $partId = $request->filled('part_id') ? (int) $request->input('part_id') : null;
        $ovokoOrderId = $request->filled('ovoko_order_id') ? (string) $request->input('ovoko_order_id') : null;
        $marketplaceItemId = $request->filled('marketplace_item_id') ? (string) $request->input('marketplace_item_id') : null;

        if ($request->isMethod('post') && $request->boolean('confirm')) {
            $result = $service->apply($ovokoOrderId, $partId, $marketplaceItemId, true);
        } else {
            $result = $service->preview($ovokoOrderId, $partId, $marketplaceItemId);
        }

        return $this->html($result, $request);
    }

    public function preview(Request $request, OvokoOrderItemPartMappingService $service)
    {
        if (! $this->authorized($request)) return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);

        return response()->json($service->preview(
            $request->query('ovoko_order_id') ? (string) $request->query('ovoko_order_id') : null,
            $request->query('part_id') ? (int) $request->query('part_id') : null,
            $request->query('marketplace_item_id') ? (string) $request->query('marketplace_item_id') : null,
        ));
    }

    public function apply(Request $request, OvokoOrderItemPartMappingService $service)
    {
        if (! $this->authorized($request)) return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        if (! $request->boolean('confirm')) return response()->json(['ok' => false, 'error_message' => 'confirm=1 is required for POST backfill.'], 422);

        return response()->json($service->apply(
            $request->input('ovoko_order_id') ? (string) $request->input('ovoko_order_id') : null,
            $request->input('part_id') ? (int) $request->input('part_id') : null,
            $request->input('marketplace_item_id') ? (string) $request->input('marketplace_item_id') : null,
            true,
        ));
    }

    private function html(?array $result, Request $request)
    {
        $csrf = csrf_field();
        $partId = e((string) $request->input('part_id', '7820'));
        $orderId = e((string) $request->input('ovoko_order_id', '8755665'));
        $itemId = e((string) $request->input('marketplace_item_id', '11672'));
        $json = $result ? '<h2>Result</h2><pre>'.e(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</pre>' : '';

        return response("<!doctype html><html><head><meta charset='utf-8'><title>Ovoko order item part backfill</title><style>body{font-family:sans-serif;max-width:1100px;margin:32px auto}label{display:block;margin:12px 0}input{padding:6px}pre{background:#111;color:#eee;padding:16px;overflow:auto}.danger{border:1px solid #c00;padding:12px}</style></head><body><h1>Ovoko order item part backfill</h1><p>GET without parameters only displays this form. Preview is read-only. Apply requires POST, CSRF and confirm=1.</p><form method='get'><label>part_id <input name='part_id' value='{$partId}'></label><label>ovoko_order_id <input name='ovoko_order_id' value='{$orderId}'></label><label>marketplace_item_id <input name='marketplace_item_id' value='{$itemId}'></label><button type='submit'>Preview (GET, read-only)</button></form><form method='post' class='danger'>{$csrf}<input type='hidden' name='part_id' value='{$partId}'><input type='hidden' name='ovoko_order_id' value='{$orderId}'><input type='hidden' name='marketplace_item_id' value='{$itemId}'><input type='hidden' name='confirm' value='1'><p>Apply mutates only the exact order/item/part above and dispatches PartAvailabilityEventService::sold. It does not delete links and does not relist eBay.</p><button type='submit'>Apply (POST confirm=1)</button></form>{$json}</body></html>");
    }

    private function authorized(Request $request): bool
    {
        return hash_equals(self::TOKEN, (string) ($request->query('token') ?: $request->input('token', '')));
    }
}
