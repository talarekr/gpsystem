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
    private const MODE = 'mark_label_missing_and_keep_tracking';
    private const CODE_MARKER = 'dhl_missing_label_local_repair_v1';

    public function __invoke(Request $request): JsonResponse
    {
        $shipmentId = (int) $request->integer('shipment_id');
        $orderId = (int) $request->integer('order_id');
        $confirm = (string) $request->input('confirm', '');
        $mode = (string) $request->input('mode', 'preview');
        $shipment = $shipmentId > 0 ? Shipment::query()->find($shipmentId) : null;
        $preview = $this->preview($shipment, $shipmentId, $orderId);

        if ($mode !== self::MODE || $confirm !== self::CONFIRM) {
            return response()->json($preview + [
                'ok' => false,
                'preview_only' => true,
                'error' => 'No mutation was performed. Send exact mode and confirm to repair this one local shipment record.',
                'required_mode' => self::MODE,
                'required_confirm' => self::CONFIRM,
            ], 422);
        }

        if (! $preview['qualifies_for_repair']) {
            return response()->json($preview + [
                'ok' => false,
                'error' => $preview['blocking_reason'] ?? 'Shipment does not qualify for missing-label repair.',
            ], 422);
        }

        $labelPath = $preview['label_path'];
        $responsePayload = is_array($shipment->response_payload) ? $shipment->response_payload : [];
        $responsePayload['missing_label_repair'] = [
            'code_marker' => self::CODE_MARKER,
            'marked_at' => now()->toDateTimeString(),
            'previous_missing_label_path' => $labelPath,
            'label_file_exists_at_repair' => false,
            'tracking_kept' => true,
            'carrier_shipment_id_kept' => true,
            'createShipment_called' => false,
            'getLabels_called' => false,
            'bulk' => false,
        ];

        $shipment->forceFill([
            'shipment_status' => 'remote_created_label_missing',
            'label_path' => null,
            'response_payload' => $responsePayload,
        ])->save();

        return response()->json(array_merge($this->preview($shipment->refresh(), $shipmentId, $orderId), [
            'ok' => true,
            'previous_missing_label_path' => $labelPath,
            'changed' => [
                'shipment_status' => 'remote_created_label_missing',
                'label_path' => null,
            ],
        ]));
    }

    private function preview(?Shipment $shipment, int $shipmentId, int $orderId): array
    {
        $labelPath = $shipment && is_scalar($shipment->label_path) ? trim((string) $shipment->label_path) : '';
        $tracking = $shipment && is_scalar($shipment->tracking_number) ? trim((string) $shipment->tracking_number) : '';
        $carrierShipmentId = $shipment && is_scalar($shipment->carrier_shipment_id) ? trim((string) $shipment->carrier_shipment_id) : '';
        $labelExists = $this->labelExists($labelPath);
        $blockingReason = null;

        if (! $shipment) {
            $blockingReason = 'Shipment was not found.';
        } elseif ((int) $shipment->order_id !== $orderId) {
            $blockingReason = 'Shipment does not belong to the supplied order_id.';
        } elseif (strtolower((string) $shipment->carrier) !== 'dhl') {
            $blockingReason = 'Shipment carrier is not DHL.';
        } elseif ($tracking === '') {
            $blockingReason = 'Shipment has no tracking_number to keep.';
        } elseif ($labelPath === '') {
            $blockingReason = 'Shipment has no label_path to clear.';
        } elseif ($labelExists) {
            $blockingReason = 'Local label file exists; repair is not needed and label_path must not be cleared.';
        }

        return [
            'ok' => false,
            'code_marker' => self::CODE_MARKER,
            'shipment_id' => $shipment?->id ?? $shipmentId,
            'order_id' => $shipment?->order_id ?? $orderId,
            'tracking_number' => $tracking !== '' ? $tracking : null,
            'carrier_shipment_id' => $carrierShipmentId !== '' ? $carrierShipmentId : null,
            'label_path' => $labelPath !== '' ? $labelPath : null,
            'label_file_exists' => $labelExists,
            'qualifies_for_repair' => $blockingReason === null,
            'blocking_reason' => $blockingReason,
            'repair_action' => self::MODE,
            'shipment_created' => false,
            'createShipment_called' => false,
            'getLabels_called' => false,
            'bulk' => false,
        ];
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
