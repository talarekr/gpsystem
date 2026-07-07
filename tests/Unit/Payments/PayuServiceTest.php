<?php

namespace Tests\Unit\Payments;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Payments\PayuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayuServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_md5_openpayu_signature(): void
    {
        config(['payu.second_key' => 'second']);
        $body = '{"order":{"status":"COMPLETED"}}';
        $signature = 'signature='.md5($body.'second').';algorithm=MD5';

        $this->assertTrue(app(PayuService::class)->verifySignature($body, $signature));
        $this->assertFalse(app(PayuService::class)->verifySignature($body, 'signature=bad;algorithm=MD5'));
    }

    public function test_it_builds_checkout_order_payload_in_minor_units(): void
    {
        config([
            'payu.merchant_pos_id' => '123456',
            'payu.currency' => 'PLN',
            'payu.notify_url' => 'https://gpswiss.pl/payu/notify',
            'payu.continue_url' => 'https://gpswiss.pl/zamowienie/payu/powrot',
        ]);

        $order = Order::query()->create([
            'order_number' => 'GPS-2026-000001',
            'status' => 'new',
            'currency' => 'PLN',
            'subtotal' => 12.34,
            'shipping_total' => 0,
            'total' => 12.34,
            'customer_name' => 'Jan Kowalski',
            'email' => 'jan@example.com',
            'phone' => '500600700',
            'address_line1' => 'Test 1',
            'postal_code' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_name' => 'Część GPS',
            'unit_price' => 12.34,
            'quantity' => 1,
            'line_total' => 12.34,
        ]);

        $payload = app(PayuService::class)->orderPayload($order->fresh('items'), '1.2.3.4');

        $this->assertSame('1234', (string) $payload['totalAmount']);
        $this->assertSame('1234', (string) $payload['products'][0]['unitPrice']);
        $this->assertSame('GPS-2026-000001-'.$order->id, $payload['extOrderId']);
        $this->assertSame('Jan', $payload['buyer']['firstName']);
    }
}
