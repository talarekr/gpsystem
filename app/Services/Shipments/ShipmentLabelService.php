<?php

namespace App\Services\Shipments;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ShipmentLabelService
{
    public function preview(string $carrier, ?Order $order = null, ?Shipment $shipment = null): array
    {
        return $this->buildResult($carrier, $order, $shipment, false);
    }

    public function confirm(string $carrier, ?Order $order = null, ?Shipment $shipment = null): array
    {
        $result = $this->buildResult($carrier, $order, $shipment, true);

        if ($result['validation']['missing']) {
            return $result;
        }

        $shipment ??= Shipment::query()->create(['order_id' => $order?->id, 'carrier' => $carrier, 'shipment_status' => 'draft']);
        $shipment->fill([
            'order_id' => $order?->id,
            'carrier' => $carrier,
            'service_code' => $result['request_preview']['service_code'],
            'shipment_status' => 'created',
            'sender_snapshot' => $result['sender_snapshot'],
            'receiver_snapshot' => $result['receiver_snapshot'],
            'parcel_snapshot' => $result['parcel_snapshot'],
            'request_payload' => $result['request_preview'],
            'test_mode' => (bool) config("services.$carrier.test_mode", true),
        ])->save();

        $api = $this->createCarrierShipment($carrier, $result['request_preview']);
        $labelPath = $this->storeLabel($carrier, $api['carrier_shipment_id'], $api['label_content']);

        $shipment->fill([
            'shipment_status' => 'label_created',
            'tracking_number' => $api['tracking_number'],
            'carrier_shipment_id' => $api['carrier_shipment_id'],
            'label_path' => $labelPath,
            'label_format' => 'pdf',
            'response_payload' => $this->sanitizePayload($api),
        ])->save();

        $result['shipment'] = $shipment->fresh();
        $result['safety_flags']['shipment_created'] = true;
        $result['safety_flags']['label_created'] = true;
        $result['safety_flags']['read_only'] = false;

        return $result;
    }

    protected function buildResult(string $carrier, ?Order $order, ?Shipment $shipment, bool $confirm): array
    {
        $carrier = strtolower($carrier);
        if (! in_array($carrier, ['dhl', 'dpd'], true)) {
            throw new InvalidArgumentException('Unsupported carrier.');
        }

        $sender = $this->senderSnapshot($carrier);
        $receiver = $this->receiverSnapshot($order);
        $parcel = ['weight_kg' => 1.0, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20];
        $missing = $this->missing($sender, 'sender', ['name', 'address', 'postal_code', 'city', 'country', 'phone', 'email']);
        $missing = array_merge($missing, $this->missing($receiver, 'receiver', ['name', 'address', 'postal_code', 'city', 'country', 'phone', 'email']));
        $missing = array_merge($missing, $this->missing($this->carrierConfig($carrier), $carrier, ['account_number', 'login']));

        $request = [
            'carrier' => $carrier,
            'service_code' => $shipment?->service_code ?: config("services.$carrier.default_service", $carrier === 'dhl' ? 'AH' : 'CLASSIC'),
            'sender' => $sender,
            'receiver' => $receiver,
            'parcel' => $parcel,
            'references' => ['order_id' => $order?->id, 'order_number' => $order?->order_number],
            'confirm' => $confirm,
            'test_mode' => (bool) config("services.$carrier.test_mode", true),
        ];

        if ($shipment && ! $confirm) {
            $shipment->fill([
                'order_id' => $order?->id ?: $shipment->order_id,
                'carrier' => $carrier,
                'service_code' => $request['service_code'],
                'shipment_status' => 'previewed',
                'sender_snapshot' => $sender,
                'receiver_snapshot' => $receiver,
                'parcel_snapshot' => $parcel,
                'request_payload' => $this->sanitizePayload($request),
                'test_mode' => $request['test_mode'],
            ])->save();
        }

        return [
            'carrier' => $carrier,
            'mode' => $confirm ? 'confirm' : 'dry-run',
            'validation' => ['ok' => $missing === [], 'missing' => $missing],
            'configuration' => ['ok' => count(array_filter($this->missing($this->carrierConfig($carrier), $carrier, ['account_number', 'login']))) === 0],
            'sender_snapshot' => $sender,
            'receiver_snapshot' => $receiver,
            'parcel_snapshot' => $parcel,
            'request_preview' => $this->sanitizePayload($request),
            'safety_flags' => $this->safetyFlags($confirm),
        ];
    }

