<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OvokoOrderShipmentPackageDraftController extends Controller
{
    private const CONFIRM = 'save-ovoko-package-draft';

    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(Schema::hasTable('orders') && Schema::hasTable('shipments'), 404);

        $data = $request->validate([
            'confirm' => ['required', Rule::in([self::CONFIRM])],
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'marketplace_order_id' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(['package', 'pallet'])],
            'length_cm' => ['required', 'numeric', 'min:0'],
            'width_cm' => ['required', 'numeric', 'min:0'],
            'height_cm' => ['required', 'numeric', 'min:0'],
            'weight_kg' => ['required', 'numeric', 'min:0'],
        ]);

        $order = Order::query()
            ->where('marketplace', 'ovoko')
            ->whereKey((int) $data['order_id'])
            ->firstOrFail();

        if (filled($data['marketplace_order_id'] ?? null) && (string) $order->marketplace_order_id !== (string) $data['marketplace_order_id']) {
            abort(422, 'Marketplace order ID does not match the local Ovoko order.');
        }

        $typeLabel = $data['type'] === 'pallet' ? 'Paleta' : 'Opakowanie';
        $parcel = [
            'type' => $data['type'],
            'type_label' => $typeLabel,
            'length_cm' => $this->number($data['length_cm']),
            'width_cm' => $this->number($data['width_cm']),
            'height_cm' => $this->number($data['height_cm']),
            'weight_kg' => $this->number($data['weight_kg']),
            'status' => 'package_data_entered',
        ];

        $shipment = Shipment::query()
            ->where('order_id', $order->id)
            ->where('carrier', 'ovoko')
            ->latest('id')
            ->first();

        $payload = [
            'source' => 'admin_order_ovoko_package_draft',
            'code_marker' => 'ovoko_package_draft_local_save_v1',
            'marketplace' => 'ovoko',
            'marketplace_order_id' => (string) $order->marketplace_order_id,
            'local_only' => true,
            'ovoko_api_write' => false,
            'package' => $parcel,
        ];

        if ($shipment) {
            $shipment->fill([
                'shipment_status' => 'package_data_entered',
                'parcel_snapshot' => $parcel,
                'request_payload' => $payload,
                'test_mode' => true,
            ])->save();
        } else {
            Shipment::query()->create([
                'order_id' => $order->id,
                'carrier' => 'ovoko',
                'service_code' => 'ovoko_local_package_draft',
                'shipment_status' => 'package_data_entered',
                'parcel_snapshot' => $parcel,
                'request_payload' => $payload,
                'test_mode' => true,
            ]);
        }

        return back()->with('success', 'Dane paczki Ovoko zapisane lokalnie.');
    }

    private function number(mixed $value): int|float
    {
        $number = (float) $value;
        return fmod($number, 1.0) === 0.0 ? (int) $number : $number;
    }
}
