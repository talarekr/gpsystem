<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\OrderShippingAddressDisplayResolver;
use Tests\TestCase;

class OrderShippingAddressDisplayResolverTest extends TestCase
{
    public function test_ebay_address_uses_ship_to_contact_address_without_buyer_id(): void
    {
        $order = new Order([
            'marketplace' => 'ebay',
            'customer_name' => 'infn3202-uamtdrxai',
            'phone' => '-',
            'address_line1' => '-',
            'postal_code' => '-',
            'city' => '-',
            'country' => 'PL',
            'raw_payload' => [
                'buyer' => ['username' => 'infn3202-uamtdrxai'],
                'fulfillmentStartInstructions' => [[
                    'shippingStep' => [
                        'shipTo' => [
                            'fullName' => 'John Smith',
                            'primaryPhone' => ['phoneNumber' => '+49123456789'],
                            'contactAddress' => [
                                'addressLine1' => 'Example Street 12',
                                'city' => 'Berlin',
                                'postalCode' => '12-345',
                                'countryCode' => 'DE',
                            ],
                        ],
                    ],
                ]],
            ],
        ]);

        $this->assertSame([
            'John Smith',
            'Example Street 12',
            '12-345 Berlin DE',
            '+49123456789',
        ], app(OrderShippingAddressDisplayResolver::class)->resolve($order));
    }

    public function test_ebay_address_shows_missing_message_instead_of_login_when_payload_has_no_address(): void
    {
        $order = new Order([
            'marketplace' => 'ebay',
            'customer_name' => 'infn3202-uamtdrxai',
            'raw_payload' => ['buyer' => ['username' => 'infn3202-uamtdrxai']],
        ]);

        $this->assertSame(['Brak danych adresowych'], app(OrderShippingAddressDisplayResolver::class)->resolve($order));
    }
}
