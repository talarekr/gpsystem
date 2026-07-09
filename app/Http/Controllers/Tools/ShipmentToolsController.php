<?php

namespace App\Http\Controllers\Tools;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Marketplace\Shipments\OvokoShipmentAdapter;
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
        $input = $request->only(['weight', 'length', 'width', 'height', 'package_type', 'label_reference', 'size_code']);
        if (! array_key_exists('weight', $input)) {
            $input['weight'] = 2;
        }
        if (! array_key_exists('label_reference', $input) && $order) {
            $input['label_reference'] = $builder->defaultLabelReference($order);
        }
        $result = $builder->build($order, $input);

        if (! $request->expectsJson() && ! $request->wantsJson()) {
            return response()->view('tools.allegro-shipment-preview-form', [
                'order' => $order,
                'result' => $result,
                'input' => $input,
            ], ($result['error'] ?? null) === 'Order not found.' ? 404 : 200);
        }

        return response()->json($result, ($result['error'] ?? null) === 'Order not found.' ? 404 : 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ovokoPreview(Request $request, OvokoShipmentAdapter $adapter)
    {
        $orderId = $request->integer('order_id') ?: $request->integer('order');
        $order = $orderId ? Order::query()->with('items')->find($orderId) : Order::query()->where('marketplace', 'ovoko')->latest('id')->first();

        if (! $order) {
            return response()->json([
                'ok' => false,
                'read_only' => true,
                'ovoko_write' => false,
                'package_data_sent' => false,
                'label_downloaded' => false,
                'pickup_ordered' => false,
                'marketplace_write' => false,
                'will_send' => false,
                'error' => 'Order not found.',
            ], 404, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (! $adapter->supports($order)) {
            return response()->json([
                'ok' => false,
                'read_only' => true,
                'ovoko_write' => false,
                'package_data_sent' => false,
                'label_downloaded' => false,
                'pickup_ordered' => false,
                'marketplace_write' => false,
                'will_send' => false,
                'error' => 'order_not_ovoko',
                'order' => ['id' => $order->id, 'marketplace' => $order->marketplace, 'marketplace_order_id' => $order->marketplace_order_id],
            ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $input = $request->only(['weight', 'length', 'width', 'height', 'package_type', 'label_reference']);
        $result = $adapter->preview($order, $input)->toArray();

        return response()->json($result, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function download(Shipment $shipment)
    {
        $path = is_scalar($shipment->label_path) ? trim((string) $shipment->label_path) : '';
        abort_unless($path !== '' && ! str_contains($path, "\0") && preg_match('/^[a-z]+:\/\//i', $path) !== 1, 404, 'Label not found.');

        try {
            abort_unless(Storage::disk('local')->exists($path), 404, 'Label not found.');

            return Storage::disk('local')->download($path, 'shipment-'.$shipment->id.'-'.(is_scalar($shipment->carrier) && $shipment->carrier ? $shipment->carrier : 'carrier').'.pdf');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            throw $exception;
        } catch (\Throwable) {
            abort(404, 'Label not found.');
        }
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
