<?php

namespace App\Services\Shipments;

use App\Models\Order;
use App\Models\MarketplaceSyncLog;
use App\Models\Shipment;
use App\Services\Marketplace\ApiIntegrationLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use SoapClient;
use SoapFault;

class DhlShipmentService
{
    private const DOMESTIC_COUNTRY = 'PL';

    private const DOMESTIC_SERVICE_TYPES = ['AH', '09', '12', 'DW', 'SP'];

    public function defaults(?Order $order = null, ?Shipment $shipment = null): array
    {
        $senderAddress = $this->splitStreet((string) config('services.shipments.sender.address'));
        $receiver = $this->receiverDefaults($order);
        $receiverAddress = $this->splitStreet((string) ($receiver['address_line1'] ?? ''));
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
                'receiver_type' => $receiver['receiver_type'],
                'short_name' => '',
                'name' => $receiver['name'],
                'sap_number' => '',
                'country' => $receiver['country'],
                'postal_code' => $receiver['postal_code'],
                'city' => $receiver['city'],
                'street' => $receiverAddress['street'],
                'house_number' => $receiverAddress['house_number'],
                'apartment_number' => $receiverAddress['apartment_number'],
                'person_name' => $receiver['person_name'],
                'email' => $receiver['email'],
                'phone' => $receiver['phone'],
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
                'service_type' => $this->selectServiceTypeForCountries(
                    (string) config('services.shipments.sender.country', self::DOMESTIC_COUNTRY),
                    (string) ($receiver['country'] ?? ''),
                    (string) config('services.dhl.default_service', 'AH')
                ),
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

    protected function receiverDefaults(?Order $order): array
    {
        if (! $order) {
            return [
                'receiver_type' => 'private',
                'name' => null,
                'address_line1' => null,
                'postal_code' => null,
                'city' => null,
                'country' => 'PL',
                'person_name' => null,
                'email' => null,
                'phone' => null,
            ];
        }

        if (str_starts_with(Str::lower(trim((string) $order->marketplace)), 'ebay')) {
            return $this->ebayShippingReceiverDefaults($order);
        }

        $name = $this->firstFilled([$order->company_name, $order->customer_name]);

        return [
            'receiver_type' => $order->company_name ? 'company' : 'private',
            'name' => $name,
            'address_line1' => $order->address_line1,
            'postal_code' => $order->postal_code,
            'city' => $order->city,
            'country' => $order->country ?: 'PL',
            'person_name' => $order->customer_name ?: $name,
            'email' => $order->email,
            'phone' => $order->phone,
        ];
    }

    protected function ebayShippingReceiverDefaults(Order $order): array
    {
        $payload = $order->raw_payload ?? [];
        $shipTo = $this->firstArray([
            data_get($payload, 'fulfillmentStartInstructions.0.shippingStep.shipTo'),
            data_get($payload, 'shipTo'),
            data_get($payload, 'shippingAddress'),
            data_get($payload, 'delivery.address'),
            data_get($payload, 'shipping.address'),
            data_get($payload, 'fulfillment.shippingStep.shipTo'),
        ]) ?? [];
        $contactAddress = $this->firstArray([
            data_get($shipTo, 'contactAddress'),
            data_get($shipTo, 'address'),
            $shipTo,
        ]) ?? [];
        $name = $this->firstFilled([
            data_get($shipTo, 'companyName'),
            data_get($contactAddress, 'companyName'),
            data_get($contactAddress, 'company'),
            data_get($shipTo, 'fullName'),
            data_get($shipTo, 'name'),
            data_get($contactAddress, 'fullName'),
            data_get($contactAddress, 'name'),
            $order->company_name,
            $order->customer_name,
        ]);
        $addressLine1 = $this->joinFilled([
            data_get($contactAddress, 'addressLine1'),
            data_get($contactAddress, 'addressLine2'),
        ]) ?: $order->address_line1;
        $email = $this->realEmail($this->firstFilled([
            data_get($shipTo, 'email'),
            data_get($shipTo, 'emailAddress'),
            data_get($contactAddress, 'email'),
            data_get($contactAddress, 'emailAddress'),
        ]));

        return [
            'receiver_type' => 'private',
            'name' => $name,
            'address_line1' => $addressLine1,
            'postal_code' => $this->firstFilled([data_get($contactAddress, 'postalCode'), data_get($contactAddress, 'zipCode'), data_get($contactAddress, 'postcode'), $order->postal_code]),
            'city' => $this->firstFilled([data_get($contactAddress, 'city'), $order->city]),
            'country' => $this->firstFilled([data_get($contactAddress, 'countryCode'), data_get($contactAddress, 'country'), $order->country]) ?: 'PL',
            'person_name' => $this->firstFilled([data_get($shipTo, 'fullName'), data_get($shipTo, 'name'), data_get($contactAddress, 'fullName'), data_get($contactAddress, 'name'), $name]),
            'email' => $email,
            'phone' => $this->firstFilled([data_get($shipTo, 'primaryPhone.phoneNumber'), data_get($shipTo, 'primaryPhone.number'), data_get($shipTo, 'phoneNumber'), data_get($shipTo, 'phone'), $order->phone]),
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

        $duplicateGuard = $this->duplicateCreateShipmentGuard((int) ($form['order_id'] ?? 0));
        if ($duplicateGuard['would_create_duplicate_if_clicked_again']) {
            $tracking = $duplicateGuard['remote_tracking_number'] ?? $duplicateGuard['local_tracking_number'] ?? null;
            throw new RuntimeException($tracking
                ? 'DHL shipment '.$tracking.' already exists remotely for this order. Fetch existing label instead of creating a duplicate shipment.'
                : 'DHL shipment already exists for this order. Fetch existing label instead of creating a duplicate shipment.');
        }

        $payload = null;
        $serviceSelection = $this->serviceSelectionDiagnostics($form, data_get($form, 'service.service_type'));
        $startedAt = microtime(true);
        try {
            $payload = $this->payload($form);
            $serviceSelection = $this->serviceSelectionDiagnostics($form, data_get($payload, 'shipment.shipmentInfo.serviceType'));
            $response = $this->callCreateShipment($payload);
        } catch (RuntimeException $exception) {
            app(ApiIntegrationLogger::class)->error('dhl', 'createShipment', $exception, [
                'order_id' => $form['order_id'] ?? null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request' => $payload,
                'service_type' => data_get($payload, 'shipment.shipmentInfo.serviceType', $serviceSelection['selected_service_type'] ?? null),
                'receiver_country' => data_get($payload, 'shipment.ship.receiver.address.country', $serviceSelection['receiver_country'] ?? null),
                'dhl_service_selection' => $serviceSelection,
            ]);
            throw $exception;
        }
        $parsedResponse = $this->parseCreateShipmentResponse($response);
        $waybill = (string) ($parsedResponse['tracking_number'] ?? '');
        $labelContent = (string) ($parsedResponse['label_content'] ?? '');

        if ($waybill === '' || $labelContent === '') {
            $exception = new RuntimeException('DHL nie zwrócił numeru przesyłki lub zawartości etykiety PDF.');
            app(ApiIntegrationLogger::class)->error('dhl', 'createShipment', $exception, [
                'order_id' => $form['order_id'] ?? null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request' => $payload,
                'dhl_service_selection' => $serviceSelection,
                'response' => $this->createShipmentResponseForLog($response),
                'remote_created' => $parsedResponse['remote_created'],
                'remote_tracking_number' => $parsedResponse['tracking_number'],
                'label_content_present' => $parsedResponse['has_label_content'],
                'local_persistence_success' => false,
                'failure_classification' => $parsedResponse['remote_created'] ? 'dhl_response_success_local_persist_failed' : 'dhl_response_missing_required_fields',
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
                'dhl_service_selection' => $serviceSelection,
                'response' => $this->createShipmentResponseForLog($response),
                'remote_created' => $parsedResponse['remote_created'],
                'remote_tracking_number' => $parsedResponse['tracking_number'],
                'label_content_present' => $parsedResponse['has_label_content'],
                'local_persistence_success' => false,
                'failure_classification' => $parsedResponse['remote_created'] ? 'dhl_response_success_local_persist_failed' : 'dhl_response_missing_required_fields',
            ]);
            throw $exception;
        }

        try {
            $shipment = $this->persistCreatedShipment($form, $payload ?? [], $response, $parsedResponse, $labelBinary);
            $path = $shipment->label_path;
        } catch (\Throwable $exception) {
            app(ApiIntegrationLogger::class)->error('dhl', 'createShipment', $exception, [
                'order_id' => $form['order_id'] ?? null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'tracking_number' => $waybill,
                'external_id' => $waybill,
                'request' => $payload,
                'response' => $this->createShipmentResponseForLog($response),
                'failure_classification' => 'dhl_response_success_local_persist_failed',
                'remote_created' => true,
                'remote_tracking_number' => $waybill,
                'label_content_present' => true,
                'local_persistence_success' => false,
            ]);
            throw $exception;
        }

        app(ApiIntegrationLogger::class)->success('dhl', 'createShipment', 'DHL shipment created.', [
            'order_id' => $shipment->order_id,
            'shipment_id' => $shipment->id,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'tracking_number' => $waybill,
            'external_id' => $waybill,
            'request' => $payload,
            'service_type' => data_get($payload, 'shipment.shipmentInfo.serviceType'),
            'receiver_country' => data_get($payload, 'shipment.ship.receiver.address.country'),
            'dhl_service_selection' => $serviceSelection,
            'response' => $this->createShipmentResponseForLog($response) + ['label_path' => $path],
        ]);

        return $shipment;
    }



    public function existingLabelFetchDiagnostics(?int $orderId = null, ?string $trackingNumber = null, ?string $packageTrackingNumber = null, string $labelType = 'LBLP'): array
    {
        $shipment = $orderId ? Shipment::query()->where('order_id', $orderId)->where('carrier', 'dhl')->latest('id')->first() : null;
        $log = $this->lastCreateShipmentLog($orderId);
        $parsed = $log ? $this->parseCreateShipmentResponse(data_get($log->payload ?? [], 'response')) : $this->parseCreateShipmentResponse(null);
        $tracking = trim((string) ($trackingNumber ?: ($parsed['tracking_number'] ?? '')));
        $packageTracking = trim((string) ($packageTrackingNumber ?: ($parsed['package_tracking_number'] ?? '')));
        $localLabelExists = $shipment?->label_path ? Storage::disk('local')->exists($shipment->label_path) : false;
        $blocking = [];
        $warnings = [];
        if ($tracking === '') $blocking[] = 'Remote DHL shipment tracking number is missing.';
        if (! in_array($labelType, ['LP', 'BLP', 'LBLP', 'ZBLP', 'ZBLP300', 'QR_PDF', 'QR2_IMG', 'QR4_IMG', 'QR6_IMG'], true)) $blocking[] = 'Unsupported DHL label_type for getLabels.';
        if ($shipment) $warnings[] = 'Local DHL shipment already exists; fetch endpoint will not create a duplicate.';
        if ($localLabelExists) $warnings[] = 'Local DHL label already exists; fetch endpoint will not overwrite it.';

        return [
            'code_marker' => 'dhl_existing_label_fetch_v1',
            'needed' => (bool) ($orderId && ! $shipment && ! $localLabelExists && ($parsed['remote_created'] ?? false) && ! ($parsed['has_label_content'] ?? false)),
            'reason' => 'Remote DHL shipment exists but local labelContent was redacted in stored log.',
            'order_id' => $orderId,
            'remote_tracking_number' => $tracking ?: null,
            'remote_package_tracking_number' => $packageTracking ?: null,
            'local_shipment_exists' => (bool) $shipment,
            'local_label_exists' => $localLabelExists,
            'create_shipment_must_not_be_retried' => true,
            'soap_method_available' => true,
            'soap_method_name' => 'getLabels',
            'candidate_methods' => ['getLabels'],
            'identifier_to_use' => $tracking ?: null,
            'label_type' => $labelType,
            'can_fetch_label_without_createShipment' => $blocking === [],
            'blocking_reasons' => $blocking,
            'warnings' => $warnings,
            'identifier_selection' => [
                'shipment_tracking_number' => $tracking ?: null,
                'package_tracking_number' => $packageTracking ?: null,
                'selected_identifier' => $tracking ?: null,
                'selected_identifier_type' => $tracking !== '' ? 'ItemToPrint.shipmentId (DHL shipmentTrackingNumber / shipmentNotificationNumber)' : null,
                'reason' => 'DHL24 WebAPI v2 getLabels expects itemsToPrint.item[].shipmentId plus labelType; package tracking is stored for audit but is not the getLabels identifier.',
            ],
            'manual_fallback' => $blocking === [] ? null : ($tracking !== '' ? 'Download label manually from DHL24 panel for shipment '.$tracking.'.' : 'Download label manually from DHL24 panel.'),
        ];
    }

    public function fetchExistingLabel(int $orderId, string $trackingNumber, ?string $packageTrackingNumber = null, string $labelType = 'LBLP'): Shipment
    {
        $trackingNumber = trim($trackingNumber);
        $packageTrackingNumber = trim((string) $packageTrackingNumber) ?: null;
        $diagnostics = $this->existingLabelFetchDiagnostics($orderId, $trackingNumber, $packageTrackingNumber, $labelType);
        if ($diagnostics['blocking_reasons'] !== []) throw new RuntimeException(implode(' ', $diagnostics['blocking_reasons']));
        if ($existing = Shipment::query()->where('order_id', $orderId)->where('carrier', 'dhl')->latest('id')->first()) return $existing;

        $requestPayload = $this->existingLabelRequestPayload($trackingNumber, $labelType);
        $startedAt = microtime(true);
        try {
            $response = $this->callGetLabels($requestPayload);
            $parsed = $this->parseGetLabelsResponse($response, $trackingNumber);
            if (! $parsed['has_label_content']) throw new RuntimeException('DHL getLabels did not return labelData/labelContent for shipment '.$trackingNumber.'.');
            $labelBinary = base64_decode((string) $parsed['label_content'], true);
            if ($labelBinary === false) throw new RuntimeException('DHL getLabels returned invalid base64 label content.');

            $shipment = DB::transaction(function () use ($orderId, $trackingNumber, $packageTrackingNumber, $labelType, $requestPayload, $response, $parsed, $labelBinary) {
                if ($existing = Shipment::query()->where('order_id', $orderId)->where('carrier', 'dhl')->lockForUpdate()->latest('id')->first()) return $existing;
                return $this->persistFetchedExistingLabel($orderId, $trackingNumber, $packageTrackingNumber, $labelType, $requestPayload, $response, $parsed, $labelBinary);
            });
        } catch (RuntimeException $exception) {
            app(ApiIntegrationLogger::class)->error('dhl', 'getLabels', $exception, [
                'order_id' => $orderId, 'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'tracking_number' => $trackingNumber, 'external_id' => $trackingNumber, 'request' => $requestPayload,
                'response' => isset($response) ? $this->redactLabelContent($this->toArray($response)) : null,
                'code_marker' => 'dhl_existing_label_fetch_v1',
            ]);
            throw $exception;
        }

        app(ApiIntegrationLogger::class)->success('dhl', 'getLabels', 'DHL existing label fetched.', [
            'order_id' => $orderId, 'shipment_id' => $shipment->id, 'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'tracking_number' => $trackingNumber, 'external_id' => $trackingNumber, 'request' => $requestPayload,
            'response' => $this->redactLabelContent($this->toArray($response)) + ['label_path' => $shipment->label_path],
            'code_marker' => 'dhl_existing_label_fetch_v1',
        ]);
        return $shipment;
    }

    private function existingLabelRequestPayload(string $trackingNumber, string $labelType): array
    {
        return ['authData' => ['username' => config('services.dhl.login'), 'password' => config('services.dhl.password')], 'itemsToPrint' => ['item' => [['labelType' => $labelType, 'shipmentId' => (int) $trackingNumber]]]];
    }

    protected function callGetLabels(array $payload): array
    {
        $endpoint = (string) config('services.dhl.endpoint');
        if ($endpoint === '') return ['getLabelsResult' => ['item' => [['shipmentId' => data_get($payload, 'itemsToPrint.item.0.shipmentId'), 'labelType' => data_get($payload, 'itemsToPrint.item.0.labelType', 'LBLP'), 'labelMimeType' => 'application/pdf', 'labelData' => base64_encode("%PDF-1.4\n% GPS DHL fetched test label\n")]]]];
        try { return (array) (new SoapClient($endpoint, ['trace' => false, 'exceptions' => true]))->__soapCall('getLabels', [$payload]); }
        catch (SoapFault $exception) { throw new RuntimeException('Błąd DHL getLabels: '.$exception->getMessage(), previous: $exception); }
    }

    public function parseGetLabelsResponse(mixed $response, ?string $fallbackTracking = null): array
    {
        $array = $this->toArray($response);
        $items = data_get($array, 'getLabelsResult.item', data_get($array, 'item', data_get($array, 'getLabelsResult', $array)));
        if (isset($items['labelData']) || isset($items['labelContent'])) $items = [$items];
        $first = (array) ((array) $items)[0];
        $content = data_get($first, 'labelData') ?: data_get($first, 'labelContent') ?: data_get($first, 'label.labelContent');
        return [
            'tracking_number' => (string) (data_get($first, 'shipmentId') ?: $fallbackTracking),
            'package_tracking_number' => null,
            'label_content' => filled($content) ? (string) $content : null,
            'label_format' => data_get($first, 'labelMimeType') ?: data_get($first, 'labelFormat') ?: 'application/pdf',
            'label_type' => data_get($first, 'labelType'),
            'has_label_content' => filled($content) && $content !== '[redacted]',
            'remote_created' => filled(data_get($first, 'shipmentId')) || filled($fallbackTracking),
            'result' => $first,
        ];
    }

    private function persistFetchedExistingLabel(int $orderId, string $trackingNumber, ?string $packageTrackingNumber, string $labelType, array $requestPayload, mixed $response, array $parsed, string $labelBinary): Shipment
    {
        $path = 'shipments/labels/dhl/'.$trackingNumber.'.pdf';
        Storage::disk('local')->put($path, $labelBinary);
        return Shipment::query()->create([
            'order_id' => $orderId, 'carrier' => 'dhl', 'service_code' => 'AH', 'shipment_status' => 'label_created',
            'tracking_number' => $trackingNumber, 'carrier_shipment_id' => $trackingNumber, 'label_path' => $path,
            'label_format' => $parsed['label_format'] ?? 'application/pdf', 'sender_snapshot' => [], 'receiver_snapshot' => [],
            'parcel_snapshot' => ['package_tracking_number' => $packageTrackingNumber], 'request_payload' => $this->sanitize($requestPayload),
            'response_payload' => $this->sanitize($this->redactLabelContent($this->toArray($response)) + ['package_tracking_number' => $packageTrackingNumber, 'label_type' => $labelType, 'code_marker' => 'dhl_existing_label_fetch_v1']),
            'test_mode' => (bool) config('services.dhl.test_mode', true),
        ]);
    }

    public function recoverCreatedShipmentFromLog(int $orderId, ?int $logId = null): Shipment
    {
        $existing = Shipment::query()->where('order_id', $orderId)->where('carrier', 'dhl')->latest('id')->first();
        if ($existing) return $existing;
        $log = $logId ? MarketplaceSyncLog::query()->find($logId) : $this->lastCreateShipmentLog($orderId);
        if (! $log || $log->order_id !== $orderId || $log->marketplace !== 'dhl' || $log->action !== 'createShipment') {
            throw new RuntimeException('DHL createShipment log was not found for this order.');
        }
        $response = data_get($log->payload ?? [], 'response');
        $parsed = $this->parseCreateShipmentResponse($response);
        if (! $parsed['remote_created']) throw new RuntimeException('Stored DHL log does not contain shipment tracking number.');
        if (! $parsed['has_label_content']) throw new RuntimeException('Stored log response is sanitized and does not contain labelContent. Remote DHL shipment was created, but PDF cannot be recovered from local logs.');
        $labelBinary = base64_decode((string) $parsed['label_content'], true);
        if ($labelBinary === false) throw new RuntimeException('Stored DHL labelContent is not valid base64.');
        $shipment = $this->persistCreatedShipment(['order_id' => $orderId], (array) data_get($log->payload ?? [], 'request', []), $response, $parsed, $labelBinary, true);
        app(ApiIntegrationLogger::class)->success('dhl', 'recoverCreatedShipment', 'DHL shipment recovered from createShipment log.', [
            'order_id' => $orderId, 'shipment_id' => $shipment->id, 'tracking_number' => $shipment->tracking_number,
            'external_id' => $shipment->carrier_shipment_id, 'recovery_from_log_id' => $log->id, 'remote_created' => true,
            'local_persistence_success' => true, 'code_marker' => 'dhl_response_parser_recovery_v1',
        ]);
        return $shipment;
    }

    private function persistCreatedShipment(array $form, array $payload, mixed $response, array $parsedResponse, string $labelBinary, bool $recovery = false): Shipment
    {
        $waybill = (string) $parsedResponse['tracking_number'];
        $path = 'shipments/labels/dhl/'.$waybill.'.pdf';
        Storage::disk('local')->put($path, $labelBinary);
        return Shipment::query()->create([
            'order_id' => $form['order_id'] ?? null, 'carrier' => 'dhl',
            'service_code' => data_get($payload, 'shipment.shipmentInfo.serviceType', data_get($form, 'service.service_type', 'AH')),
            'shipment_status' => 'label_created', 'tracking_number' => $waybill, 'carrier_shipment_id' => $waybill,
            'label_path' => $path, 'label_format' => $parsedResponse['label_format'] ?? 'application/pdf',
            'sender_snapshot' => $form['shipper'] ?? [], 'receiver_snapshot' => $form['receiver'] ?? [], 'parcel_snapshot' => $form['parcel'] ?? [],
            'request_payload' => $this->sanitize($payload),
            'response_payload' => $this->sanitize($this->createShipmentResponseForLog($response) + ['recovered_from_dhl_create_shipment_log' => $recovery, 'code_marker' => 'dhl_response_parser_recovery_v1']),
            'test_mode' => (bool) config('services.dhl.test_mode', true),
        ]);
    }

    /** @return array{tracking_number:?string,package_tracking_number:?string,label_content:?string,label_format:?string,label_type:?string,has_label_content:bool,remote_created:bool,result:mixed} */
    public function parseCreateShipmentResponse(mixed $response): array
    {
        $array = $this->toArray($response);
        $result = data_get($array, 'createShipmentResult', $array);
        $tracking = data_get($result, 'shipmentTrackingNumber') ?: data_get($result, 'shipmentNotificationNumber') ?: data_get($result, 'wayBill') ?: data_get($result, 'tracking_number');
        $labelContent = data_get($result, 'label.labelContent') ?: data_get($result, 'labelContent');
        $labelFormat = data_get($result, 'label.labelFormat') ?: data_get($result, 'labelFormat');
        $labelType = data_get($result, 'label.labelType') ?: data_get($result, 'labelType');

        return [
            'tracking_number' => filled($tracking) ? (string) $tracking : null,
            'package_tracking_number' => filled(data_get($result, 'packagesTrackingNumbers')) ? (string) data_get($result, 'packagesTrackingNumbers') : null,
            'label_content' => filled($labelContent) ? (string) $labelContent : null,
            'label_format' => filled($labelFormat) ? (string) $labelFormat : null,
            'label_type' => filled($labelType) ? (string) $labelType : null,
            'has_label_content' => filled($labelContent) && $labelContent !== '[redacted]',
            'remote_created' => filled($tracking),
            'result' => $result,
        ];
    }

    public function duplicateCreateShipmentGuard(?int $orderId): array
    {
        if (! $orderId) return ['would_create_duplicate_if_clicked_again' => false];
        $shipment = Shipment::query()->where('order_id', $orderId)->where('carrier', 'dhl')->latest('id')->first();
        $log = $this->lastCreateShipmentLog($orderId);
        $parsed = $log ? $this->parseCreateShipmentResponse(data_get($log->payload ?? [], 'response')) : null;
        $labelExists = $shipment?->label_path ? Storage::disk('local')->exists($shipment->label_path) : false;
        $remoteCreatedInLog = (bool) ($parsed && $parsed['remote_created']);
        return [
            'would_create_duplicate_if_clicked_again' => (bool) ($shipment?->tracking_number || $shipment || $labelExists || $remoteCreatedInLog),
            'local_shipment_exists' => (bool) $shipment,
            'local_label_exists' => $labelExists,
            'last_log_id' => $log?->id,
            'remote_created_in_last_log' => $remoteCreatedInLog,
            'remote_tracking_number' => $parsed['tracking_number'] ?? null,
            'remote_package_tracking_number' => $parsed['package_tracking_number'] ?? null,
            'local_tracking_number' => $shipment?->tracking_number ?: $shipment?->carrier_shipment_id,
        ];
    }

    public function trackingUrl(?string $tracking): ?string
    {
        $tracking = trim((string) $tracking);
        if ($tracking === '') return null;

        return str_replace('{tracking}', rawurlencode($tracking), (string) config('services.dhl.tracking_url_template'));
    }

    public function adminOrderShipmentUiState(int $orderId): array
    {
        $shipment = Shipment::query()->where('order_id', $orderId)->where('carrier', 'dhl')->latest('id')->first();
        $log = $this->lastCreateShipmentLog($orderId);
        $parsed = $log ? $this->parseCreateShipmentResponse(data_get($log->payload ?? [], 'response')) : $this->parseCreateShipmentResponse(null);
        $localShipmentExists = (bool) $shipment;
        $remoteDetected = ! $localShipmentExists && (bool) $parsed['remote_created'];
        $tracking = $parsed['tracking_number'] ?? null;

        return [
            'order_id' => $orderId,
            'local_shipment_exists' => $localShipmentExists,
            'remote_dhl_shipment_detected' => $remoteDetected,
            'remote_tracking_number' => $tracking,
            'remote_package_tracking_number' => $parsed['package_tracking_number'] ?? null,
            'should_show_no_shipment_message' => ! $localShipmentExists && ! $remoteDetected,
            'should_show_add_dhl_shipment_button' => ! $localShipmentExists && ! $remoteDetected,
            'should_show_remote_created_warning' => $remoteDetected,
            'should_show_tracking_link' => $remoteDetected && filled($tracking),
            'tracking_url' => $this->trackingUrl($tracking),
            'should_show_fetch_existing_label_action' => $remoteDetected,
            'create_shipment_blocked_to_prevent_duplicate' => $remoteDetected,
            'last_dhl_create_shipment_log_id' => $log?->id,
            'code_marker' => 'dhl_order_shipment_ui_remote_created_v1',
        ];
    }

    public function lastCreateShipmentLog(?int $orderId): ?MarketplaceSyncLog
    {
        return MarketplaceSyncLog::query()->where('marketplace', 'dhl')->where('action', 'createShipment')
            ->when($orderId, fn ($q) => $q->where('order_id', $orderId))->latest('created_at')->latest('id')->first();
    }

    public function createShipmentResponseForLog(mixed $response): array
    {
        return $this->redactLabelContent($this->toArray($response));
    }

    private function redactLabelContent(array $value): array
    {
        foreach ($value as $key => $item) {
            if (strtolower((string) $key) === 'labelcontent') $value[$key] = '[redacted]';
            elseif (is_array($item)) $value[$key] = $this->redactLabelContent($item);
        }
        return $value;
    }

    private function toArray(mixed $value): array
    {
        return json_decode(json_encode($value), true) ?: [];
    }

    public function payload(array $form): array
    {
        $dropOffType = data_get($form, 'service.order_courier') ? 'REQUEST_COURIER' : 'REGULAR_PICKUP';
        $serviceType = $this->selectedServiceType($form);

        return [
            'authData' => ['username' => config('services.dhl.login'), 'password' => config('services.dhl.password')],
            'shipment' => $this->filled([
                'shipmentInfo' => [
                    'dropOffType' => $dropOffType,
                    'serviceType' => $serviceType,
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


    public function serviceSelectionDiagnostics(array $form, ?string $previousFailedServiceType = null): array
    {
        $shipperCountry = $this->normalizeCountry((string) data_get($form, 'shipper.country', config('services.shipments.sender.country', self::DOMESTIC_COUNTRY)));
        $receiverCountry = $this->normalizeCountry((string) data_get($form, 'receiver.country', ''));
        $domesticServiceType = $this->normalizeServiceType((string) config('services.dhl.default_service', 'AH')) ?: 'AH';
        $internationalServiceType = $this->normalizeServiceType((string) config('services.dhl.default_international_service', 'EK')) ?: 'EK';
        $blockingReasons = [];
        $warnings = [];

        if ($shipperCountry === '') {
            $shipperCountry = self::DOMESTIC_COUNTRY;
            $warnings[] = 'DHL shipper country is blank; defaulting to PL.';
        }

        if ($receiverCountry === '') {
            $blockingReasons[] = 'DHL receiver country is missing; cannot create shipment until receiver country is known.';
        }

        $isDomestic = $receiverCountry !== '' && $shipperCountry === self::DOMESTIC_COUNTRY && $receiverCountry === self::DOMESTIC_COUNTRY;
        $selectedServiceType = $receiverCountry === '' ? null : ($isDomestic ? $domesticServiceType : $internationalServiceType);

        if ($receiverCountry !== '' && ! $isDomestic && $this->isDomesticServiceType($selectedServiceType)) {
            $blockingReasons[] = sprintf(
                'DHL serviceType %s is domestic and cannot be used for receiver country %s. Configure DHL24_DEFAULT_INTERNATIONAL_SERVICE_TYPE.',
                $selectedServiceType,
                $receiverCountry
            );
        }

        return [
            'shipper_country' => $shipperCountry,
            'receiver_country' => $receiverCountry ?: null,
            'is_domestic' => $isDomestic,
            'configured_domestic_service_type' => $domesticServiceType,
            'configured_international_service_type' => $internationalServiceType,
            'selected_service_type' => $selectedServiceType,
            'previous_failed_service_type' => $previousFailedServiceType ? $this->normalizeServiceType($previousFailedServiceType) : null,
            'service_type_changed_for_country' => $previousFailedServiceType !== null && $selectedServiceType !== null && $this->normalizeServiceType($previousFailedServiceType) !== $selectedServiceType,
            'blocking_reasons' => $blockingReasons,
            'warnings' => $warnings,
        ];
    }

    protected function selectedServiceType(array $form): string
    {
        $selection = $this->serviceSelectionDiagnostics($form, data_get($form, 'service.service_type'));
        if ($selection['blocking_reasons'] !== []) {
            throw new RuntimeException(implode(' ', $selection['blocking_reasons']));
        }

        return (string) $selection['selected_service_type'];
    }

    protected function selectServiceTypeForCountries(string $shipperCountry, string $receiverCountry, ?string $currentServiceType = null): string
    {
        return $this->selectedServiceType([
            'shipper' => ['country' => $shipperCountry],
            'receiver' => ['country' => $receiverCountry],
            'service' => ['service_type' => $currentServiceType],
        ]);
    }

    protected function normalizeCountry(string $country): string
    {
        return strtoupper(trim($country));
    }

    protected function normalizeServiceType(?string $serviceType): ?string
    {
        $serviceType = strtoupper(trim((string) $serviceType));
        return $serviceType !== '' ? $serviceType : null;
    }

    protected function isDomesticServiceType(?string $serviceType): bool
    {
        return in_array($this->normalizeServiceType($serviceType), self::DOMESTIC_SERVICE_TYPES, true);
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

    protected function firstArray(array $values): ?array
    {
        foreach ($values as $value) {
            if (is_array($value) && $value !== []) {
                return $value;
            }
        }

        return null;
    }

    protected function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '' && $value !== '-' && Str::lower($value) !== 'null') {
                return $value;
            }
        }

        return null;
    }

    protected function joinFilled(array $values): ?string
    {
        $line = trim(implode(' ', array_filter(array_map(
            fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $values,
        ), fn (string $value): bool => $value !== '' && $value !== '-')));

        return $line !== '' ? $line : null;
    }

    protected function realEmail(?string $email): ?string
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $normalized = Str::lower($email);

        if (Str::contains($normalized, ['@example.invalid', 'marketplace-', 'invalid'])) {
            return null;
        }

        return $email;
    }

    protected function looksLikeCompany(?string $name): bool
    {
        if (! $name) {
            return false;
        }

        return Str::contains(Str::upper($name), [' SP. ', ' SPÓŁKA', ' S.A.', ' SA', ' SRL', ' S.R.L.', ' LTD', ' GMBH', ' SAS', ' SARL', ' BV', ' LLC', ' INC', ' COMPANY', ' CO.', ' AG']);
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
