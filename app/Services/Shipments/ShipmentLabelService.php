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
        $invalid = $this->invalid($sender, 'sender');

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
            'validation' => ['ok' => $missing === [] && $invalid === [], 'missing' => $missing, 'invalid' => $invalid],
            'configuration' => ['ok' => count(array_filter($this->missing($this->carrierConfig($carrier), $carrier, ['account_number', 'login']))) === 0],
            'debug' => $this->debugConfiguration($carrier, $sender, $receiver),
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
        $address = $order?->address_line1;
        $postalCode = $order?->postal_code;
        $city = $order?->city;
        $country = $order?->country ?: 'PL';
        $parse = $this->parseImportedReceiverAddress($address, $postalCode, $city, $country);

        if ($parse['used']) {
            $address = $parse['address'];
            $postalCode = $parse['postal_code'];
            $city = $parse['city'];
            $country = $parse['country'];
        }

        return [
            'name' => $order?->company_name ?: $order?->customer_name, 'address' => $address,
            'postal_code' => $postalCode, 'city' => $city, 'country' => $country,
            'phone' => $order?->phone, 'email' => $order?->email,
            'debug' => [
                'receiver_address_source' => $parse['source'],
                'receiver_address_parse_used' => $parse['used'],
                'receiver_address_parse_pattern' => $parse['pattern'],
                'receiver_address_parse_warning' => $parse['warning'],
                'receiver_country_detected_from_address' => $parse['country_detected_from_address'],
            ],
        ];
    }

    protected function parseImportedReceiverAddress(?string $address, ?string $postalCode, ?string $city, ?string $country): array
    {
        $result = [
            'used' => false,
            'source' => 'order.address_line1',
            'pattern' => null,
            'warning' => null,
            'address' => $address,
            'postal_code' => $postalCode,
            'city' => $city,
            'country' => $country ?: 'PL',
            'country_detected_from_address' => false,
        ];

        $needsFallback = filled($address) && (blank($postalCode) || $postalCode === '-' || blank($city) || $city === '-');
        if (! $needsFallback || ! str_contains((string) $address, ',')) {
            return $result;
        }

        $segments = array_values(array_filter(array_map(fn ($part) => trim((string) $part), explode(',', (string) $address)), fn ($part) => $part !== ''));
        if (count($segments) < 3) {
            $result['warning'] = 'Address fallback skipped: not enough comma-separated segments.';
            return $result;
        }

        $last = array_pop($segments);
        if (! preg_match('/^([A-Z]{2})-([A-Z0-9][A-Z0-9 -]{1,15})$/i', $last, $matches)) {
            $result['warning'] = 'Address fallback skipped: last segment is not COUNTRY-POSTAL_CODE.';
            return $result;
        }

        $parsedCity = array_pop($segments);
        $pattern = count($segments) === 2 && preg_match('/^\d+[\pL\pN\s\/-]*$/u', $segments[1])
            ? 'street_number_city_country_postal'
            : 'address_city_country_postal';
        $parsedAddress = trim(implode(' ', $segments));
        if ($parsedAddress === '' || $parsedCity === '') {
            $result['warning'] = 'Address fallback skipped: parsed street or city is empty.';
            return $result;
        }

        return [
            'used' => true,
            'source' => 'order.address_line1_fallback',
            'pattern' => $pattern,
            'warning' => null,
            'address' => $parsedAddress,
            'postal_code' => trim($matches[2]),
            'city' => $parsedCity,
            'country' => strtoupper($matches[1]),
            'country_detected_from_address' => true,
        ];
    }

    protected function carrierConfig(string $carrier): array
    {
        return ['account_number' => config("services.$carrier.account_number"), 'login' => config("services.$carrier.login"), 'password' => config("services.$carrier.password"), 'endpoint' => config("services.$carrier.endpoint"), 'label_type' => config("services.$carrier.label_type"), 'drop_off_type' => config("services.$carrier.drop_off_type")];
    }


    protected function debugConfiguration(string $carrier, array $sender, array $receiver): array
    {
        $config = $this->carrierConfig($carrier);

        return [
            'env_keys_expected' => [
                'sender' => ['SHIPMENT_SENDER_NAME/SENDER_NAME', 'SHIPMENT_SENDER_ADDRESS/SENDER_ADDRESS', 'SHIPMENT_SENDER_POSTAL_CODE/SENDER_POSTAL_CODE', 'SHIPMENT_SENDER_CITY/SENDER_CITY', 'SHIPMENT_SENDER_COUNTRY/SENDER_COUNTRY', 'SHIPMENT_SENDER_PHONE/SENDER_PHONE', 'SHIPMENT_SENDER_EMAIL/SENDER_EMAIL'],
                'dhl' => ['DHL_API_LOGIN/DHL24_LOGIN/DHL24_USERNAME', 'DHL_API_PASSWORD/DHL24_PASSWORD', 'DHL_ACCOUNT_NUMBER/DHL24_ACCOUNT_NUMBER', 'DHL_API_ENDPOINT/DHL24_WSDL', 'DHL_LABEL_TYPE/DHL24_LABEL_TYPE', 'DHL_DROP_OFF_TYPE/DHL24_DEFAULT_DROP_OFF_TYPE'],
            ],
            'config_paths_checked' => ['services.shipments.sender.*', "services.$carrier.login", "services.$carrier.password", "services.$carrier.account_number", "services.$carrier.endpoint", "services.$carrier.label_type", "services.$carrier.drop_off_type"],
            'sender_config_present' => collect(['name', 'address', 'postal_code', 'city', 'phone', 'email'])->every(fn ($key) => filled($sender[$key] ?? null)),
            'sender_name_present' => filled($sender['name'] ?? null),
            'sender_address_present' => filled($sender['address'] ?? null),
            'sender_postal_code_present' => filled($sender['postal_code'] ?? null),
            'sender_city_present' => filled($sender['city'] ?? null),
            'sender_phone_present' => filled($sender['phone'] ?? null),
            'sender_email_present' => filled($sender['email'] ?? null),
            'dhl_login_present' => filled($config['login'] ?? null),
            'dhl_password_present' => filled($config['password'] ?? null),
            'dhl_account_number_present' => filled($config['account_number'] ?? null),
            'dhl_wsdl_present' => filled($config['endpoint'] ?? null),
            'dhl_label_type' => $config['label_type'] ?? null,
            'dhl_drop_off_type' => $config['drop_off_type'] ?? null,
            'config_cache_hint' => app()->configurationIsCached() ? 'Configuration is cached; run php artisan config:clear && php artisan config:cache after .env changes.' : 'Configuration is not cached in this runtime.',
            'receiver_address_source' => $receiver['debug']['receiver_address_source'] ?? null,
            'receiver_address_parse_used' => $receiver['debug']['receiver_address_parse_used'] ?? false,
            'receiver_address_parse_pattern' => $receiver['debug']['receiver_address_parse_pattern'] ?? null,
            'receiver_address_parse_warning' => $receiver['debug']['receiver_address_parse_warning'] ?? null,
            'receiver_country_detected_from_address' => $receiver['debug']['receiver_country_detected_from_address'] ?? false,
        ];
    }

    protected function missing(array $data, string $prefix, array $keys): array
    {
        return collect($keys)->filter(fn ($key) => blank($data[$key] ?? null))->map(fn ($key) => "$prefix.$key")->values()->all();
    }

    protected function invalid(array $sender, string $prefix): array
    {
        return $this->isInvalidSenderPhone($sender['phone'] ?? null) ? ["$prefix.phone"] : [];
    }

    protected function isInvalidSenderPhone(mixed $phone): bool
    {
        $value = trim((string) $phone);
        if ($value === '') {
            return false;
        }

        $normalized = Str::upper(preg_replace('/[^\pL\pN]+/u', '', $value) ?? '');
        if (in_array($normalized, ['TELEFON', 'PHONE', 'TEL', '123', '123456', '000000000'], true)) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) < 7 || preg_match('/^(\d)\1+$/', $digits) === 1;
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
