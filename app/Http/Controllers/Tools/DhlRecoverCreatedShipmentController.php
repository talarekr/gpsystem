<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Shipments\DhlShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DhlRecoverCreatedShipmentController extends Controller
{
    public function __invoke(Request $request, DhlShipmentService $dhl): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'log_id' => ['nullable', 'integer', 'exists:marketplace_sync_logs,id'],
            'confirm' => ['required', 'in:recover-dhl-created-shipment'],
        ]);

        try {
            $shipment = $dhl->recoverCreatedShipmentFromLog((int) $data['order_id'], isset($data['log_id']) ? (int) $data['log_id'] : null);
        } catch (RuntimeException $exception) {
            if ($request->expectsJson() || $request->boolean('json')) {
                return response()->json(['ok' => false, 'message' => $exception->getMessage(), 'code_marker' => 'dhl_response_parser_recovery_v1'], 422);
            }
            return back()->withErrors(['dhl_recovery' => $exception->getMessage()]);
        }

        $payload = ['ok' => true, 'shipment_id' => $shipment->id, 'tracking_number' => $shipment->tracking_number, 'label_path' => $shipment->label_path, 'code_marker' => 'dhl_response_parser_recovery_v1'];
        return ($request->expectsJson() || $request->boolean('json')) ? response()->json($payload) : back()->with('status', 'DHL shipment recovered.');
    }
}
