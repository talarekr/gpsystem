<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OvokoOrderShipmentSendPackageDataController extends Controller
{
    private const CONFIRM = 'send-ovoko-package-data';
    private const CODE_MARKER = 'ovoko_import_post_data_send_v1';

    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(Schema::hasTable('orders') && Schema::hasTable('shipments') && Schema::hasTable('marketplace_accounts'), 404);

        $data = $request->validate([
            'confirm' => ['required', Rule::in([self::CONFIRM])],
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'marketplace_order_id' => ['required', 'string', 'max:255'],
        ]);

        $order = Order::query()->where('marketplace', 'ovoko')->whereKey((int) $data['order_id'])->firstOrFail();
        $ovokoOrderId = trim((string) $order->marketplace_order_id);
        abort_if($ovokoOrderId === '', 422, 'Local Ovoko order is missing marketplace_order_id required by RRR/Ovoko order_id.');
        abort_if($ovokoOrderId !== (string) $data['marketplace_order_id'], 422, 'Marketplace order ID does not match the local Ovoko order.');

        $shipment = $this->latestOvokoShipment($order);
        abort_unless($shipment, 422, 'Brak lokalnego draftu danych paczki Ovoko.');

        $package = $this->packageDraft($shipment);
        abort_unless($package, 422, 'Brak kompletnego lokalnego draftu danych paczki Ovoko.');
        abort_unless(in_array($package['type'], ['package', 'pallet'], true), 422, 'Obsługiwane typy Ovoko to tylko package albo pallet.');

        $account = MarketplaceAccount::query()->where('code', 'ovoko_main')->first();
        abort_unless($account && $account->api_enabled && filled($account->api_base_url), 422, 'Ovoko API account ovoko_main is not configured or enabled.');
        $credentials = is_array($account->api_credentials) ? Arr::only($account->api_credentials, ['username', 'password', 'user_token']) : [];
        foreach (['username', 'password', 'user_token'] as $key) {
            abort_if(blank($credentials[$key] ?? null), 422, 'Missing Ovoko API credential: '.$key);
        }

        $packingType = $package['type'] === 'pallet' ? 2 : 1;
        $weightG = (int) round(((float) $package['weight_kg']) * 1000);
        $dimensions = [
            'length_cm' => $this->number($package['length_cm']),
            'width_cm' => $this->number($package['width_cm']),
            'height_cm' => $this->number($package['height_cm']),
        ];
        $form = $credentials + [
            // RRR/Ovoko expects its marketplace order number here, not our local orders.id.
            'order_id' => $ovokoOrderId,
            'packing_type' => $packingType,
            'length' => $dimensions['length_cm'],
            'width' => $dimensions['width_cm'],
            'height' => $dimensions['height_cm'],
            'weight' => $weightG,
        ];
        $endpoint = rtrim((string) $account->api_base_url, '/').'/crm/importPostData';

        $response = Http::asForm()->acceptJson()->timeout(30)->post($endpoint, $form);
        $json = $response->json();
        $isJson = is_array($json);
        $statusCode = $isJson ? ($json['status_code'] ?? null) : null;
        $ok = $response->successful() && $isJson && ($statusCode === 'R200' || $statusCode === 200);

        $meta = [
            'sent' => $ok,
            'sent_at' => now()->toISOString(),
            'packing_type' => $packingType,
            'weight_g' => $weightG,
            'length_cm' => $dimensions['length_cm'],
            'width_cm' => $dimensions['width_cm'],
            'height_cm' => $dimensions['height_cm'],
            'status_code' => $statusCode,
            'ovoko_order_id' => $ovokoOrderId,
            'local_order_id' => (int) $order->id,
        ];

        $requestPayload = is_array($shipment->request_payload) ? $shipment->request_payload : [];
        $responsePayload = is_array($shipment->response_payload) ? $shipment->response_payload : [];
        $requestPayload['ovoko_import_post_data'] = $meta;
        $requestPayload['code_marker'] = self::CODE_MARKER;
        $responsePayload['ovoko_import_post_data_response'] = [
            'http_status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'is_json' => $isJson,
            'json' => $isJson ? $this->sanitize($json) : null,
            'preview' => $isJson ? null : str($response->body())->limit(500)->toString(),
        ];

        $shipment->fill([
            'shipment_status' => $ok ? 'ovoko_package_data_sent' : ($shipment->shipment_status ?: 'package_data_entered'),
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'test_mode' => false,
        ])->save();

        abort_unless($ok, 502, $isJson ? (string) ($json['msg'] ?? $json['message'] ?? 'Ovoko importPostData returned an error.') : 'Ovoko importPostData returned a non-JSON response.');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Dane paczki wysłane do Ovoko.', 'shipment_status' => 'ovoko_package_data_sent', 'ovoko_import_post_data' => $meta]);
        }

        return back()->with('success', 'Dane paczki wysłane do Ovoko.');
    }

    private function latestOvokoShipment(Order $order): ?Shipment
    {
        return Shipment::query()->where('order_id', $order->id)->where(function ($query): void {
            $query->where('carrier', 'ovoko')->orWhere('request_payload', 'like', '%ovoko%')->orWhere('response_payload', 'like', '%ovoko%');
        })->latest('id')->first();
    }

    private function packageDraft(Shipment $shipment): ?array
    {
        $parcel = is_array($shipment->parcel_snapshot) ? $shipment->parcel_snapshot : [];
        $requestPackage = is_array($shipment->request_payload) ? (array) data_get($shipment->request_payload, 'package', []) : [];
        $value = fn (string $key, array $aliases = []) => collect([$key, ...$aliases])->map(fn (string $candidate) => data_get($parcel, $candidate, data_get($requestPackage, $candidate)))->first(fn ($candidate) => filled($candidate));
        $package = [
            'type' => $value('type', ['package_type']),
            'length_cm' => $value('length_cm', ['length']),
            'width_cm' => $value('width_cm', ['width']),
            'height_cm' => $value('height_cm', ['height']),
            'weight_kg' => $value('weight_kg', ['weight']),
        ];

        return collect($package)->every(fn ($value): bool => filled($value)) ? $package : null;
    }

    private function number(mixed $value): int|float
    {
        $number = (float) $value;
        return fmod($number, 1.0) === 0.0 ? (int) $number : $number;
    }

    private function sanitize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['username', 'password', 'user_token', 'token', 'authorization'], true)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }

        return $payload;
    }
}
