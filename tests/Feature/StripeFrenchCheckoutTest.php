<?php

namespace Tests\Feature;

use App\Mail\FrenchOrderMail;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\PartAvailabilityEventService;
use App\Services\Payments\PayuService;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeFrenchCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->freezeTime();
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A', 'rate' => 5, 'effective_date' => today()->toDateString()], 3600);
        config(['frontend-maintenance.enabled' => false, 'stripe-fr.enabled' => true, 'stripe-fr.mode' => 'test',
            'stripe-fr.secret_key' => 'sk_test_fixture', 'stripe-fr.publishable_key' => 'pk_test_fixture',
            'stripe-fr.webhook_secret' => 'whsec_fixture']);
        $this->mock(PayuService::class)->shouldNotReceive('createOrder', 'getOrder');
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');
        Http::preventStrayRequests();
        $category = PartCategory::create(['name' => 'Moteurs', 'slug' => 'stripe-category']);
        $this->part = Part::create(['name' => 'Silnik', 'sku' => 'STRIPE-FR', 'slug' => 'stripe-fr',
            'price' => 400, 'currency' => 'PLN', 'quantity' => 1, 'status' => 'ready', 'category_id' => $category->id]);
        $this->part->translations()->create(['locale' => 'fr', 'name' => 'Moteur', 'status' => 'reviewed']);
        MarketplaceCategoryMapping::create(['local_category_id' => $category->id, 'channel' => 'ebay_fr',
            'shipping_group' => 'fr_55_eur', 'fulfillment_policy_id' => '260547694013']);
        $this->mockCart();
        $this->withSession(['storefront' => ['fr' => ['address' => $this->address()]]]);
        Http::fake(['api.stripe.com/*' => function ($request) {
            return Http::response([
                'id' => config('stripe-fr.mode') === 'test' ? 'cs_test_fr_fixture' : 'cs_live_fr_fixture',
                'object' => 'checkout.session', 'mode' => 'payment', 'livemode' => config('stripe-fr.mode') === 'live',
                'currency' => 'eur', 'amount_total' => 15500, 'url' => 'https://checkout.stripe.com/c/pay/fixture',
                'client_reference_id' => $request['client_reference_id'], 'metadata' => $request['metadata'],
            ]);
        }]);
    }

    public function test_checkout_persists_french_order_and_repeated_submit_reuses_session(): void
    {
        $key = $this->key();
        $this->submit($key)->assertRedirect('https://checkout.stripe.com/c/pay/fixture');
        $this->submit($key)->assertRedirect('https://checkout.stripe.com/c/pay/fixture');
        $order = Order::query()->sole();
        $this->assertSame('gpswiss_fr', $order->storefrontCode());
        $this->assertSame('fr', $order->meta['locale']);
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('stripe', $order->paymentProvider());
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('Moteur', $order->items()->sole()->product_name);
        $this->assertSame('155.00', $order->total);
        $this->assertSame('FR', $order->country);
        $this->assertSame(1, $this->part->fresh()->quantity);
        Mail::assertSent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'awaiting');
        Mail::assertNotSent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'paid');
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', 'gpswiss-fr-'.$key)
            && $r['line_items'][0]['price_data']['unit_amount'] === 10000
            && $r['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] === 5500);
    }

    public function test_nbp_fallback_uses_the_same_eur_price_on_card_and_stripe_and_records_rate_snapshot(): void
    {
        $this->part->update(['currency' => 'PLN', 'price' => 400]);
        Cache::forget('nbp_table_a_rate:EUR:latest');
        Http::fake(['api.nbp.pl/*' => Http::response(['rates' => [[
            'mid' => 5, 'effectiveDate' => today()->toDateString(), 'no' => '100/A/NBP/2026',
        ]]])]);
        $this->get('https://gpswiss.fr/produkt/'.$this->part->slug)->assertOk()
            ->assertSee('100,00 EUR')->assertDontSee('PLN')->assertDontSee('Prix indisponible</strong>', false)
            ->assertSee('Frais de livraison : 55,00 EUR')->assertDontSee('Livraison en France calculée au panier');
        $this->submit($this->key())->assertRedirect('https://checkout.stripe.com/c/pay/fixture');
        $order = Order::sole();
        $item = $order->items()->sole();
        $this->assertSame('100.00', $item->unit_price);
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('155.00', $order->total);
        $this->assertSame(5, $item->meta['price_conversion']['rate']);
        $this->assertSame('400.00', $item->meta['price_conversion']['source_amount']);
        $this->assertSame(today()->toDateString(), $item->meta['price_conversion']['effective_date']);
        $this->assertSame('parts.price(PLN)*1.25->NBP_TABLE_A(EUR)', $item->meta['price_source']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.stripe.com')
            && $request['line_items'][0]['price_data']['currency'] === 'eur'
            && $request['line_items'][0]['price_data']['unit_amount'] === 10000);
        $this->assertSame('400.00', $this->part->fresh()->price);
        $this->assertSame(1, $this->part->fresh()->quantity);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'api.nbp.pl')));
    }

    public function test_unavailable_nbp_blocks_checkout_before_order_and_stripe(): void
    {
        $this->part->update(['currency' => 'PLN', 'price' => 400]);
        Cache::forget('nbp_table_a_rate:EUR:latest');
        Http::fake(['api.nbp.pl/*' => Http::response([], 503)]);
        $this->submit($this->key())->assertRedirect()->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.stripe.com'));
        $this->assertSame(1, $this->part->fresh()->quantity);
    }

    public function test_disabled_stripe_blocks_orders_before_api(): void
    {
        config(['stripe-fr.enabled' => false]);
        $this->get('https://gpswiss.fr/commande')->assertSee('Paiements temporairement indisponibles');
        $this->submit($this->key())->assertRedirect()->assertSessionHas('warning');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_missing_eur_shipping_addresses_and_multiple_units_block_before_writes(): void
    {
        $this->part->update(['currency' => 'EUR']);
        $this->assertBlocked();
        $this->part->update(['currency' => 'PLN']);
        MarketplaceCategoryMapping::query()->delete();
        $this->assertBlocked();
        MarketplaceCategoryMapping::create(['local_category_id' => $this->part->category_id, 'channel' => 'ebay_fr',
            'shipping_group' => 'fr_55_eur', 'fulfillment_policy_id' => '260547694013']);
        $this->mockCart(2);
        $this->assertBlocked();
        $this->mockCart();
        $this->withSession(['storefront.fr.address' => []]);
        $this->assertBlocked();
        Http::assertNothingSent();
        $this->assertSame(1, $this->part->fresh()->quantity);
    }

    public function test_local_ebay_eur_price_is_diagnostic_only_without_listing_or_mapping_writes(): void
    {
        $this->part->update(['currency' => 'PLN', 'price' => 400]);
        $listing = MarketplaceListing::create(['part_id' => $this->part->id, 'marketplace' => 'ebay_de', 'match_status' => 'matched',
            'external_offer_id' => 'fr-price-fixture', 'price' => 400, 'currency' => 'PLN', 'quantity' => 1, 'status' => 'active']);
        $before = $listing->fresh()->toArray();
        $this->submit($this->key())->assertRedirect('https://checkout.stripe.com/c/pay/fixture');
        $this->assertSame($before, $listing->fresh()->toArray());
        $this->assertSame('parts.price(PLN)*1.25->NBP_TABLE_A(EUR)', Order::sole()->items()->sole()->meta['price_source']);
        Http::assertSentCount(1);
    }

    public function test_return_is_signed_read_only_and_webhook_marks_test_payment_without_stock(): void
    {
        $this->submit($this->key())->assertRedirect();
        $order = Order::sole();
        $payload = json_decode(DB::table('stripe_fr_attempts')->sole()->request_payload, true);
        $this->get('https://gpswiss.fr/commande/stripe/retour/'.$order->id)->assertForbidden();
        $this->get($payload['success_url'])->assertOk();
        $this->get($payload['cancel_url'])->assertOk();
        Mail::assertSentCount(1);
        $this->assertSame('pending', $order->fresh()->payment_status);
        Mail::assertNotSent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'paid');
        $event = $this->event($order);
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();
        $this->webhook(array_replace($event, ['id' => 'evt_second_delivery']))->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('on_hold', $order->fresh()->status);
        $this->assertSame(1, $this->part->fresh()->quantity);
        $this->assertDatabaseCount('stripe_fr_events', 2);
        Mail::assertSent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'paid');
        $this->assertCount(1, Mail::sent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'paid'));
        $this->assertDatabaseCount('storefront_fr_order_notifications', 2);
        Http::assertSentCount(1); // Return + webhook never call Stripe/PayU.
    }

    public function test_live_webhook_applies_local_one_unit_once_and_late_expiry_cannot_undo_paid(): void
    {
        config(['stripe-fr.mode' => 'live', 'stripe-fr.secret_key' => 'sk_live_fixture', 'stripe-fr.publishable_key' => 'pk_live_fixture']);
        $this->submit($this->key())->assertRedirect();
        $order = Order::sole();
        $event = $this->event($order);
        DB::table('parts')->where('id', $this->part->id)->update(['ebay_price' => '777.00']);
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();
        $this->assertSame('777.00', $this->part->fresh()->ebay_price);
        $this->assertSame(0, $this->part->fresh()->quantity);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('processing', $order->fresh()->status);
        $event['id'] = 'evt_late_expired';
        $event['type'] = 'checkout.session.expired';
        $event['data']['object']['status'] = 'expired';
        $this->webhook($event)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(0, $this->part->fresh()->quantity);
    }

    public function test_webhook_rejects_wrong_identity_amount_currency_and_signature(): void
    {
        $this->submit($this->key())->assertRedirect();
        $order = Order::sole();
        foreach (['amount_total' => 1, 'currency' => 'pln', 'client_reference_id' => '9999'] as $field => $value) {
            $event = $this->event($order);
            $event['data']['object'][$field] = $value;
            $this->webhook($event)->assertStatus(422);
        }
        foreach (['storefront_code' => 'gpswiss_pl', 'payment_provider' => 'payu'] as $field => $value) {
            $event = $this->event($order);
            $event['data']['object']['metadata'][$field] = $value;
            $this->webhook($event)->assertStatus(422);
        }
        $meta = $order->meta;
        $meta['storefront_code'] = 'gpswiss_pl';
        $order->update(['meta' => $meta]);
        $this->webhook($this->event($order))->assertStatus(422);
        $order->update(['meta' => array_replace($meta, ['storefront_code' => 'gpswiss_fr', 'payment_provider' => 'payu'])]);
        $this->webhook($this->event($order))->assertStatus(422);
        $this->call('POST', 'https://gpswiss.fr/payments/stripe/fr/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'invalid'], '{}')->assertStatus(400);
        $this->webhook($this->event($order), 'gpswiss.pl')->assertNotFound();
        $this->assertDatabaseCount('stripe_fr_events', 0);
        Mail::assertNotSent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'paid');
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(1, $this->part->fresh()->quantity);
    }

    public function test_expired_session_fails_pending_order_and_releases_hold_without_stock(): void
    {
        $this->submit($this->key())->assertRedirect();
        $order = Order::sole();
        $event = $this->event($order);
        $event['type'] = 'checkout.session.expired';
        $event['data']['object']['status'] = 'expired';
        $event['data']['object']['payment_status'] = 'unpaid';
        $this->webhook($event)->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('expired', DB::table('stripe_fr_attempts')->sole()->status);
        $this->webhook($event)->assertOk();
        $this->assertCount(1, Mail::sent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'expired'));
        Mail::assertNotSent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'paid');
        $this->assertSame(1, $this->part->fresh()->quantity);
    }

    public function test_network_failure_retries_same_order_payload_and_key(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $calls = 0;
        Http::fake(['api.stripe.com/*' => function ($request) use (&$calls) {
            if (++$calls === 1) {
                return Http::response([], 500);
            }

            return Http::response([
                'id' => 'cs_test_fr_fixture', 'object' => 'checkout.session', 'mode' => 'payment', 'livemode' => false,
                'currency' => 'eur', 'amount_total' => 15500, 'url' => 'https://checkout.stripe.com/c/pay/fixture',
                'client_reference_id' => $request['client_reference_id'], 'metadata' => $request['metadata'],
            ]);
        }]);
        $key = $this->key();
        $this->submit($key)->assertRedirect()->assertSessionHasErrors('payment');
        $this->submit($key)->assertRedirect('https://checkout.stripe.com/c/pay/fixture');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stripe_fr_attempts', 1);
        $requests = Http::recorded()->pluck(0);
        $this->assertSame($requests[0]->data(), $requests[1]->data());
        $this->assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
    }

    public function test_pending_fr_hold_blocks_second_buyer_and_expiry_restores_checkout_key(): void
    {
        $key = $this->key();
        $this->submit($key)->assertRedirect();
        $this->withSession(['storefront.fr.checkout_key' => (string) Str::uuid()]);
        $this->submit($this->key())->assertRedirect()->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(1);
        $this->travel(41)->minutes();
        $next = $this->key();
        $this->assertNotSame($key, $next);
    }

    public function test_late_payment_stock_conflict_is_paid_on_hold_without_stock_or_marketplace_updates(): void
    {
        config(['stripe-fr.mode' => 'live', 'stripe-fr.secret_key' => 'sk_live_fixture', 'stripe-fr.publishable_key' => 'pk_live_fixture']);
        $this->submit($this->key())->assertRedirect();
        $order = Order::sole();
        DB::table('parts')->where('id', $this->part->id)->update(['quantity' => 0, 'status' => 'sold']);
        $this->webhook($this->event($order))->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('on_hold', $order->fresh()->status);
        $this->assertSame(0, $this->part->fresh()->quantity);
        $this->assertSame('stock_conflict_after_payment', $order->fresh()->meta['stripe']['review_reason']);
    }

    public function test_webhook_rejects_cancelled_order_and_stale_signature_without_mutations(): void
    {
        $key = $this->key();
        $this->submit($key)->assertRedirect();
        $order = Order::sole();
        $order->update(['status' => 'cancelled']);
        $this->submit($key)->assertRedirect()->assertSessionHasErrors('payment');
        $this->webhook($this->event($order))->assertStatus(422);
        $body = json_encode($this->event($order));
        $time = now()->timestamp - 301;
        $sig = hash_hmac('sha256', $time.'.'.$body, 'whsec_fixture');
        $this->call('POST', 'https://gpswiss.fr/payments/stripe/fr/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$sig], $body)->assertStatus(400);
        $this->assertDatabaseCount('stripe_fr_events', 0);
        Mail::assertNotSent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'paid');
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_multiple_positions_block_but_ambiguous_marketplace_prices_do_not(): void
    {
        $cart = $this->mock(CartService::class);
        $item = ['part_id' => $this->part->id, 'current_part' => $this->part->fresh(), 'quantity' => 1,
            'name' => 'Silnik', 'is_available' => true, 'sku' => 'STRIPE-FR', 'slug' => 'stripe-fr', 'image_url' => null];
        $cart->shouldReceive('items')->andReturn(collect([$item, $item]));
        $cart->shouldReceive('count')->andReturn(2);
        $cart->shouldNotReceive('clear');
        $this->assertBlocked();
        $this->mockCart();
        $this->part->update(['currency' => 'PLN']);
        foreach ([100, 110] as $i => $price) {
            MarketplaceListing::create(['part_id' => $this->part->id, 'marketplace' => 'ebay_de', 'match_status' => 'matched',
                'external_offer_id' => 'ambiguous-'.$i, 'price' => $price, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'active']);
        }
        $this->submit($this->key())->assertRedirect('https://checkout.stripe.com/c/pay/fixture');
        Http::assertSentCount(1);
    }

    public function test_changed_price_invalidates_old_checkout_key_before_order_or_stripe(): void
    {
        $key = $this->key();
        $this->part->update(['price' => 101]);
        $this->submit($key)->assertRedirect()->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_webhook_can_bind_creating_attempt_before_session_response_and_during_maintenance(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => function ($request) {
            $order = Order::sole();
            $event = $this->event($order);
            $event['data']['object']['id'] = 'cs_test_fr_fixture';
            config(['frontend-maintenance.enabled' => true]);
            $this->webhook($event)->assertOk();
            config(['frontend-maintenance.enabled' => false]);

            return Http::response(['id' => 'cs_test_fr_fixture', 'object' => 'checkout.session', 'mode' => 'payment',
                'livemode' => false, 'currency' => 'eur', 'amount_total' => 15500,
                'url' => 'https://checkout.stripe.com/c/pay/fixture',
                'client_reference_id' => $request['client_reference_id'], 'metadata' => $request['metadata']]);
        }]);
        $this->submit($this->key())->assertRedirect('https://checkout.stripe.com/c/pay/fixture');
        $this->assertSame('paid', Order::sole()->payment_status);
        $this->assertSame('paid', DB::table('stripe_fr_attempts')->sole()->status);
        $this->assertSame(1, $this->part->fresh()->quantity);
    }

    public function test_polish_checkout_still_uses_payu_when_stripe_fr_is_enabled(): void
    {
        $cart = $this->mock(CartService::class);
        $cart->shouldReceive('items')->once()->andReturn(collect([[
            'is_available' => true, 'name' => 'PL fixture', 'currency' => 'PLN',
            'unit_price' => 100, 'quantity' => 1, 'line_total' => 100,
        ]]));
        $cart->shouldReceive('clear')->once();
        $this->mock(PayuService::class)->shouldReceive('createOrder')->once()->andReturn([
            'response' => ['orderId' => 'PAYU-PL-FIXTURE'], 'payload' => ['extOrderId' => 'EXT-PL'],
            'redirectUri' => 'https://secure.payu.com/pl-fixture',
        ]);
        $this->post('https://gpswiss.pl/zamowienie', [
            'customer_type' => 'private', 'billing_first_name' => 'Jan', 'billing_last_name' => 'Test',
            'billing_street' => 'Test', 'billing_building_number' => '1', 'billing_postal_code' => '00-001',
            'billing_city' => 'Warszawa', 'billing_phone' => '123456789', 'billing_email' => 'pl@example.test',
            'shipping_same_as_billing' => 1, 'shipping_method' => 'courier', 'payment_method' => 'payu', 'terms' => 1,
        ])->assertRedirect('https://secure.payu.com/pl-fixture');
        $this->assertSame('payu', Order::sole()->paymentProvider());
        $this->assertSame('gpswiss_pl', Order::sole()->storefrontCode());
        $this->assertDatabaseCount('stripe_fr_attempts', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    private function key(): string
    {
        return $this->get('https://gpswiss.fr/commande')->assertOk()->viewData('draft')['checkout_key'];
    }

    private function submit(string $key): TestResponse
    {
        return $this->post('https://gpswiss.fr/commande', ['checkout_key' => $key, 'terms' => '1', 'total' => 1, 'currency' => 'PLN']);
    }

    private function assertBlocked(): void
    {
        $this->submit($this->key())->assertRedirect()->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 0);
    }

    private function event(Order $order): array
    {
        $attempt = DB::table('stripe_fr_attempts')->where('order_id', $order->id)->first();

        return ['id' => 'evt_fr_fixture', 'type' => 'checkout.session.completed', 'livemode' => config('stripe-fr.mode') === 'live',
            'data' => ['object' => ['object' => 'checkout.session', 'id' => $attempt->session_id, 'mode' => 'payment',
                'livemode' => config('stripe-fr.mode') === 'live', 'status' => 'complete', 'payment_status' => 'paid',
                'payment_intent' => 'pi_fr_fixture', 'currency' => 'eur', 'amount_total' => 15500,
                'client_reference_id' => (string) $order->id, 'metadata' => ['order_id' => (string) $order->id,
                    'checkout_key' => $attempt->checkout_key, 'storefront_code' => 'gpswiss_fr', 'payment_provider' => 'stripe']]]];
    }

    private function webhook(array $event, string $host = 'gpswiss.fr'): TestResponse
    {
        $body = json_encode($event);
        $signature = hash_hmac('sha256', now()->timestamp.'.'.$body, 'whsec_fixture');

        return $this->call('POST', 'https://'.$host.'/payments/stripe/fr/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => 't='.now()->timestamp.',v1='.$signature], $body);
    }

    private function mockCart(int $quantity = 1): void
    {
        $cart = $this->mock(CartService::class);
        $cart->shouldReceive('items')->andReturnUsing(fn () => collect([['part_id' => $this->part->id,
            'current_part' => $this->part->fresh(), 'quantity' => $quantity, 'name' => 'Silnik', 'is_available' => true,
            'sku' => 'STRIPE-FR', 'slug' => 'stripe-fr', 'image_url' => null]]));
        $cart->shouldReceive('count')->andReturn($quantity);
        $cart->shouldReceive('clear')->zeroOrMoreTimes();
    }

    private function address(): array
    {
        return ['customer_type' => 'private', 'billing_first_name' => 'Jean', 'billing_last_name' => 'Dupont',
            'billing_address_line1' => '7 rue Exemple', 'billing_postal_code' => '75008', 'billing_city' => 'Paris',
            'billing_country' => 'FR', 'billing_email' => 'jean@example.test', 'billing_phone' => '+33123456789',
            'shipping_same_as_billing' => true, 'shipping_country' => 'FR'];
    }
}
