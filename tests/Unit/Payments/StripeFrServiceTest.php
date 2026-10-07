<?php

namespace Tests\Unit\Payments;

use App\Models\Order;
use App\Services\Payments\StripeFrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StripeFrServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_stripe_is_disabled_and_test_mode_is_the_default(): void
    {
        $this->assertFalse(config('stripe_fr.enabled'));
        $this->assertSame('test', config('stripe_fr.mode'));
        $this->assertFalse(app(StripeFrService::class)->isAvailable());
        Http::assertNothingSent();
    }

    #[DataProvider('configurationCases')]
    public function test_availability_requires_explicit_enabled_test_configuration(array $overrides, bool $available): void
    {
        $this->configureStripe($overrides);

        $this->assertSame($available, app(StripeFrService::class)->isAvailable());
        Http::assertNothingSent();
    }

    public static function configurationCases(): array
    {
        return [
            'valid test configuration' => [[], true],
            'disabled' => [['enabled' => false], false],
            'live mode' => [['mode' => 'live'], false],
            'unknown mode' => [['mode' => 'sandbox'], false],
            'secret absent' => [['secret_key' => ''], false],
            'publishable key absent' => [['publishable_key' => ''], false],
            'webhook secret absent' => [['webhook_secret' => ''], false],
            'secret prefix alone' => [['secret_key' => 'sk_test_'], false],
            'publishable key prefix alone' => [['publishable_key' => 'pk_test_'], false],
            'webhook prefix alone' => [['webhook_secret' => 'whsec_'], false],
            'live secret' => [['secret_key' => 'sk_live_forbidden'], false],
            'live publishable key' => [['publishable_key' => 'pk_live_forbidden'], false],
            'invalid secret' => [['secret_key' => 'not-a-stripe-secret'], false],
            'invalid publishable key' => [['publishable_key' => 'not-a-stripe-public-key'], false],
            'invalid webhook secret' => [['webhook_secret' => 'invalid-webhook-secret'], false],
        ];
    }

    #[DataProvider('currencies')]
    public function test_checkout_session_uses_order_total_currency_and_fr_metadata(string $currency): void
    {
        $this->configureStripe();
        $order = $this->order(['currency' => $currency]);
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_service',
            'object' => 'checkout.session',
            'mode' => 'payment',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_service',
            'livemode' => false,
            'payment_intent' => 'pi_service',
            'amount_total' => 1234,
            'currency' => strtolower($currency),
            'client_reference_id' => (string) $order->id,
            'metadata' => $this->metadata($order),
        ])]);

        $result = app(StripeFrService::class)->createCheckoutSession($order);

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_service', $result['url']);
        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('cs_test_service', data_get($order->meta, 'stripe.session_id'));
        $this->assertSame('pi_service', data_get($order->meta, 'stripe.payment_intent_id'));
        $this->assertSame(1234, data_get($order->meta, 'stripe.amount_minor'));
        $this->assertSame(strtolower($currency), data_get($order->meta, 'stripe.currency'));
        $this->assertSame('test', data_get($order->meta, 'stripe.environment'));
        $this->assertFalse(data_get($order->meta, 'stripe.livemode'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', data_get($order->meta, 'stripe.return_token'));
        $serialized = json_encode($order->meta, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sk_test_service_secret', $serialized);
        $this->assertStringNotContainsString('whsec_service_secret', $serialized);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($order, $currency): bool {
            $payload = $request->data();
            $metadata = [
                'order_id' => (string) $order->id,
                'storefront_code' => 'gpswiss_fr',
                'payment_provider' => 'stripe',
                'environment' => 'test',
            ];
            $token = data_get($order->meta, 'stripe.return_token');

            return $request->method() === 'POST'
                && $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->hasHeader('Authorization', 'Bearer sk_test_service_secret')
                && data_get($payload, 'mode') === 'payment'
                && data_get($payload, 'locale') === 'fr'
                && data_get($payload, 'payment_method_types') === ['card']
                && (int) data_get($payload, 'line_items.0.price_data.unit_amount') === 1234
                && data_get($payload, 'line_items.0.price_data.currency') === strtolower($currency)
                && (int) data_get($payload, 'line_items.0.quantity') === 1
                && data_get($payload, 'metadata') == $metadata
                && data_get($payload, 'payment_intent_data.metadata') == $metadata
                && (string) data_get($payload, 'client_reference_id') === (string) $order->id
                && data_get($payload, 'success_url') === 'https://gpswiss.fr/stripe/fr/success/'.$order->id.'?token='.$token
                && data_get($payload, 'cancel_url') === 'https://gpswiss.fr/stripe/fr/cancel/'.$order->id.'?token='.$token;
        });
    }

    public static function currencies(): array
    {
        return ['EUR' => ['EUR'], 'existing catalog PLN' => ['PLN']];
    }

    public function test_timeout_retry_preserves_receipt_token_and_idempotency_key_then_reuses_saved_session(): void
    {
        $this->configureStripe();
        $order = $this->order();
        $session = $this->stripeSession($order);
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions' => Http::sequence()
                ->push(['error' => ['message' => 'Temporary failure']], 503)
                ->push($session),
            'https://api.stripe.com/v1/checkout/sessions/cs_test_service' => Http::response($session),
        ]);

        try {
            app(StripeFrService::class)->createCheckoutSession($order);
            $this->fail('A temporary provider failure must reject checkout creation.');
        } catch (RuntimeException) {
            $token = data_get($order->fresh()->meta, 'stripe.return_token');
            $this->assertIsString($token);
        }

        $service = app(StripeFrService::class);
        $this->assertSame($session['url'], $service->createCheckoutSession($order)['url']);
        $this->assertSame($session['url'], $service->createCheckoutSession($order)['url']);
        $this->assertSame($token, data_get($order->fresh()->meta, 'stripe.return_token'));
        $requests = Http::recorded()->pluck(0);
        $this->assertSame(['POST', 'POST', 'GET'], $requests->map(fn (Request $request): string => $request->method())->all());
        $this->assertNotEmpty($requests[0]->header('Idempotency-Key'));
        $this->assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
        $this->assertSame($requests[0]->data(), $requests[1]->data());
        Http::assertSentCount(3);
    }

    public function test_session_response_does_not_overwrite_payment_update_received_during_http_request(): void
    {
        $this->configureStripe();
        $order = $this->order();
        $meta = $order->meta;
        data_set($meta, 'stripe', [
            'session_id' => 'cs_test_service',
            'payment_intent_id' => 'pi_service',
            'environment' => 'test',
            'test' => true,
            'livemode' => false,
            'amount_minor' => 1234,
            'currency' => 'eur',
            'return_token' => str_repeat('r', 64),
            'event_ids' => [],
        ]);
        $order->update(['meta' => $meta]);
        Http::fake(['https://api.stripe.com/v1/checkout/sessions/cs_test_service' => function () use ($order) {
            // Simulate the webhook committing while Stripe's HTTP response is in flight.
            $updated = $order->fresh();
            $meta = $updated->meta;
            data_set($meta, 'stripe.event_ids', ['evt_arrived_during_request']);
            $updated->update(['meta' => $meta, 'payment_status' => 'paid', 'status' => 'processing']);

            return Http::response($this->stripeSession($order));
        }]);

        $this->assertSame($this->stripeSession($order)['url'], app(StripeFrService::class)->createCheckoutSession($order)['url']);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertSame(['evt_arrived_during_request'], data_get($order->meta, 'stripe.event_ids'));
        Http::assertSentCount(1);
    }

    public function test_disabled_service_cannot_create_session_or_call_stripe(): void
    {
        $this->configureStripe(['enabled' => false]);
        $order = $this->order();

        try {
            app(StripeFrService::class)->createCheckoutSession($order);
            $this->fail('Disabled Stripe must reject session creation.');
        } catch (RuntimeException) {
            $this->assertNull(data_get($order->fresh()->meta, 'stripe.session_id'));
            Http::assertNothingSent();
        }
    }

    #[DataProvider('invalidOrderCases')]
    public function test_non_fr_or_invalid_order_cannot_start_stripe_payment(array $overrides): void
    {
        $this->configureStripe();
        $order = $this->order($overrides);

        try {
            app(StripeFrService::class)->createCheckoutSession($order);
            $this->fail('An invalid order must not start Stripe payment.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }
    }

    public static function invalidOrderCases(): array
    {
        return [
            'PL storefront' => [['meta' => ['source' => 'storefront', 'payment_provider' => 'payu', 'storefront_code' => 'gpswiss_pl']]],
            'marketplace order' => [['marketplace' => 'allegro', 'meta' => ['source' => 'allegro']]],
            'zero total' => [['total' => '0.00']],
            'negative total' => [['total' => '-1.00']],
            'unsupported currency' => [['currency' => 'USD']],
        ];
    }

    #[DataProvider('invalidSessionResponses')]
    public function test_unsafe_or_inconsistent_stripe_response_is_rejected_without_paid_status(array $overrides): void
    {
        $this->configureStripe();
        $order = $this->order();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response(array_replace([
            'id' => 'cs_test_service',
            'object' => 'checkout.session',
            'mode' => 'payment',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_service',
            'livemode' => false,
            'payment_intent' => 'pi_service',
            'amount_total' => 1234,
            'currency' => 'eur',
            'client_reference_id' => (string) $order->id,
            'metadata' => $this->metadata($order),
        ], $overrides))]);

        try {
            app(StripeFrService::class)->createCheckoutSession($order);
            $this->fail('Stripe response must be rejected.');
        } catch (RuntimeException) {
            $order->refresh();
            $this->assertSame('pending', $order->payment_status);
            $this->assertNull(data_get($order->meta, 'stripe.session_id'));
            Http::assertSentCount(1);
        }
    }

    public static function invalidSessionResponses(): array
    {
        return [
            'live session' => [['livemode' => true, 'id' => 'cs_live_service']],
            'wrong amount' => [['amount_total' => 1235]],
            'wrong currency' => [['currency' => 'pln']],
            'non Stripe redirect' => [['url' => 'https://malicious.example.test/checkout']],
            'missing URL' => [['url' => null]],
        ];
    }

    private function configureStripe(array $overrides = []): void
    {
        foreach (array_replace([
            'enabled' => true,
            'mode' => 'test',
            'secret_key' => 'sk_test_service_secret',
            'publishable_key' => 'pk_test_service_public',
            'webhook_secret' => 'whsec_service_secret',
        ], $overrides) as $key => $value) {
            config(['stripe_fr.'.$key => $value]);
        }
    }

    private function metadata(Order $order): array
    {
        return [
            'order_id' => (string) $order->id,
            'storefront_code' => 'gpswiss_fr',
            'payment_provider' => 'stripe',
            'environment' => 'test',
        ];
    }

    private function stripeSession(Order $order): array
    {
        return [
            'id' => 'cs_test_service',
            'object' => 'checkout.session',
            'mode' => 'payment',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_service',
            'livemode' => false,
            'payment_intent' => 'pi_service',
            'amount_total' => 1234,
            'currency' => 'eur',
            'client_reference_id' => (string) $order->id,
            'metadata' => $this->metadata($order),
        ];
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_replace([
            'order_number' => 'GPS-STRIPE-SERVICE-TEST',
            'status' => 'new',
            'currency' => 'EUR',
            'subtotal' => '10.00',
            'shipping_total' => '2.34',
            'total' => '12.34',
            'payment_status' => 'pending',
            'customer_name' => 'Jean Test',
            'email' => 'jean@example.test',
            'phone' => '0102030405',
            'address_line1' => 'Rue Test 1',
            'postal_code' => '75001',
            'city' => 'Paris',
            'country' => 'FR',
            'meta' => ['source' => 'storefront', 'payment_provider' => 'stripe', 'storefront_code' => 'gpswiss_fr'],
        ], $overrides));
    }
}
