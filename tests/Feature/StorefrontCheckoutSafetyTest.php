<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveStorefrontHost;
use App\Models\Order;
use App\Services\Marketplace\PartAvailabilityEventService;
use App\Services\Payments\PayuService;
use App\Services\Storefront\CartService;
use App\Support\StorefrontContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StorefrontCheckoutSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['frontend-maintenance.enabled' => false]);
        Http::preventStrayRequests();
        Route::middleware('web')->get('/_storefront-identity', fn (Request $request) => response()->json([
            'code' => $request->attributes->get('storefront_code'),
            'locale' => app()->getLocale(),
            'provider' => $request->attributes->get('storefront_payment_provider'),
        ]))->name('storefront.identity-probe');
    }

    public function test_french_and_polish_hosts_have_separate_identity(): void
    {
        $this->withSession(['locale' => 'pl'])->get('https://gpswiss.fr/_storefront-identity')
            ->assertOk()->assertJson(['code' => 'gpswiss_fr', 'locale' => 'fr', 'provider' => 'stripe']);
        $this->withSession(['locale' => 'de'])->get('https://gpswiss.pl/_storefront-identity')
            ->assertOk()->assertJson(['code' => 'gpswiss_pl', 'locale' => 'de', 'provider' => 'payu']);
        $metadata = StorefrontContext::orderMetadata(Request::create('https://gpswiss.fr/zamowienie'));
        $this->assertSame('gpswiss_fr', $metadata['storefront_code']);
        $this->assertSame('fr', $metadata['locale']);
        $this->assertSame('stripe', $metadata['payment_provider']);
    }

    public function test_french_checkout_get_and_posts_cannot_call_provider_or_create_orders(): void
    {
        $this->mock(CartService::class)->shouldNotReceive('items', 'isEmpty', 'clear');
        $this->mock(PayuService::class)->shouldNotReceive('createOrder', 'getOrder');
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');
        $this->get('https://gpswiss.fr/zamowienie')->assertOk()
            ->assertViewIs('storefront.checkout.unavailable')
            ->assertSee('Paiements temporairement indisponibles')
            ->assertDontSee('name="payment_method"', false);
        foreach (['gpswiss.fr', 'www.gpswiss.fr'] as $host) {
            $this->post('https://'.$host.'/zamowienie', [
                'payment_method' => 'payu', 'storefront_code' => 'gpswiss_pl', 'locale' => 'pl',
            ])->assertRedirect()->assertSessionHas('warning');
        }
        $this->get('https://gpswiss.fr/zamowienie/payu/powrot?order=1')
            ->assertOk()->assertViewIs('storefront.checkout.unavailable');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        Http::assertNothingSent();
    }

    public function test_polish_http_checkout_still_redirects_to_payu_and_grants_session_return_access(): void
    {
        $cart = $this->mock(CartService::class);
        $cart->shouldReceive('items')->once()->andReturn(collect([[
            'is_available' => true, 'name' => 'Polish fixture', 'currency' => 'PLN',
            'unit_price' => 100, 'quantity' => 1, 'line_total' => 100,
        ]]));
        $cart->shouldReceive('clear')->once();
        $this->mock(PayuService::class)->shouldReceive('createOrder')->once()->andReturn([
            'response' => ['orderId' => 'PAYU-SAFETY'], 'payload' => ['extOrderId' => 'EXT-SAFETY'],
            'redirectUri' => 'https://secure.payu.com/safety-fixture',
        ]);
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');
        $this->post('https://gpswiss.pl/zamowienie', [
            'customer_type' => 'private', 'billing_first_name' => 'Test', 'billing_last_name' => 'Klient',
            'billing_street' => 'Test', 'billing_building_number' => '1', 'billing_postal_code' => '00-001',
            'billing_city' => 'Warszawa', 'billing_phone' => '123456789', 'billing_email' => 'pl@example.test',
            'shipping_same_as_billing' => 1, 'shipping_method' => 'courier', 'payment_method' => 'payu', 'terms' => '1',
        ])->assertRedirect('https://secure.payu.com/safety-fixture');
        $order = Order::query()->sole();
        $this->assertSame('gpswiss_pl', $order->storefrontCode());
        $this->assertSame('payu', $order->paymentProvider());
        $this->assertSame('PLN', $order->currency);
        $this->assertGreaterThan(now()->timestamp, session('storefront.order_access.'.$order->id));
        Http::assertNothingSent();
    }

    public function test_www_french_navigation_is_canonical_and_post_retains_french_identity(): void
    {
        $this->get('http://www.gpswiss.fr/czesci?q=moteur')->assertStatus(301)
            ->assertRedirect('https://gpswiss.fr/czesci?q=moteur');
        $this->get('https://www.gpswiss.fr/regulamin')->assertRedirect('https://gpswiss.fr/regulamin');
        $request = Request::create('https://www.gpswiss.fr/zamowienie', 'POST');
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
        (new ResolveStorefrontHost)->handle($request, function (Request $request) {
            $this->assertSame('gpswiss_fr', $request->attributes->get('storefront_code'));
            $this->assertSame('fr', $request->attributes->get('storefront_locale'));

            return response('safe');
        });
    }

    public function test_thank_you_rejects_unsigned_tampered_and_expired_links(): void
    {
        $order = $this->order();
        $this->get('/zamowienie/dziekujemy/'.$order->id)->assertForbidden()->assertDontSee($order->email);
        $url = URL::temporarySignedRoute('storefront.checkout.thank-you', now()->addMinutes(5), ['order' => $order->id]);
        $this->get($url)->assertOk()->assertSee($order->email);
        $other = $this->order('OTHER');
        $this->get(str_replace('/dziekujemy/'.$order->id.'?', '/dziekujemy/'.$other->id.'?', $url))
            ->assertForbidden()->assertDontSee($other->email);
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden()->assertDontSee($order->email);
    }

    public function test_payu_return_does_not_lookup_provider_for_numeric_id_alone(): void
    {
        $order = $this->order();
        $this->mock(PayuService::class)->shouldNotReceive('getOrder');
        $this->get('https://gpswiss.pl/zamowienie/payu/powrot?order='.$order->id)
            ->assertOk()->assertViewHas('order', null)->assertDontSee($order->order_number);
        Http::assertNothingSent();
    }

    public function test_payu_return_keeps_current_checkout_session_access(): void
    {
        $order = $this->order();
        $this->mock(PayuService::class)->shouldReceive('getOrder')->once()->with('PAYU-SAFETY')
            ->andReturn(['orders' => [['status' => 'PENDING']]]);
        $this->withSession(['storefront' => ['order_access' => [$order->id => now()->addDays(7)->timestamp]]])
            ->get('https://gpswiss.pl/zamowienie/payu/powrot?order='.$order->id)
            ->assertOk()->assertSee($order->order_number);
    }

    public function test_model_exposes_future_french_identity_separately(): void
    {
        $order = new Order(['meta' => ['storefront_code' => 'gpswiss_fr', 'locale' => 'fr', 'payment_provider' => 'stripe']]);
        $this->assertTrue($order->isFrenchStorefront());
        $this->assertFalse($order->isPolishStorefront());
        $this->assertSame('gpswiss_fr', $order->storefrontCode());
        $this->assertSame('stripe', $order->paymentProvider());
    }

    private function order(string $suffix = 'ONE'): Order
    {
        return Order::create([
            'order_number' => 'GPS-SAFETY-'.$suffix, 'status' => 'new', 'currency' => 'PLN',
            'subtotal' => 100, 'shipping_total' => 0, 'total' => 100, 'payment_status' => 'pending',
            'customer_name' => 'Private Customer', 'email' => strtolower($suffix).'@example.test',
            'phone' => '123456789', 'address_line1' => 'Private Address 1', 'postal_code' => '00-001',
            'city' => 'Warszawa', 'country' => 'PL',
            'meta' => ['storefront_code' => 'gpswiss_pl', 'payu' => ['order_id' => 'PAYU-SAFETY']],
        ]);
    }
}
