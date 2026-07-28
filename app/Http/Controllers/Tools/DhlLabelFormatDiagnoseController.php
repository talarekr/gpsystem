<?php

namespace App\Http\Controllers\Tools;

use App\Models\Shipment;
use App\Services\Shipments\PdfPageInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DhlLabelFormatDiagnoseController
{
    public function __invoke(Request $request, PdfPageInspector $inspector): JsonResponse
    {
        $data = $request->validate(['shipment_id' => ['required', 'integer', 'exists:shipments,id']]);
        $shipment = Shipment::query()->findOrFail($data['shipment_id']);
        abort_unless(strtolower((string) $shipment->carrier) === 'dhl', 422, 'Shipment is not DHL.');

        $path = is_scalar($shipment->label_path) ? trim((string) $shipment->label_path) : '';
        $exists = $path !== '' && ! str_contains($path, "\0") && preg_match('/^[a-z]+:\/\//i', $path) !== 1
            && Storage::disk('local')->exists($path);
        $bytes = $exists ? Storage::disk('local')->get($path) : null;
        $requestLabelType = data_get($shipment->request_payload, 'shipment.shipmentInfo.labelType');
        $responseLabelType = data_get($shipment->response_payload, 'label.labelType')
            ?: data_get($shipment->response_payload, 'labelType');
        $current = $responseLabelType ?: $requestLabelType ?: 'unknown';

        return response()->json([
            'shipment_id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'tracking' => $shipment->tracking_number,
            'shipmentNotificationNumber' => $shipment->carrier_shipment_id ?: $shipment->tracking_number,
            'current_label_format' => $current,
            'requested_label_format' => 'BLP',
            'dhl_api_supports_blp_pdf' => true,
            'dhl_api_parameter' => [
                'createShipment' => 'shipment.shipmentInfo.labelType',
                'getLabels' => 'itemsToPrint.item.labelType',
            ],
            'pdf_page_size' => is_string($bytes) ? $inspector->inspect($bytes) : null,
            'mime_type' => is_string($bytes) && str_starts_with($bytes, '%PDF-') ? 'application/pdf' : ($shipment->label_format ?: null),
            'file_size_bytes' => is_string($bytes) ? strlen($bytes) : null,
            'stored_at' => $path ?: null,
            'file_exists' => $exists,
            'marketplace_write' => false,
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
