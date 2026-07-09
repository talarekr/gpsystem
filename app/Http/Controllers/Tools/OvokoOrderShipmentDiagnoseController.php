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
    private const CODE_MARKER = 'ovoko_package_draft_local_save_v1';

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
            'read_only' => true,
            'order' => [
                'local_order_id' => $order?->id ?? $localOrderId,
                'marketplace_order_id' => $order?->marketplace_order_id ?? $marketplaceOrderId,
                'marketplace' => 'ovoko',
                'exists_locally' => $order ? true : ($localOrderId || $marketplaceOrderId ? false : null),
            ],
            'ovoko_api_capabilities' => [
                'can_set_package_type_and_dimensions' => null,
                'can_mark_shipment_prepared' => null,
                'can_fetch_label' => null,
                'docs_or_methods_found' => $methodsFound,
            ],
            'package_type_mapping' => [
                'supported_in_our_panel' => ['package', 'pallet'],
                'ovoko_values' => ['package' => null, 'pallet' => null],
                'source' => 'unknown',
                'warnings' => [
                    'UI labels Opakowanie and Paleta are intentionally not mapped to API enum values until confirmed in Ovoko docs or captured existing requests.',
                ],
            ],
            'required_fields' => [
                'type' => true,
                'length_cm' => true,
                'width_cm' => true,
                'height_cm' => true,
                'weight_kg' => true,
            ],
            'current_local_shipment_data' => $this->localShipmentData($shipment),
            'planned_flow' => [
                'step_1_save_package_dimensions' => ['method' => null, 'endpoint' => null, 'payload_shape_sanitized' => null],
                'step_2_mark_shipment_prepared' => ['method' => null, 'endpoint' => null, 'payload_shape_sanitized' => null],
                'step_3_fetch_label' => ['method' => null, 'endpoint' => null, 'response_type' => null],
            ],
            'blocking_reasons' => [
                'No confirmed Ovoko API endpoint/payload has been found for setting package type, dimensions, weight, prepared state, or label download.',
                'Existing OvokoShipmentAdapter sendPackageData() deliberately throws and performs no mutation.',
            ],
            'warnings' => [
                'Endpoint performs no Ovoko HTTP calls, no database writes, no label generation, and no shipment-prepared transition.',
                'Do not implement mutating UI buttons until endpoint, payload, CSRF/confirmation, and package type enum values are confirmed.',
            ],
        ]);
    }

    private function findOrder(?int $localOrderId, ?string $marketplaceOrderId): ?Order
    {
        if (! Schema::hasTable('orders')) return null;
        if ($localOrderId) return Order::query()->where('marketplace', 'ovoko')->find($localOrderId);
        if ($marketplaceOrderId) return Order::query()->where('marketplace', 'ovoko')->where('marketplace_order_id', $marketplaceOrderId)->first();
        return null;
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
