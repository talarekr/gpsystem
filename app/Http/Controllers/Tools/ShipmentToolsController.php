<?php

namespace App\Http\Controllers\Tools;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentLabelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ShipmentToolsController
{
    public function create(Request $request, ShipmentLabelService $service)
    {
        $carrier = strtolower((string) $request->query('carrier', 'dhl'));
        $order = Order::query()->find($request->integer('order')) ?: Order::query()->latest('id')->first();
        $shipment = $request->integer('shipment') ? Shipment::query()->find($request->integer('shipment')) : null;
        $confirm = $request->boolean('confirm');

        $result = $confirm ? $service->confirm($carrier, $order, $shipment) : $service->preview($carrier, $order, $shipment);

        return response()->json($result, $confirm && (($result['validation']['missing'] ?? []) !== []) ? 422 : 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
