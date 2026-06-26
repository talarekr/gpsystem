<?php

namespace App\Http\Controllers\Tools;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentLabelService;
use App\Support\AllegroShipmentPreviewBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ShipmentToolsController
{
    public function create(Request $request, ShipmentLabelService $service)
    {
        $carrier = strtolower((string) $request->query('carrier', 'dhl'));
        $orderId = $request->integer('order_id') ?: $request->integer('order');
        $order = $orderId ? Order::query()->find($orderId) : Order::query()->latest('id')->first();
        if ($orderId && ! $order) {
            return response()->json([
                'ok' => false,
                'error' => 'Order not found.',
                'requested_order_id' => $orderId,
                'safety_flags' => [
                    'read_only' => true, 'shipment_created' => false, 'label_created' => false, 'pickup_ordered' => false,
                    'emails_sent' => false, 'marketplace_status_changed' => false, 'marketplace_tracking_uploaded' => false,
                    'products_changed' => false, 'parts_changed' => false, 'offers_changed' => false, 'listings_changed' => false,
                    'stock_changed' => false, 'prices_changed' => false, 'mappings_changed' => false, 'allegro_write' => false,
                    'ovoko_write' => false, 'ebay_write' => false,
                ],
            ], 404, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $shipment = $request->integer('shipment') ? Shipment::query()->find($request->integer('shipment')) : null;
        $confirm = $request->boolean('confirm');

        $result = $confirm ? $service->confirm($carrier, $order, $shipment) : $service->preview($carrier, $order, $shipment);

        return response()->json($result, $confirm && (($result['validation']['missing'] ?? []) !== []) ? 422 : 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function allegroPreview(Request $request, AllegroShipmentPreviewBuilder $builder)
    {
        $orderId = $request->integer('order_id') ?: $request->integer('order');
        $order = $orderId ? Order::query()->with('items')->find($orderId) : Order::query()->where('marketplace', 'allegro')->latest('id')->first();
        $result = $builder->build($order);

        return response()->json($result, ($result['error'] ?? null) ? 404 : 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function download(Shipment $shipment)
    {
        abort_unless($shipment->label_path && Storage::disk('local')->exists($shipment->label_path), 404, 'Label not found.');

        return Storage::disk('local')->download($shipment->label_path, 'shipment-'.$shipment->id.'-'.($shipment->carrier ?: 'carrier').'.pdf');
    }

    public function deleteTest(Shipment $shipment)
    {
        abort_unless($shipment->test_mode, 403, 'Only test shipments can be deleted by this endpoint.');
        if ($shipment->label_path) {
            Storage::disk('local')->delete($shipment->label_path);
        }
        $shipment->delete();

        return response()->json(['deleted' => true, 'safety_flags' => ['pickup_ordered' => false, 'emails_sent' => false, 'marketplace_tracking_uploaded' => false]]);
    }
}
