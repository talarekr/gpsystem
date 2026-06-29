<?php

namespace Tests\Unit;

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
            'email' => null,
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
        $this->assertNull($defaults['shipper']['email']);
    }
}
