<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Shipments\DhlShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DhlFetchExistingLabelController extends Controller
{
    public function __invoke(Request $request, DhlShipmentService $dhl): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'tracking_number' => ['required', 'string', 'max:40'],
            'package_tracking_number' => ['nullable', 'string', 'max:80'],
            'label_type' => ['required', 'in:LBLP,BLP,LP,ZBLP,ZBLP300,QR_PDF,QR2_IMG,QR4_IMG,QR6_IMG'],
            'confirm' => ['required', 'in:fetch-existing-dhl-label'],
        ]);

        try {
            $shipment = $dhl->fetchExistingLabel((int) $data['order_id'], (string) $data['tracking_number'], $data['package_tracking_number'] ?? null, (string) $data['label_type']);
        } catch (RuntimeException $exception) {
            $payload = [
                'ok' => false,
                'message' => $exception->getMessage(),
                'can_fetch_label_without_createShipment' => false,
                'blocking_reasons' => ['DHL API/client does not expose or accept label fetch for existing shipment.'],
                'manual_fallback' => 'Download label manually from DHL24 panel for shipment '.((string) $data['tracking_number']).'.',
                'code_marker' => 'dhl_existing_label_fetch_v1',
            ];
            return ($request->expectsJson() || $request->boolean('json')) ? response()->json($payload, 422) : back()->withErrors(['dhl_existing_label_fetch' => $exception->getMessage()]);
        }

        $payload = ['ok' => true, 'shipment_id' => $shipment->id, 'tracking_number' => $shipment->tracking_number, 'label_path' => $shipment->label_path, 'code_marker' => 'dhl_existing_label_fetch_v1'];
        return ($request->expectsJson() || $request->boolean('json')) ? response()->json($payload) : back()->with('status', 'DHL existing label fetched.');
    }
}
