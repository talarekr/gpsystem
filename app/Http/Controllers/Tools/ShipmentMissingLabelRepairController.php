<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ShipmentMissingLabelRepairController extends Controller
{
    private const CONFIRM = 'repair-missing-dhl-label';
    private const CODE_MARKER = 'shipment_missing_label_hotfix_v1';

    public function __invoke(Request $request): JsonResponse
    {
        $shipmentId = (int) $request->integer('shipment_id');
        $orderId = (int) $request->integer('order_id');
        $confirm = (string) $request->input('confirm', '');
        $mode = (string) $request->input('mode', 'preview');
        $shipment = $shipmentId > 0 ? Shipment::query()->find($shipmentId) : null;

        if (! $shipment || (int) $shipment->order_id !== $orderId || $confirm !== self::CONFIRM) {
            return response()->json([
                'ok' => false,
                'code_marker' => self::CODE_MARKER,
                'error' => 'Invalid shipment_id/order_id or missing exact confirm token.',
                'required_confirm' => self::CONFIRM,
                'shipment_created' => false,
                'createShipment_called' => false,
                'getLabels_called' => false,
                'bulk' => false,
            ], 422);
        }

        $labelPath = is_scalar($shipment->label_path) ? trim((string) $shipment->label_path) : '';
        $labelExists = $this->labelExists($labelPath);

        if ($mode !== 'mark_label_missing_and_keep_tracking') {
            return response()->json([
                'ok' => true,
                'preview_only' => true,
                'code_marker' => self::CODE_MARKER,
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'tracking_number' => $shipment->tracking_number,
                'label_path' => $labelPath ?: null,
                'label_file_exists' => $labelExists,
                'available_mode_now' => 'mark_label_missing_and_keep_tracking',
                'disabled_future_modes' => ['manual_upload_label_pdf', 'fetch_existing_label_from_dhl_without_createShipment'],
                'shipment_created' => false,
                'createShipment_called' => false,
                'getLabels_called' => false,
                'bulk' => false,
            ]);
        }

        $responsePayload = is_array($shipment->response_payload) ? $shipment->response_payload : [];
        $responsePayload['missing_label_repair'] = [
            'code_marker' => self::CODE_MARKER,
            'marked_at' => now()->toDateTimeString(),
            'previous_label_path' => $labelPath ?: null,
            'label_file_exists_at_repair' => $labelExists,
            'tracking_kept' => true,
            'createShipment_called' => false,
            'getLabels_called' => false,
        ];

        $shipment->forceFill([
            'shipment_status' => 'label_missing',
            'response_payload' => $responsePayload,
        ])->save();

        return response()->json([
            'ok' => true,
            'code_marker' => self::CODE_MARKER,
            'mode' => $mode,
            'shipment_id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'tracking_number' => $shipment->tracking_number,
            'label_path' => $labelPath ?: null,
            'label_file_exists' => $labelExists,
            'shipment_created' => false,
            'createShipment_called' => false,
            'getLabels_called' => false,
            'bulk' => false,
        ]);
    }

    private function labelExists(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/^[a-z]+:\/\//i', $path) === 1) {
            return false;
        }

        try {
            return Storage::disk('local')->exists($path);
        } catch (Throwable) {
            return false;
        }
    }
}
