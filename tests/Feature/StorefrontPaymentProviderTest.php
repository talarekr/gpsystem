<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Part;
use App\Services\Marketplace\PartAvailabilityEventService;
use App\Services\Payments\PaymentProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontPaymentProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false]);
    }

    #[DataProvider('providerHosts')]
    public function test_provider_is_chosen_from_host_and_ignores_client_input(string $host, string $expected): void
    {
        $request = Request::create('https://'.$host.'/zamowienie?payment_provider=forged', 'POST', [
            'payment_provider' => $expected === 'payu' ? 'stripe' : 'payu',
            'storefront_code' => $expected === 'payu' ? 'gpswiss_fr' : 'gpswiss_pl',
        ]);

        $this->assertSame($expected, app(PaymentProviderResolver::class)->resolve($request));
    }

    public static function providerHosts(): array
    {
        return [
            'PL' => ['gpswiss.pl', 'payu'],
            'PL www' => ['www.gpswiss.pl', 'payu'],
            'FR' => ['gpswiss.fr', 'stripe'],
            'FR www' => ['www.gpswiss.fr', 'stripe'],
            'FR upper case and DNS trailing dot' => ['GPSWISS.FR.', 'stripe'],
            'legacy preview' => ['preview.example.test', 'payu'],
            'similar foreign host' => ['gpswiss.fr.example.test', 'payu'],
        ];
    }

    #[DataProvider('polishMethods')]
    public function test_polish_checkout_still_uses_payu_with_stripe_disabled_and_forged_provider(string $host, string $method): void
    {
        config(['stripe_fr.enabled' => false]);
        $part = $this->cartPart();
        $originalPart = $part->getRawOriginal();
        $this->expectOneExistingSoldEvent($part);
        $this->fakePayu();

        $this->withSession($this->cartSession($part))
            ->post('https://'.$host.'/zamowienie', $this->checkoutInput([
                'payment_method' => $method,
                'payment_provider' => 'stripe',
                'storefront_code' => 'gpswiss_fr',
            ]))
            ->assertRedirect('https://payu.example.test/checkout');

        $order = Order::query()->sole();
        $this->assertSame('payu', $order->marketplace);
        $this->assertSame('PAYU-TEST-1', $order->marketplace_order_id);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame($method, data_get($order->meta, 'payment_method'));
        $this->assertSame('PAYU-TEST-1', data_get($order->meta, 'payu.order_id'));
        $this->assertNull(data_get($order->meta, 'stripe'));
        $this->assertSame($originalPart, $part->fresh()->getRawOriginal());
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://secure.snd.payu.com/api/v2_1/orders'
            && $request['currencyCode'] === 'PLN'
            && (int) $request['totalAmount'] === 1234);
    }

    public static function polishMethods(): array
    {
        return [
            'PL PayU' => ['gpswiss.pl', 'payu'],
            'PL BLIK' => ['gpswiss.pl', 'blik'],
            'PL www PayU' => ['www.gpswiss.pl', 'payu'],
        ];
    }

    public function test_polish_checkout_rejects_stripe_payment_method_before_order_or_sold(): void
    {
        $this->enableStripe();
        $part = $this->cartPart();
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');

        $this->withSession($this->cartSession($part))
            ->post('https://gpswiss.pl/zamowienie', $this->checkoutInput([
                'payment_method' => 'stripe',
                'payment_provider' => 'stripe',
            ]))
            ->assertSessionHasErrors('payment_method');

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('frenchMethods')]
    public function test_french_checkout_forces_stripe_even_when_client_requests_payu(string $host, string $method): void
    {
        $this->enableStripe();
        $part = $this->cartPart();
        $originalPart = $part->getRawOriginal();
        $this->expectOneExistingSoldEvent($part);
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => fn (ClientRequest $request) => Http::response([
            'id' => 'cs_test_storefront',
            'object' => 'checkout.session',
            'mode' => 'payment',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_storefront',
            'livemode' => false,
            'payment_intent' => null,
            'amount_total' => 1234,
            'currency' => 'pln',
            'metadata' => $request['metadata'],
            'client_reference_id' => $request['client_reference_id'],
        ])]);

        $this->withSession($this->cartSession($part))
            ->post('https://'.$host.'/zamowienie', $this->checkoutInput([
                'payment_method' => $method,
                'payment_provider' => 'payu',
                'storefront_code' => 'gpswiss_pl',
            ]))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_storefront');

        $order = Order::query()->sole();
        $this->assertSame('stripe', data_get($order->meta, 'payment_provider'));
        $this->assertSame('gpswiss_fr', data_get($order->meta, 'storefront_code'));
        $this->assertSame('stripe', data_get($order->meta, 'payment_method'));
        $this->assertSame('cs_test_storefront', data_get($order->meta, 'stripe.session_id'));
        $this->assertNull(data_get($order->meta, 'payu'));
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame($originalPart, $part->fresh()->getRawOriginal());
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && (string) data_get($request->data(), 'metadata.order_id') === (string) $order->id
            && data_get($request->data(), 'metadata.payment_provider') === 'stripe'
            && data_get($request->data(), 'metadata.storefront_code') === 'gpswiss_fr');
    }

    public static function frenchMethods(): array
    {
        return [
            'FR Stripe' => ['gpswiss.fr', 'stripe'],
            'FR forged PayU' => ['gpswiss.fr', 'payu'],
            'FR www forged BLIK' => ['www.gpswiss.fr', 'blik'],
        ];
    }

    public function test_remote_stripe_failure_keeps_recorded_order_and_shows_safe_private_receipt(): void
    {
        $this->enableStripe();
        $part = $this->cartPart();
        $this->expectOneExistingSoldEvent($part);
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([
            'error' => ['message' => 'Provider diagnostic containing sk_test_sensitive_response'],
        ], 503)]);

        $response = $this->withSession($this->cartSession($part))
            ->post('https://gpswiss.fr/zamowienie', $this->checkoutInput(['payment_method' => 'stripe']))
            ->assertSessionHas('error')
            ->assertSessionMissing('storefront.cart.items');

        $order = Order::query()->sole();
        $token = data_get($order->meta, 'stripe.return_token');
        $response->assertRedirect('https://gpswiss.fr/stripe/fr/cancel/'.$order->id.'?token='.$token);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull(data_get($order->meta, 'stripe.session_id'));
        $this->assertStringNotContainsString('sk_test_sensitive_response', session('error'));
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Le paiement est temporairement indisponible.')
            ->assertDontSee('sk_test_sensitive_response');
        Http::assertSentCount(1);
    }

    #[DataProvider('unavailableStripeSettings')]
    public function test_unavailable_stripe_blocks_french_finalization_before_order_and_sold(array $settings): void
    {
        $this->enableStripe();
        config($settings);
        $part = $this->cartPart();
        $originalPart = $part->getRawOriginal();
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');

        $this->withSession($this->cartSession($part))
            ->post('https://gpswiss.fr/zamowienie', $this->checkoutInput(['payment_method' => 'stripe']))
            ->assertSessionHasErrors('payment_method')
            ->assertSessionHas('storefront.cart.items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame($originalPart, $part->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function unavailableStripeSettings(): array
    {
        return [
            'disabled' => [['stripe_fr.enabled' => false]],
            'missing secret' => [['stripe_fr.secret_key' => '']],
            'missing webhook secret' => [['stripe_fr.webhook_secret' => '']],
            'live mode' => [['stripe_fr.mode' => 'live']],
            'live secret' => [['stripe_fr.secret_key' => 'sk_live_not_allowed']],
        ];
    }

    public function test_disabled_french_checkout_displays_safe_notice_while_polish_form_remains_available(): void
    {
        config(['stripe_fr.enabled' => false]);
        $part = $this->cartPart();

        $this->withSession($this->cartSession($part))
            ->get('https://gpswiss.fr/zamowienie')
            ->assertOk()
            ->assertSee('Le paiement est temporairement indisponible.')
            ->assertSee('name="payment_method" value="stripe"', false)
            ->assertDontSee('name="payment_method" value="payu"', false)
            ->assertSee('type="submit" disabled', false);

        $this->get('https://gpswiss.pl/zamowienie')
            ->assertOk()
            ->assertSee('name="payment_method" value="payu"', false)
            ->assertSee('name="payment_method" value="blik"', false)
            ->assertDontSee('name="payment_method" value="stripe"', false)
            ->assertDontSee('type="submit" disabled', false);

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_thank_you_url_cannot_bypass_private_stripe_receipt_token(): void
    {
        $order = $this->stripeOrder();
        $original = $order->getRawOriginal();

        foreach (['gpswiss.fr', 'gpswiss.pl'] as $host) {
            $this->get('https://'.$host.'/zamowienie/dziekujemy/'.$order->id)->assertNotFound();
        }

        $this->assertSame($original, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    #[DataProvider('returnRoutes')]
    public function test_stripe_browser_return_only_reads_status_and_discloses_no_customer_data(string $path): void
    {
        $order = $this->stripeOrder();
        $original = $order->getRawOriginal();
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');

        $this->get('https://gpswiss.fr/stripe/fr/'.$path.'/'.$order->id.'?token='.str_repeat('r', 64).'&payment_status=paid')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertDontSee($order->email)
            ->assertDontSee($order->customer_name)
            ->assertDontSee($order->address_line1);

        $this->assertSame($original, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function returnRoutes(): array
    {
        return ['success' => ['success'], 'cancel' => ['cancel']];
    }

    #[DataProvider('invalidReturns')]
    public function test_stripe_return_requires_fr_host_and_matching_private_token(string $host, string $token): void
    {
        $order = $this->stripeOrder();
        $original = $order->getRawOriginal();

        $this->get('https://'.$host.'/stripe/fr/success/'.$order->id.'?token='.$token)
            ->assertNotFound();

        $this->assertSame($original, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function invalidReturns(): array
    {
        return [
            'PL' => ['gpswiss.pl', str_repeat('r', 64)],
            'unknown host' => ['preview.example.test', str_repeat('r', 64)],
            'wrong token' => ['gpswiss.fr', 'wrong-token'],
            'missing token' => ['gpswiss.fr', ''],
        ];
    }

    public function test_signed_legacy_payu_notification_still_marks_polish_order_paid(): void
    {
        config(['payu.second_key' => 'payu-notification-test-key', 'stripe_fr.enabled' => false]);
        $order = $this->stripeOrder();
        $order->update([
            'marketplace' => 'payu',
            'marketplace_order_id' => 'PAYU-NOTIFY-1',
            'meta' => ['source' => 'storefront', 'payment_method' => 'payu', 'payu' => [
                'order_id' => 'PAYU-NOTIFY-1',
                'ext_order_id' => 'GPS-PAYU-'.$order->id,
            ]],
        ]);
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');
        $body = json_encode(['order' => [
            'orderId' => 'PAYU-NOTIFY-1',
            'extOrderId' => 'GPS-PAYU-'.$order->id,
            'status' => 'COMPLETED',
        ]], JSON_THROW_ON_ERROR);

        $this->call('POST', 'https://gpswiss.pl/payu/notify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_OPENPAYU_SIGNATURE' => 'signature='.md5($body.'payu-notification-test-key').';algorithm=MD5',
        ], $body)->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertSame('COMPLETED', $order->marketplace_status);
        $this->assertSame('COMPLETED', data_get($order->meta, 'payu.last_notification.status'));
        $this->assertNull(data_get($order->meta, 'stripe'));
        Http::assertNothingSent();
    }

    private function enableStripe(): void
    {
        config([
            'stripe_fr.enabled' => true,
            'stripe_fr.mode' => 'test',
            'stripe_fr.secret_key' => 'sk_test_storefront_test',
            'stripe_fr.publishable_key' => 'pk_test_storefront_test',
            'stripe_fr.webhook_secret' => 'whsec_storefront_test',
        ]);
    }

    private function fakePayu(): void
    {
        config([
            'payu.env' => 'sandbox',
            'payu.client_id' => 'test-client',
            'payu.client_secret' => 'test-secret',
            'payu.merchant_pos_id' => '12345',
            'payu.currency' => 'PLN',
            'payu.notify_url' => 'https://gpswiss.pl/payu/notify',
            'payu.continue_url' => 'https://gpswiss.pl/zamowienie/payu/powrot',
        ]);
        Http::fake([
            'https://secure.snd.payu.com/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'test-access', 'expires_in' => 300]),
            'https://secure.snd.payu.com/api/v2_1/orders' => Http::response([
                'orderId' => 'PAYU-TEST-1',
                'redirectUri' => 'https://payu.example.test/checkout',
                'status' => ['statusCode' => 'SUCCESS'],
            ], 201),
        ]);
    }

    private function cartPart(): Part
    {
        return Part::query()->create([
            'name' => 'Testowa część płatności',
            'slug' => 'testowa-czesc-platnosci',
            'status' => 'ready',
            'price' => '12.34',
            'currency' => 'PLN',
            'quantity' => 1,
            'is_visible_storefront' => true,
            'needs_listing' => false,
            'needs_review' => false,
        ])->refresh();
    }

    private function cartSession(Part $part): array
    {
        return ['storefront.cart.items' => [(string) $part->id => [
            'part_id' => $part->id,
            'quantity' => 1,
            'unit_price' => '12.34',
            'currency' => 'PLN',
            'name' => $part->name,
            'slug' => $part->slug,
        ]]];
    }

    private function expectOneExistingSoldEvent(Part $part): void
    {
        $this->mock(PartAvailabilityEventService::class)->shouldReceive('sold')->once()
            ->withArgs(fn (array $event): bool => $event['source_channel'] === 'storefront'
                && (int) $event['part_id'] === $part->id
                && isset($event['source_order_id'], $event['source_order_item_id']))
            ->andReturn(['ok' => true]);
    }

    private function checkoutInput(array $overrides = []): array
    {
        return array_replace([
            'customer_type' => 'private',
            'billing_first_name' => 'Jean',
            'billing_last_name' => 'Test',
            'billing_street' => 'Rue du Test',
            'billing_building_number' => '1',
            'billing_postal_code' => '75001',
            'billing_city' => 'Paris',
            'billing_phone' => '0102030405',
            'billing_email' => 'jean@example.test',
            'shipping_same_as_billing' => '1',
            'shipping_method' => 'courier',
            'payment_method' => 'payu',
            'terms' => '1',
        ], $overrides);
    }

    private function stripeOrder(): Order
    {
        return Order::query()->create([
            'order_number' => 'GPS-RETURN-TEST',
            'status' => 'new',
            'currency' => 'PLN',
            'subtotal' => '12.34',
            'shipping_total' => 0,
            'total' => '12.34',
            'payment_status' => 'pending',
            'customer_name' => 'Private Customer Name',
            'email' => 'private-customer@example.test',
            'phone' => '0102030405',
            'address_line1' => 'Private Customer Street 123',
            'postal_code' => '75001',
            'city' => 'Paris',
            'meta' => [
                'source' => 'storefront',
                'payment_provider' => 'stripe',
                'storefront_code' => 'gpswiss_fr',
                'stripe' => ['return_token' => str_repeat('r', 64), 'session_id' => 'cs_test_return'],
            ],
        ])->refresh();
    }
}
