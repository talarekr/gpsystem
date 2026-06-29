<?php

namespace App\Services\Shipments;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Marketplace\ApiIntegrationLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SoapClient;
use SoapFault;

class DhlShipmentService
{
    public function defaults(?Order $order = null, ?Shipment $shipment = null): array
    {
        $senderAddress = $this->splitStreet((string) config('services.shipments.sender.address'));
        $receiverAddress = $this->splitStreet((string) $order?->address_line1);
        $reference = $order?->order_number ?: ($order ? 'ORDER-'.$order->id : ($shipment ? 'SHIPMENT-'.$shipment->id : ''));

        return [
            'order_id' => $order?->id,
            'shipper' => [
                'name' => config('services.shipments.sender.name'),
                'country' => config('services.shipments.sender.country', 'PL'),
                'postal_code' => config('services.shipments.sender.postal_code'),
                'city' => config('services.shipments.sender.city'),
                'street' => $senderAddress['street'],
                'house_number' => $senderAddress['house_number'],
                'apartment_number' => $senderAddress['apartment_number'],
                'person_name' => config('services.shipments.sender.contact_name', config('services.shipments.sender.name')),
                'email' => config('services.shipments.sender.email'),
                'phone' => config('services.shipments.sender.phone'),
            ],
            'receiver' => [
                'receiver_type' => $order?->company_name ? 'company' : 'private',
                'short_name' => '',
                'name' => $order?->company_name ?: $order?->customer_name,
                'sap_number' => '',
                'country' => $order?->country ?: 'PL',
                'postal_code' => $order?->postal_code,
                'city' => $order?->city,
                'street' => $receiverAddress['street'],
                'house_number' => $receiverAddress['house_number'],
                'apartment_number' => $receiverAddress['apartment_number'],
                'person_name' => $order?->customer_name,
                'email' => $order?->email,
                'phone' => $order?->phone,
                'neighbour_delivery' => false,
                'save_to_address_book' => false,
            ],
            'parcel' => [
                'quantity' => 1,
                'type' => 'PACKAGE',
                'weight' => 1,
                'length' => 40,
                'width' => 30,
                'height' => 20,
                'non_standard' => false,
                'volumetric' => false,
                'euro_return' => false,
                'half_pallet' => false,
                'mpk' => '1142',
                'dhl_option' => '',
                'content' => 'Części samochodowe',
                'comment' => '',
                'reference' => $reference,
            ],
            'service' => [
                'service_type' => config('services.dhl.default_service', 'AH'),
                'shipment_date' => now()->toDateString(),
                'shipment_start_hour' => '12:00',
                'shipment_end_hour' => '15:00',
                'drop_off_type' => config('services.dhl.drop_off_type', 'REGULAR_PICKUP') === 'REQUEST_COURIER' ? 'REGULAR_PICKUP' : config('services.dhl.drop_off_type', 'REGULAR_PICKUP'),
                'order_courier' => false,
                'label_type' => config('services.dhl.label_type', 'LBLP'),
            ],
            'special_services' => [
                'insurance' => false,
                'insurance_value' => null,
                'cod' => false,
                'cod_value' => null,
                'pdi' => false,
                'pod' => false,
                'rod' => false,
                'sas' => false,
                'odb' => false,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'dhlForm.order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'dhlForm.shipper.name' => ['required', 'string', 'max:60'],
            'dhlForm.shipper.country' => ['required', 'string', 'size:2'],
            'dhlForm.shipper.postal_code' => ['required', 'string', 'max:10'],
            'dhlForm.shipper.city' => ['required', 'string', 'max:17'],
            'dhlForm.shipper.street' => ['required', 'string', 'max:35'],
            'dhlForm.shipper.house_number' => ['required', 'string', 'max:10'],
            'dhlForm.shipper.apartment_number' => ['nullable', 'string', 'max:10'],
            'dhlForm.shipper.person_name' => ['nullable', 'string', 'max:50'],
            'dhlForm.shipper.email' => ['nullable', 'email', 'max:60'],
            'dhlForm.shipper.phone' => ['nullable', 'string', 'max:20'],
            'dhlForm.receiver.receiver_type' => ['required', 'in:private,company'],
            'dhlForm.receiver.short_name' => ['nullable', 'string', 'max:60'],
            'dhlForm.receiver.name' => ['required', 'string', 'max:60'],
            'dhlForm.receiver.sap_number' => ['nullable', 'string', 'max:20'],
            'dhlForm.receiver.country' => ['required', 'string', 'size:2'],
            'dhlForm.receiver.postal_code' => ['required', 'string', 'max:10'],
            'dhlForm.receiver.city' => ['required', 'string', 'max:17'],
            'dhlForm.receiver.street' => ['required', 'string', 'max:35'],
            'dhlForm.receiver.house_number' => ['required', 'string', 'max:10'],
            'dhlForm.receiver.apartment_number' => ['nullable', 'string', 'max:10'],
            'dhlForm.receiver.person_name' => ['nullable', 'string', 'max:50'],
            'dhlForm.receiver.email' => ['nullable', 'email', 'max:60'],
            'dhlForm.receiver.phone' => ['nullable', 'string', 'max:20'],
            'dhlForm.receiver.neighbour_delivery' => ['nullable', 'boolean'],
            'dhlForm.receiver.save_to_address_book' => ['nullable', 'boolean'],
            'dhlForm.parcel.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'dhlForm.parcel.type' => ['required', 'in:PACKAGE,ENVELOPE,PALLET'],
            'dhlForm.parcel.weight' => ['required_unless:dhlForm.parcel.type,ENVELOPE', 'numeric', 'min:0.1', 'max:999'],
            'dhlForm.parcel.length' => ['required_unless:dhlForm.parcel.type,ENVELOPE', 'integer', 'min:1', 'max:999'],
            'dhlForm.parcel.width' => ['required_unless:dhlForm.parcel.type,ENVELOPE', 'integer', 'min:1', 'max:999'],
            'dhlForm.parcel.height' => ['required_unless:dhlForm.parcel.type,ENVELOPE', 'integer', 'min:1', 'max:999'],
            'dhlForm.parcel.content' => ['required', 'string', 'max:30'],
            'dhlForm.parcel.comment' => ['nullable', 'string', 'max:100'],
            'dhlForm.parcel.reference' => ['nullable', 'string', 'max:200'],
            'dhlForm.parcel.non_standard' => ['nullable', 'boolean'],
            'dhlForm.parcel.volumetric' => ['nullable', 'boolean'],
            'dhlForm.parcel.euro_return' => ['nullable', 'boolean'],
            'dhlForm.parcel.half_pallet' => ['nullable', 'boolean'],
            'dhlForm.parcel.mpk' => ['nullable', 'string', 'max:20'],
            'dhlForm.parcel.dhl_option' => ['nullable', 'string', 'max:30'],
            'dhlForm.service.service_type' => ['required', 'in:AH,09,12,DW,SP,EK,PI,PR,CP,CM'],
            'dhlForm.service.shipment_date' => ['required', 'date'],
            'dhlForm.service.drop_off_type' => ['required', 'in:REGULAR_PICKUP,REQUEST_COURIER'],
            'dhlForm.service.label_type' => ['required', 'in:BLP,LBLP'],
            'dhlForm.special_services.insurance_value' => ['required_if:dhlForm.special_services.insurance,true,1', 'nullable', 'numeric', 'min:0.01'],
            'dhlForm.special_services.cod_value' => ['required_if:dhlForm.special_services.cod,true,1', 'nullable', 'numeric', 'min:0.01'],
        ];
    }

    public function normalizeForm(array $form): array
    {
        if (data_get($form, 'parcel.type') !== 'PALLET') {
            data_set($form, 'parcel.euro_return', false);
        }

        foreach (['insurance_value', 'cod_value'] as $key) {
            $value = data_get($form, 'special_services.'.$key);
            if (is_string($value)) {
                data_set($form, 'special_services.'.$key, str_replace(',', '.', trim($value)));
            }
        }

        return $form;
    }

    public function create(array $form): Shipment
    {
        $form = $this->normalizeForm($form);

        if ((bool) data_get($form, 'service.order_courier') === false) {
            data_set($form, 'service.drop_off_type', 'REGULAR_PICKUP');
        }

        $payload = $this->payload($form);
        $startedAt = microtime(true);
        try {
            $response = $this->callCreateShipment($payload);
        } catch (RuntimeException $exception) {
            app(ApiIntegrationLogger::class)->error('dhl', 'createShipment', $exception, [
                'order_id' => $form['order_id'] ?? null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request' => $payload,
            ]);
            throw $exception;
        }
        $waybill = (string) ($response['shipmentNotificationNumber'] ?? $response['wayBill'] ?? $response['tracking_number'] ?? '');
        $labelContent = (string) ($response['labelContent'] ?? '');

        if ($waybill === '' || $labelContent === '') {
            $exception = new RuntimeException('DHL nie zwrócił numeru przesyłki lub zawartości etykiety PDF.');
            app(ApiIntegrationLogger::class)->error('dhl', 'createShipment', $exception, [
                'order_id' => $form['order_id'] ?? null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request' => $payload,
                'response' => Arr::except($response, ['labelContent']),
            ]);
            throw $exception;
        }

        $labelBinary = base64_decode($labelContent, true);
        if ($labelBinary === false) {
            $exception = new RuntimeException('DHL zwrócił niepoprawną etykietę base64.');
            app(ApiIntegrationLogger::class)->error('dhl', 'createShipment', $exception, [
                'order_id' => $form['order_id'] ?? null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'tracking_number' => $waybill,
                'external_id' => $waybill,
                'request' => $payload,
                'response' => Arr::except($response, ['labelContent']),
            ]);
            throw $exception;
        }

        $path = 'shipments/labels/dhl/'.$waybill.'.pdf';
        Storage::disk('local')->put($path, $labelBinary);

        $shipment = Shipment::query()->create([
            'order_id' => $form['order_id'] ?? null,
            'carrier' => 'dhl',
            'service_code' => data_get($form, 'service.service_type', 'AH'),
            'shipment_status' => 'label_created',
            'tracking_number' => $waybill,
            'carrier_shipment_id' => $waybill,
            'label_path' => $path,
            'label_format' => $response['labelFormat'] ?? 'application/pdf',
            'sender_snapshot' => $form['shipper'] ?? [],
            'receiver_snapshot' => $form['receiver'] ?? [],
            'parcel_snapshot' => $form['parcel'] ?? [],
            'request_payload' => $this->sanitize($payload),
            'response_payload' => $this->sanitize(Arr::except($response, ['labelContent'])),
            'test_mode' => (bool) config('services.dhl.test_mode', true),
        ]);

        app(ApiIntegrationLogger::class)->success('dhl', 'createShipment', 'DHL shipment created.', [
            'order_id' => $shipment->order_id,
            'shipment_id' => $shipment->id,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'tracking_number' => $waybill,
            'external_id' => $waybill,
            'request' => $payload,
            'response' => Arr::except($response, ['labelContent']) + ['label_path' => $path],
        ]);

        return $shipment;
    }

    public function payload(array $form): array
    {
        $dropOffType = data_get($form, 'service.order_courier') ? 'REQUEST_COURIER' : 'REGULAR_PICKUP';

        return [
            'authData' => ['username' => config('services.dhl.login'), 'password' => config('services.dhl.password')],
            'shipment' => $this->filled([
                'shipmentInfo' => [
                    'dropOffType' => $dropOffType,
                    'serviceType' => data_get($form, 'service.service_type', 'AH'),
                    'billing' => [
                        'shippingPaymentType' => 'SHIPPER',
                        'billingAccountNumber' => config('services.dhl.account_number'),
                        'paymentType' => 'BANK_TRANSFER',
                        'costsCenter' => data_get($form, 'parcel.mpk'),
                    ],
                    'specialServices' => $this->specialServices($form),
                    'shipmentTime' => [
                        'shipmentDate' => data_get($form, 'service.shipment_date'),
                        'shipmentStartHour' => data_get($form, 'service.shipment_start_hour', '12:00'),
                        'shipmentEndHour' => data_get($form, 'service.shipment_end_hour', '15:00'),
                    ],
                    'labelType' => data_get($form, 'service.label_type', 'LBLP'),
                ],
                'content' => data_get($form, 'parcel.content', 'Części samochodowe'),
                'comment' => data_get($form, 'parcel.comment'),
                'reference' => data_get($form, 'parcel.reference'),
                'ship' => ['shipper' => $this->party($form['shipper'] ?? [], false), 'receiver' => $this->party($form['receiver'] ?? [], true)],
                'pieceList' => ['item' => [$this->piece($form['parcel'] ?? [])]],
            ]),
        ];
    }


    public function trackShipment(string $shipmentId): array
    {
        $shipmentId = trim($shipmentId);
        if ($shipmentId === '') {
            throw new RuntimeException('Brak numeru przesyłki DHL.');
        }

        $endpoint = (string) config('services.dhl.endpoint');
        if ($endpoint === '') {
            return ['shipmentId' => $shipmentId, 'receivedBy' => null, 'events' => []];
        }

        $startedAt = microtime(true);
        try {
            $response = (array) (new SoapClient($endpoint, ['trace' => false, 'exceptions' => true]))
                ->__soapCall('getTrackAndTraceInfo', [[
                    'authData' => ['username' => config('services.dhl.login'), 'password' => config('services.dhl.password')],
                    'shipmentId' => $shipmentId,
                ]]);
        } catch (SoapFault $exception) {
            $wrapped = new RuntimeException('Błąd DHL getTrackAndTraceInfo: '.$exception->getMessage(), previous: $exception);
            app(ApiIntegrationLogger::class)->error('dhl', 'getTrackAndTraceInfo', $wrapped, [
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'tracking_number' => $shipmentId,
                'external_id' => $shipmentId,
                'request' => ['shipmentId' => $shipmentId],
            ]);
            throw $wrapped;
        }

        $normalized = $this->normalizeTrackingResponse($response, $shipmentId);
        app(ApiIntegrationLogger::class)->success('dhl', 'getTrackAndTraceInfo', 'DHL tracking fetched.', [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'tracking_number' => $shipmentId,
            'external_id' => $shipmentId,
            'request' => ['shipmentId' => $shipmentId],
            'response' => ['events_count' => count($normalized['events'] ?? []), 'receivedBy' => $normalized['receivedBy'] ?? null],
        ]);

        return $normalized;
    }

    public function countryOptions(): array
    {
        return Cache::remember('dhl.international_countries.v2', now()->addDay(), function (): array {
            return $this->fetchCountryOptionsFromDhl() ?: $this->fallbackCountryOptions();
        });
    }

    protected function fetchCountryOptionsFromDhl(): array
    {
        $endpoint = (string) config('services.dhl.endpoint');
        if ($endpoint === '' || blank(config('services.dhl.login')) || blank(config('services.dhl.password'))) {
            return [];
        }

        try {
            $response = (array) (new SoapClient($endpoint, ['trace' => false, 'exceptions' => true]))
                ->__soapCall('getInternationalParams2', [[
                    'authData' => ['username' => config('services.dhl.login'), 'password' => config('services.dhl.password')],
                ]]);
        } catch (SoapFault) {
            return [];
        }

        $items = data_get($response, 'params.item', data_get($response, 'item', []));
        if (is_object($items)) {
            $items = [$items];
        }

        $countries = ['PL' => 'Polska'];
        foreach ((array) $items as $item) {
            $country = (array) $item;
            $code = strtoupper((string) ($country['countryCode'] ?? ''));
            $name = trim((string) ($country['countryName'] ?? ''));
            if (strlen($code) === 2 && $name !== '') {
                $countries[$code] = $name;
            }
        }

        asort($countries, SORT_LOCALE_STRING);
        return $countries;
    }

    protected function fallbackCountryOptions(): array
    {
        return [
            'PL' => 'Polska',
            'AT' => 'Austria',
            'BE' => 'Belgia',
            'CZ' => 'Czechy',
            'DE' => 'Niemcy',
            'DK' => 'Dania',
            'EE' => 'Estonia',
            'ES' => 'Hiszpania',
            'FI' => 'Finlandia',
            'FR' => 'Francja',
            'HU' => 'Węgry',
            'IT' => 'Włochy',
            'LT' => 'Litwa',
            'LV' => 'Łotwa',
            'NL' => 'Holandia',
            'NO' => 'Norwegia',
            'PT' => 'Portugalia',
            'SE' => 'Szwecja',
            'SK' => 'Słowacja',
        ];
    }

    protected function normalizeTrackingResponse(array $response, string $fallbackShipmentId): array
    {
        $events = data_get($response, 'events.item', data_get($response, 'events', []));
        if (is_object($events)) {
            $events = [$events];
        }

        $normalizedEvents = [];
        foreach ((array) $events as $event) {
            $event = (array) $event;
            $normalizedEvents[] = [
                'status' => $event['status'] ?? null,
                'description' => $event['description'] ?? null,
                'timestamp' => $event['timestamp'] ?? null,
                'terminal' => $event['terminal'] ?? null,
            ];
        }

        return [
            'shipmentId' => (string) ($response['shipmentId'] ?? $fallbackShipmentId),
            'receivedBy' => $response['receivedBy'] ?? null,
            'events' => $normalizedEvents,
        ];
    }

    protected function filled(array $payload): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = $this->filled($value);
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    protected function callCreateShipment(array $payload): array
    {
        $endpoint = (string) config('services.dhl.endpoint');
        if ($endpoint === '') {
            $id = 'DHL-TEST-'.Str::upper(Str::random(10));
            return ['shipmentNotificationNumber' => $id, 'labelType' => data_get($payload, 'shipment.shipmentInfo.labelType', 'LBLP'), 'labelFormat' => 'application/pdf', 'labelContent' => base64_encode("%PDF-1.4\n% GPS DHL test label {$id}\n")];
        }

        try {
            $client = new SoapClient($endpoint, ['trace' => false, 'exceptions' => true]);
            return (array) $client->__soapCall('createShipment', [$payload]);
        } catch (SoapFault $exception) {
            throw new RuntimeException('Błąd DHL createShipment: '.$exception->getMessage(), previous: $exception);
        }
    }

    protected function party(array $data, bool $receiver): array
    {
        $address = [
            'name' => $data['name'] ?? null,
            'postalCode' => $this->postal((string) ($data['postal_code'] ?? '')),
            'city' => $data['city'] ?? null,
            'street' => $data['street'] ?? null,
            'houseNumber' => $data['house_number'] ?? null,
            'apartmentNumber' => $data['apartment_number'] ?? null,
        ];
        if ($receiver) {
            $address = array_merge(['addressType' => ($data['receiver_type'] ?? null) === 'company' ? 'B' : 'C', 'country' => $data['country'] ?? 'PL'], $address);
        }

        return ['preaviso' => $this->contact($data), 'contact' => $this->contact($data), 'address' => $address];
    }

    protected function contact(array $data): array
    {
        return ['personName' => $data['person_name'] ?? $data['name'] ?? null, 'phoneNumber' => $this->phone((string) ($data['phone'] ?? '')), 'emailAddress' => $data['email'] ?? null];
    }

    protected function piece(array $parcel): array
    {
        $type = in_array(($parcel['type'] ?? null), ['ENVELOPE', 'PACKAGE', 'PALLET'], true) ? $parcel['type'] : 'PACKAGE';
        $piece = ['type' => $type, 'quantity' => (int) ($parcel['quantity'] ?? 1), 'nonStandard' => (bool) ($parcel['non_standard'] ?? false)];

        if ($type !== 'ENVELOPE') {
            $piece += ['weight' => (int) ceil((float) ($parcel['weight'] ?? 1)), 'width' => (int) ($parcel['width'] ?? 1), 'height' => (int) ($parcel['height'] ?? 1), 'length' => (int) ($parcel['length'] ?? 1)];
        }

        if ($type === 'PALLET' && (bool) ($parcel['euro_return'] ?? false)) {
            $piece['euroReturn'] = true;
        }

        return $piece;
    }

    protected function specialServices(array $form): array
    {
        $services = [];
        foreach ([['insurance', 'UBEZP', 'insurance_value'], ['cod', 'COD', 'cod_value']] as [$flag, $type, $valueKey]) {
            if (data_get($form, 'special_services.'.$flag)) {
                $services[] = ['serviceType' => $type, 'serviceValue' => (float) str_replace(',', '.', (string) data_get($form, 'special_services.'.$valueKey)), ...($type === 'COD' ? ['collectOnDeliveryForm' => 'BANK_TRANSFER'] : [])];
            }
        }
        foreach (['pdi' => 'PDI', 'pod' => 'POD', 'rod' => 'ROD', 'sas' => 'SAS', 'odb' => 'ODB'] as $flag => $type) {
            if (data_get($form, 'special_services.'.$flag) || ($flag === 'sas' && data_get($form, 'receiver.neighbour_delivery'))) {
                $services[] = ['serviceType' => $type];
            }
        }
        return $services === [] ? [] : ['item' => $services];
    }

    protected function splitStreet(string $address): array
    {
        $address = trim($address);
        if (preg_match('/^(.*?)[,\s]+(\d+[\pL\pN\/-]*)(?:\/(\d+[\pL\pN\/-]*))?$/u', $address, $matches)) {
            return ['street' => trim($matches[1]), 'house_number' => $matches[2], 'apartment_number' => $matches[3] ?? ''];
        }
        return ['street' => $address, 'house_number' => '', 'apartment_number' => ''];
    }

    protected function postal(string $postal): string
    {
        return preg_replace('/\D+/', '', $postal) ?: $postal;
    }

    protected function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        return strlen($digits) > 9 && str_starts_with($digits, '48') ? substr($digits, -9) : substr($digits, 0, 9);
    }

    protected function sanitize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'secret', 'token', 'api_key', 'labelcontent'], true)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }
        return $payload;
    }
}
