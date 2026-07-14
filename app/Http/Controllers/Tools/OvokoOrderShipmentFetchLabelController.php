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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OvokoOrderShipmentFetchLabelController extends Controller
{
    private const CONFIRM = 'fetch-ovoko-shipping-label';
    private const CODE_MARKER = 'ovoko_shipping_label_prepared_button_flow_v1';

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
        abort_unless($this->packageDataSent($shipment), 422, 'Najpierw wyślij dane paczki Ovoko przez importPostData.');

        $account = MarketplaceAccount::query()->where('code', 'ovoko_main')->first();
        abort_unless($account && $account->api_enabled && filled($account->api_base_url), 422, 'Ovoko API account ovoko_main is not configured or enabled.');
        $credentials = is_array($account->api_credentials) ? Arr::only($account->api_credentials, ['username', 'password', 'user_token']) : [];
        foreach (['username', 'password', 'user_token'] as $key) {
            abort_if(blank($credentials[$key] ?? null), 422, 'Missing Ovoko API credential: '.$key);
        }

        $endpoint = rtrim((string) $account->api_base_url, '/').'/get/print_shipping_label/'.$ovokoOrderId;
        $response = Http::asForm()->accept('*/*')->timeout(30)->post($endpoint, $credentials);
        abort_unless($response->successful(), 502, 'Ovoko label endpoint returned HTTP '.$response->status().'.');

        [$labelBytes, $labelFormat, $diagnostics] = $this->extractLabel($response->body(), (string) $response->header('Content-Type'));
        abort_unless($labelBytes !== null, 502, 'Ovoko label response did not contain a PDF/base64/link/binary label. Response type: '.($diagnostics['content_type'] ?: 'unknown'));

        $extension = $labelFormat === 'pdf' ? 'pdf' : 'bin';
        $path = 'shipments/ovoko/'.$order->id.'/ovoko-label-'.$ovokoOrderId.'-'.now()->format('YmdHis').'.'.$extension;
        Storage::disk('local')->put($path, $labelBytes);

        $requestPayload = is_array($shipment->request_payload) ? $shipment->request_payload : [];
        $responsePayload = is_array($shipment->response_payload) ? $shipment->response_payload : [];
        $requestPayload['ovoko_shipping_label'] = [
            'requested_at' => now()->toISOString(),
            'endpoint' => $endpoint,
            'ovoko_order_id' => $ovokoOrderId,
            'local_order_id' => (int) $order->id,
            'code_marker' => self::CODE_MARKER,
        ];
        $responsePayload['ovoko_shipping_label_response'] = [
            'http_status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'diagnostics' => $diagnostics,
            'stored_path' => $path,
            'stored_bytes' => strlen($labelBytes),
        ];

        $shipment->fill([
            'shipment_status' => 'ovoko_shipping_label_downloaded',
            'label_path' => $path,
            'label_format' => $labelFormat,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'test_mode' => false,
        ])->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Etykieta pobrana.', 'shipment_status' => $shipment->shipment_status, 'label_path' => $path, 'code_marker' => self::CODE_MARKER]);
        }

        return back()->with('success', 'Etykieta Ovoko pobrana.');
    }

    private function extractLabel(string $body, string $contentType): array
    {
        $diagnostics = ['content_type' => $contentType, 'response_shape' => 'binary_or_text', 'source' => null];
        if (str_starts_with($body, '%PDF-') || str_contains(strtolower($contentType), 'pdf')) {
            $diagnostics['source'] = 'binary_pdf';
            return [$body, 'pdf', $diagnostics];
        }

        $json = json_decode($body, true);
        if (is_array($json)) {
            $diagnostics['response_shape'] = 'json';
            foreach (['label', 'label_pdf', 'pdf', 'data', 'file', 'content'] as $key) {
                $value = data_get($json, $key);
                if (is_string($value) && $value !== '') {
                    $decoded = base64_decode(preg_replace('#^data:application/pdf;base64,#', '', $value), true);
                    if (is_string($decoded) && $decoded !== '') {
                        $diagnostics['source'] = 'json_base64:'.$key;
                        return [$decoded, str_starts_with($decoded, '%PDF-') ? 'pdf' : 'bin', $diagnostics];
                    }
                }
            }
            foreach (['url', 'link', 'label_url', 'download_url'] as $key) {
                $url = data_get($json, $key);
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $linked = Http::accept('*/*')->timeout(30)->get($url);
                    if ($linked->successful() && $linked->body() !== '') {
                        $diagnostics['source'] = 'json_link:'.$key;
                        $diagnostics['linked_http_status'] = $linked->status();
                        $diagnostics['linked_content_type'] = $linked->header('Content-Type');
                        return [$linked->body(), str_starts_with($linked->body(), '%PDF-') ? 'pdf' : 'bin', $diagnostics];
                    }
                }
            }
            $diagnostics['json_keys'] = array_keys($json);
            return [null, null, $diagnostics];
        }

        if ($body !== '') {
            $diagnostics['source'] = 'binary_fallback';
            return [$body, 'bin', $diagnostics];
        }

        return [null, null, $diagnostics];
    }

    private function latestOvokoShipment(Order $order): ?Shipment
    {
        return Shipment::query()->where('order_id', $order->id)->where(function ($query): void {
            $query->where('carrier', 'ovoko')->orWhere('request_payload', 'like', '%ovoko%')->orWhere('response_payload', 'like', '%ovoko%');
        })->latest('id')->first();
    }

    private function packageDataSent(Shipment $shipment): bool
    {
        return $shipment->shipment_status === 'ovoko_package_data_sent'
            || (bool) data_get(is_array($shipment->request_payload) ? $shipment->request_payload : [], 'ovoko_import_post_data.sent');
    }
}
