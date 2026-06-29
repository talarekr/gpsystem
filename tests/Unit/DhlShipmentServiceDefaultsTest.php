<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\Shipments\DhlShipmentService;
use Tests\TestCase;

class DhlShipmentServiceDefaultsTest extends TestCase
{
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

        $this->assertSame('company', $defaults['receiver']['receiver_type']);
        $this->assertSame('STELLA SRL', $defaults['receiver']['name']);
        $this->assertSame('Via Bagnatica', $defaults['receiver']['street']);
        $this->assertSame('21', $defaults['receiver']['house_number']);
        $this->assertSame('24060', $defaults['receiver']['postal_code']);
        $this->assertSame('Brusaporto', $defaults['receiver']['city']);
        $this->assertSame('IT', $defaults['receiver']['country']);
        $this->assertSame('3487617910', $defaults['receiver']['phone']);
        $this->assertSame('STELLA SRL', $defaults['receiver']['person_name']);
        $this->assertNull($defaults['receiver']['email']);
    }
}