    protected function senderSnapshot(string $carrier): array
    {
        return [
            'name' => config('services.shipments.sender.name'), 'address' => config('services.shipments.sender.address'),
            'postal_code' => config('services.shipments.sender.postal_code'), 'city' => config('services.shipments.sender.city'),
            'country' => config('services.shipments.sender.country', 'PL'), 'phone' => config('services.shipments.sender.phone'), 'email' => config('services.shipments.sender.email'),
            'carrier_account_number' => config("services.$carrier.account_number"),
        ];
    }

    protected function receiverSnapshot(?Order $order): array
    {
        return [
            'name' => $order?->company_name ?: $order?->customer_name, 'address' => $order?->address_line1,
            'postal_code' => $order?->postal_code, 'city' => $order?->city, 'country' => $order?->country ?: 'PL',
            'phone' => $order?->phone, 'email' => $order?->email,
        ];
    }

    protected function carrierConfig(string $carrier): array
    {
        return ['account_number' => config("services.$carrier.account_number"), 'login' => config("services.$carrier.login"), 'password' => config("services.$carrier.password"), 'endpoint' => config("services.$carrier.endpoint")];
    }

    protected function missing(array $data, string $prefix, array $keys): array
    {
        return collect($keys)->filter(fn ($key) => blank($data[$key] ?? null))->map(fn ($key) => "$prefix.$key")->values()->all();
    }

    protected function createCarrierShipment(string $carrier, array $payload): array
    {
        $endpoint = config("services.$carrier.endpoint");
        if ($endpoint) {
            $response = Http::timeout(20)->withBasicAuth((string) config("services.$carrier.login"), (string) config("services.$carrier.password"))->post($endpoint, $payload)->throw()->json();
            return ['tracking_number' => $response['tracking_number'] ?? $response['trackingNumber'] ?? null, 'carrier_shipment_id' => $response['carrier_shipment_id'] ?? $response['shipmentId'] ?? null, 'label_content' => base64_decode($response['label_base64'] ?? '', true) ?: '%PDF-1.4 test label'];
        }

        $id = strtoupper($carrier).'-TEST-'.Str::upper(Str::random(10));
        return ['tracking_number' => $id, 'carrier_shipment_id' => $id, 'label_content' => "%PDF-1.4\n% GPS Product Hub test label $id\n"];
    }

    protected function storeLabel(string $carrier, string $carrierShipmentId, string $content): string
    {
        $path = 'shipments/labels/'.$carrier.'/'.$carrierShipmentId.'.pdf';
        Storage::disk('local')->put($path, $content);
        return $path;
    }

    protected function sanitizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'secret', 'token', 'api_key'], true)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->sanitizePayload($value);
            }
        }
        return $payload;
    }

    protected function safetyFlags(bool $confirm): array
    {
        return [
            'read_only' => ! $confirm, 'shipment_created' => false, 'label_created' => false, 'pickup_ordered' => false,
            'emails_sent' => false, 'marketplace_status_changed' => false, 'marketplace_tracking_uploaded' => false,
            'products_changed' => false, 'parts_changed' => false, 'offers_changed' => false, 'listings_changed' => false,
            'stock_changed' => false, 'prices_changed' => false, 'mappings_changed' => false, 'allegro_write' => false,
            'ovoko_write' => false, 'ebay_write' => false,
        ];
    }
}
