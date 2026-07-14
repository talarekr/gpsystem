<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OvokoOrderShipmentDiagnoseController extends Controller
{
    private const CODE_MARKER = 'ovoko_import_post_data_send_v1';

    public function __invoke(Request $request): JsonResponse
    {
        $localOrderId = $request->integer('order_id') ?: null;
        $marketplaceOrderId = trim((string) $request->query('marketplace_order_id', '')) ?: null;
        $order = $this->findOrder($localOrderId, $marketplaceOrderId);
        $shipment = $order ? $this->latestOvokoShipment($order) : null;

        $methodsFound = [
            [
                'source' => 'code',
                'class' => 'App\\Services\\Marketplace\\MarketplaceOrdersImportService',
                'method' => 'fetchOvokoOrders',
                'capability' => 'orders_read',
                'method_http' => 'POST',
                'endpoint' => '/v2/get/orders/{date_from}/{date_to}',
                'notes' => 'Existing importer uses form auth fields username/password/user_token and does not mutate Ovoko.',
            ],
            [
                'source' => 'code',
                'class' => 'App\\Services\\Marketplace\\Shipments\\OvokoShipmentAdapter',
                'method' => 'preview',
                'capability' => 'shipment_preview_only',
                'method_http' => null,
                'endpoint' => null,
                'notes' => 'Existing adapter is explicitly read-only. Its endpoint names are preview placeholders and must not be treated as confirmed API documentation.',
            ],
            [
                'source' => 'docs',
                'url' => 'https://supply-connector.ms.ovoko.com/docs?ui=re_doc',
                'capability' => 'not_confirmed_for_order_shipments',
                'notes' => 'Public OpenAPI docs discovered for Ovoko supply connector, but no confirmed order shipment/package/label endpoint was mapped from it in this read-only step.',
            ],
        ];

        return response()->json([
            'code_marker' => self::CODE_MARKER,
            'label_flow_marker' => 'ovoko_shipping_label_prepared_button_flow_v1',
            'read_only' => true,
            'order' => [
                'local_order_id' => $order?->id ?? $localOrderId,
                'marketplace_order_id' => $order?->marketplace_order_id ?? $marketplaceOrderId,
                'marketplace' => 'ovoko',
                'exists_locally' => $order ? true : ($localOrderId || $marketplaceOrderId ? false : null),
            ],
            'ovoko_api_capabilities' => [
                'can_set_package_type_and_dimensions' => true,
                'can_mark_shipment_prepared' => null,
                'can_fetch_label' => true,
                'docs_or_methods_found' => $methodsFound,
            ],
            'package_type_mapping' => [
                'supported_in_our_panel' => ['package', 'pallet'],
                'ovoko_values' => ['package' => 1, 'pallet' => 2],
                'source' => 'official_docs',
            ],
            'required_fields' => [
                'type' => true,
                'length_cm' => true,
                'width_cm' => true,
                'height_cm' => true,
                'weight_kg' => true,
            ],
            'current_local_shipment_data' => $this->localShipmentData($shipment),
            'label_diagnose' => [
                'is_ovoko_order' => $order?->marketplace === 'ovoko',
                'local_package_draft' => $this->localShipmentData($shipment),
                'package_data_sent' => $shipment ? $this->packageDataSent($shipment) : false,
                'current_local_shipment_status' => $shipment?->shipment_status,
                'label_exists_locally' => (bool) ($this->localShipmentData($shipment)['label_exists'] ?? false),
                'label_endpoint' => 'https://api.rrr.lt/get/print_shipping_label/'.($order?->marketplace_order_id ?: '{marketplace_order_id}'),
                'safety_flags' => ['no_mutation' => true, 'import_post_data_not_sent' => true, 'label_not_fetched' => true],
            ],
            'rrr_order_id_source' => [
                'api_field' => 'order_id',
                'uses_local_column' => 'orders.marketplace_order_id',
                'example' => ['local_order_id' => 154, 'marketplace_order_id_sent_to_rrr' => '8769937'],
            ],
            'planned_flow' => [
                'step_1_save_package_dimensions' => ['method' => 'POST', 'endpoint' => 'https://api.rrr.lt/crm/importPostData', 'payload_shape_sanitized' => $this->plannedImportPayload($order, $shipment)],
                'step_2_mark_shipment_prepared' => ['method' => null, 'endpoint' => null, 'payload_shape_sanitized' => null],
                'step_3_fetch_label' => ['method' => 'POST', 'endpoint' => 'https://api.rrr.lt/get/print_shipping_label/{order_id}', 'response_type' => 'application/pdf'],
            ],
            'blocking_reasons' => [
                'Official RRR/Ovoko docs confirm crm/importPostData for package dimensions and get/print_shipping_label/{order_id} for label download.',
            ],
            'warnings' => [
                'Diagnostics are read-only. The separate POST endpoint sends package data only after CSRF and exact confirmation.',
                'Label fetching is never automatic; it requires POST confirm=fetch-ovoko-shipping-label.',
            ],
        ]);
    }

    private function plannedImportPayload(?Order $order, ?Shipment $shipment): array
    {
        $data = $this->localShipmentData($shipment);
        $type = $data['type'] ?? null;
        $packingType = $type === 'pallet' ? 2 : 1;
        $weightKg = is_numeric($data['weight_kg'] ?? null) ? (float) $data['weight_kg'] : 12.5;

        return [
            'username' => '[redacted]',
            'password' => '[redacted]',
            'user_token' => '[redacted]',
            // RRR/Ovoko order_id is the marketplace_order_id stored on our local order, never local orders.id.
            'order_id' => (string) ($order?->marketplace_order_id ?? '8769937'),
            'packing_type' => $packingType,
            'length' => is_numeric($data['length_cm'] ?? null) ? $this->number($data['length_cm']) : 120,
            'width' => is_numeric($data['width_cm'] ?? null) ? $this->number($data['width_cm']) : 40,
            'height' => is_numeric($data['height_cm'] ?? null) ? $this->number($data['height_cm']) : 30,
            'weight' => (int) round($weightKg * 1000),
        ];
    }

    private function number(mixed $value): int|float
    {
        $number = (float) $value;
        return fmod($number, 1.0) === 0.0 ? (int) $number : $number;
    }

    private function findOrder(?int $localOrderId, ?string $marketplaceOrderId): ?Order
    {
        if (! Schema::hasTable('orders')) return null;
        if ($localOrderId) return Order::query()->where('marketplace', 'ovoko')->find($localOrderId);
        if ($marketplaceOrderId) return Order::query()->where('marketplace', 'ovoko')->where('marketplace_order_id', $marketplaceOrderId)->first();
        return null;
    }

    private function packageDataSent(Shipment $shipment): bool
    {
        return $shipment->shipment_status === 'ovoko_package_data_sent'
            || $shipment->shipment_status === 'ovoko_shipping_label_downloaded'
            || (bool) data_get(is_array($shipment->request_payload) ? $shipment->request_payload : [], 'ovoko_import_post_data.sent');
    }

    private function latestOvokoShipment(Order $order): ?Shipment
    {
        if (! Schema::hasTable('shipments')) return null;
        return Shipment::query()->where('order_id', $order->id)->where(function ($query): void {
            $query->where('carrier', 'ovoko')->orWhere('request_payload', 'like', '%ovoko%')->orWhere('response_payload', 'like', '%ovoko%');
        })->latest('id')->first();
    }

    private function localShipmentData(?Shipment $shipment): array
    {
        $parcel = (array) ($shipment?->parcel_snapshot ?? []);
        $requestPackage = (array) data_get(is_array($shipment?->request_payload) ? $shipment->request_payload : [], 'package', []);
        $value = fn (string $key, array $aliases = []) => collect([$key, ...$aliases])
            ->map(fn (string $candidate) => data_get($parcel, $candidate, data_get($requestPackage, $candidate)))
            ->first(fn ($candidate) => filled($candidate));
        $type = $value('type', ['package_type']);
        $labelPath = is_scalar($shipment?->label_path) ? trim((string) $shipment->label_path) : null;

        return [
            'exists' => $shipment !== null,
            'type' => $type,
            'type_label' => $value('type_label') ?: match ($type) {
                'package' => 'Opakowanie',
                'pallet' => 'Paleta',
                default => null,
            },
            'length_cm' => $value('length_cm', ['length']),
            'width_cm' => $value('width_cm', ['width']),
            'height_cm' => $value('height_cm', ['height']),
            'weight_kg' => $value('weight_kg', ['weight']),
            'status' => $shipment?->shipment_status ?? $value('status'),
            'label_exists' => filled($labelPath) && Storage::disk('local')->exists($labelPath),
            'label_path' => $labelPath ?: null,
        ];
    }
}
