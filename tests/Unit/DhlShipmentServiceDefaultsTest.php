<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\Shipments\DhlShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DhlShipmentServiceDefaultsTest extends TestCase
{
    use RefreshDatabase;
    public function test_defaults_use_current_sender_configuration(): void
    {
        config()->set('services.shipments.sender', [
            'name' => 'GREGOR SWISS',
            'address' => 'Milanowska 137',
            'postal_code' => '08-460',
            'city' => 'Sobolew',
            'country' => 'PL',
            'contact_name' => 'GRZEGORZ PACIOREK',
            'phone' => '579 152 665',
            'email' => 'gregor1142@gmail.com',
        ]);

        $defaults = app(DhlShipmentService::class)->defaults();

        $this->assertSame('GREGOR SWISS', $defaults['shipper']['name']);
        $this->assertSame('PL', $defaults['shipper']['country']);
        $this->assertSame('08-460', $defaults['shipper']['postal_code']);
        $this->assertSame('Sobolew', $defaults['shipper']['city']);
        $this->assertSame('Milanowska', $defaults['shipper']['street']);
        $this->assertSame('137', $defaults['shipper']['house_number']);
        $this->assertSame('', $defaults['shipper']['apartment_number']);
        $this->assertSame('GRZEGORZ PACIOREK', $defaults['shipper']['person_name']);
        $this->assertSame('579 152 665', $defaults['shipper']['phone']);
        $this->assertSame('gregor1142@gmail.com', $defaults['shipper']['email']);
    }
    public function test_payload_sends_service_value_only_for_selected_insurance_and_cod(): void
    {
        $service = app(DhlShipmentService::class);
        $form = $service->defaults();
        $form['special_services']['insurance'] = true;
        $form['special_services']['insurance_value'] = '123,45';
        $form['special_services']['cod'] = true;
        $form['special_services']['cod_value'] = '67.89';
        $form['special_services']['pdi'] = true;

        $payload = $service->payload($form);

        $this->assertSame([
            ['serviceType' => 'UBEZP', 'serviceValue' => 123.45],
            ['serviceType' => 'COD', 'serviceValue' => 67.89, 'collectOnDeliveryForm' => 'BANK_TRANSFER'],
            ['serviceType' => 'PDI'],
        ], $payload['shipment']['shipmentInfo']['specialServices']['item']);
    }

    public function test_payload_omits_unselected_insurance_and_cod_values(): void
    {
        $service = app(DhlShipmentService::class);
        $form = $service->defaults();
        $form['special_services']['insurance_value'] = '123,45';
        $form['special_services']['cod_value'] = '67.89';
        $form['special_services']['pod'] = true;

        $payload = $service->payload($form);

        $this->assertSame([
            ['serviceType' => 'POD'],
        ], $payload['shipment']['shipmentInfo']['specialServices']['item']);
    }

    public function test_ebay_defaults_use_shipping_address_instead_of_buyer_marketplace_contact(): void
    {
        $order = new Order([
            'marketplace' => 'ebay',
            'customer_name' => 'infn3202-uamtdrxai',
            'email' => 'marketplace-abc@example.invalid',
            'phone' => '-',
            'address_line1' => '-',
            'postal_code' => '-',
            'city' => '-',
            'country' => 'PL',
            'raw_payload' => [
                'buyer' => [
                    'username' => 'infn3202-uamtdrxai',
                    'email' => 'marketplace-abc@example.invalid',
                ],
                'fulfillmentStartInstructions' => [[
                    'shippingStep' => [
                        'shipTo' => [
                            'fullName' => 'STELLA SRL',
                            'primaryPhone' => ['phoneNumber' => '3487617910'],
                            'contactAddress' => [
                                'addressLine1' => 'Via Bagnatica 21',
                                'city' => 'Brusaporto',
                                'stateOrProvince' => 'BG',
                                'postalCode' => '24060',
                                'countryCode' => 'IT',
                            ],
                        ],
                    ],
                ]],
            ],
        ]);

        $defaults = app(DhlShipmentService::class)->defaults($order);

        $this->assertSame('private', $defaults['receiver']['receiver_type']);
        $this->assertSame('STELLA SRL', $defaults['receiver']['name']);
        $this->assertSame('Via Bagnatica', $defaults['receiver']['street']);
        $this->assertSame('21', $defaults['receiver']['house_number']);
        $this->assertSame('24060', $defaults['receiver']['postal_code']);
        $this->assertSame('Brusaporto', $defaults['receiver']['city']);
        $this->assertSame('IT', $defaults['receiver']['country']);
        $this->assertSame('3487617910', $defaults['receiver']['phone']);
        $this->assertSame('STELLA SRL', $defaults['receiver']['person_name']);
        $this->assertSame('EK', $defaults['service']['service_type']);
        $this->assertNull($defaults['receiver']['email']);

        $payload = app(DhlShipmentService::class)->payload($defaults);

        $this->assertSame('C', $payload['shipment']['ship']['receiver']['address']['addressType']);
        $this->assertSame('EK', $payload['shipment']['shipmentInfo']['serviceType']);
    }

    public function test_payload_uses_configured_international_service_for_non_polish_receiver(): void
    {
        config()->set('services.dhl.default_service', 'AH');
        config()->set('services.dhl.default_international_service', 'PI');

        $service = app(DhlShipmentService::class);
        $form = $service->defaults();
        $form['receiver']['country'] = 'IT';
        $form['service']['service_type'] = 'AH';

        $payload = $service->payload($form);
        $diagnostics = $service->serviceSelectionDiagnostics($form, 'AH');

        $this->assertSame('PI', $payload['shipment']['shipmentInfo']['serviceType']);
        $this->assertFalse($diagnostics['is_domestic']);
        $this->assertSame('PI', $diagnostics['selected_service_type']);
        $this->assertTrue($diagnostics['service_type_changed_for_country']);
        $this->assertSame([], $diagnostics['blocking_reasons']);
    }

    public function test_payload_blocks_domestic_service_for_international_receiver(): void
    {
        config()->set('services.dhl.default_international_service', 'AH');

        $service = app(DhlShipmentService::class);
        $form = $service->defaults();
        $form['receiver']['country'] = 'IT';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DHL serviceType AH is domestic and cannot be used for receiver country IT. Configure DHL24_DEFAULT_INTERNATIONAL_SERVICE_TYPE.');

        $service->payload($form);
    }

    public function test_payload_blocks_missing_receiver_country(): void
    {
        $service = app(DhlShipmentService::class);
        $form = $service->defaults();
        $form['receiver']['country'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DHL receiver country is missing');

        $service->payload($form);
    }
    public function test_parser_accepts_nested_create_shipment_result_label_content(): void
    {
        $parsed = app(DhlShipmentService::class)->parseCreateShipmentResponse([
            'createShipmentResult' => [
                'shipmentNotificationNumber' => 'fallback',
                'shipmentTrackingNumber' => '31294120912',
                'packagesTrackingNumbers' => 'JJD000030249582000000000373',
                'label' => [
                    'labelType' => 'LBLP',
                    'labelFormat' => 'application/pdf',
                    'labelContent' => base64_encode('%PDF-1.4 test'),
                ],
            ],
        ]);

        $this->assertSame('31294120912', $parsed['tracking_number']);
        $this->assertSame('JJD000030249582000000000373', $parsed['package_tracking_number']);
        $this->assertSame('application/pdf', $parsed['label_format']);
        $this->assertSame('LBLP', $parsed['label_type']);
        $this->assertTrue($parsed['has_label_content']);

        $arrayParsed = app(DhlShipmentService::class)->parseCreateShipmentResponse([
            'createShipmentResult' => [[
                'shipmentTrackingNumber' => '31294120912',
                'label' => ['labelContent' => 'base64'],
            ]],
        ]);

        $this->assertSame('31294120912', $arrayParsed['tracking_number']);
        $this->assertSame('base64', $arrayParsed['label_content']);
    }

    public function test_create_does_not_mark_label_created_when_pdf_save_fails(): void
    {
        config()->set('services.dhl.endpoint', '');

        $service = new class extends DhlShipmentService {
            protected function callCreateShipment(array $payload): array
            {
                return [
                    'createShipmentResult' => [
                        'shipmentTrackingNumber' => '31294120912',
                        'packagesTrackingNumbers' => 'JJD000030249582000000000373',
                        'label' => [
                            'labelFormat' => 'application/pdf',
                            'labelContent' => base64_encode('%PDF-1.4 test'),
                        ],
                    ],
                ];
            }

            protected function writeDhlLabelFile(string $path, string $labelBinary): bool
            {
                return false;
            }
        };

        $form = $service->defaults();
        $form['order_id'] = null;
        $form['receiver']['name'] = 'Jan Test';
        $form['receiver']['country'] = 'PL';
        $form['receiver']['postal_code'] = '00-001';
        $form['receiver']['city'] = 'Warszawa';
        $form['receiver']['street'] = 'Testowa';
        $form['receiver']['house_number'] = '1';

        try {
            $service->create($form);
            $this->fail('Expected failed local PDF save to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local label PDF was not saved', $exception->getMessage());
        }

        $shipment = \App\Models\Shipment::query()->where('tracking_number', '31294120912')->first();
        $this->assertNotNull($shipment);
        $this->assertSame('remote_created_label_missing', $shipment->shipment_status);
        $this->assertNull($shipment->label_path);
        $this->assertSame('31294120912', $shipment->carrier_shipment_id);
    }

}

