<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\OvokoOrderItemPartMappingService;
use Illuminate\Http\Request;

class OvokoOrderItemPartBackfillController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function preview(Request $request, OvokoOrderItemPartMappingService $service)
    {
        if (! $this->authorized($request)) return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);

        return response()->json($service->preview(
            $request->query('ovoko_order_id') ? (string) $request->query('ovoko_order_id') : null,
            $request->query('part_id') ? (int) $request->query('part_id') : null,
        ));
    }

    public function apply(Request $request, OvokoOrderItemPartMappingService $service)
    {
        if (! $this->authorized($request)) return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        if (! $request->boolean('confirm')) return response()->json(['ok' => false, 'error_message' => 'confirm=1 is required for POST backfill.'], 422);

        return response()->json($service->apply(
            $request->input('ovoko_order_id') ? (string) $request->input('ovoko_order_id') : null,
            $request->input('part_id') ? (int) $request->input('part_id') : null,
            $request->boolean('run_sold_flow'),
        ));
    }

    private function authorized(Request $request): bool
    {
        return hash_equals(self::TOKEN, (string) ($request->query('token') ?: $request->input('token', '')));
    }
}
