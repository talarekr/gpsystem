<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\OrderCustomerDisplay;
use Tests\TestCase;

class OrderCustomerDisplayTest extends TestCase
{
    public function test_allegro_uses_buyer_first_last_and_delivery_phone_without_email_or_login(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'customer_name' => 'allegro-login',
            'email' => 'buyer@example.test',
            'phone' => '-',
            'raw_payload' => [
                'buyer' => ['login' => 'allegro-login', 'firstName' => 'Jan', 'lastName' => 'Kowalski', 'email' => 'buyer@example.test'],
                'delivery' => ['address' => ['phoneNumber' => '+48123123123', 'street' => 'Testowa 1']],
            ],
        ]);

        $display = OrderCustomerDisplay::forOrder($order);

        $this->assertSame('Jan Kowalski', $display['name']);
        $this->assertSame('+48123123123', $display['phone']);
    }

    public function test_ovoko_uses_client_name_and_client_phone(): void
    {
        $order = new Order([
            'marketplace' => 'ovoko',
            'customer_name' => 'Fallback Buyer',
            'email' => 'client@example.test',
            'phone' => '+48000000000',
            'raw_payload' => ['client_name' => 'Buyer One', 'client_phone' => '+48555111222', 'client_email' => 'client@example.test'],
        ]);

        $display = OrderCustomerDisplay::forOrder($order);

        $this->assertSame('Buyer One', $display['name']);
        $this->assertSame('+48555111222', $display['phone']);
    }

    public function test_ebay_keeps_existing_fallback_behavior(): void
    {
        $order = new Order([
            'marketplace' => 'ebay',
            'customer_name' => '',
            'company_name' => 'Company Ltd',
            'email' => 'ebay@example.test',
            'phone' => '-',
        ]);

        $display = OrderCustomerDisplay::forOrder($order);

        $this->assertSame('Company Ltd', $display['name']);
        $this->assertSame('-', $display['phone']);
    }
}
