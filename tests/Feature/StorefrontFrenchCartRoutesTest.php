<?php

namespace Tests\Feature;

use App\Services\Marketplace\PartAvailabilityEventService;
use App\Services\Payments\PayuService;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontFrenchCartRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['frontend-maintenance.enabled' => false]);
        Http::preventStrayRequests();
        $this->mock(PayuService::class)->shouldNotReceive('createOrder', 'getOrder');
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');
    }

    public function test_french_cart_and_ui_links_use_localized_paths(): void
    {
        $cart = $this->mock(CartService::class);
        $cart->shouldReceive('items')->andReturn(collect([[
            'part_id' => 1, 'name' => 'Fixture', 'slug' => 'fixture', 'image_url' => null,
            'current_part' => null, 'sku' => 'FIXTURE', 'quantity' => 1, 'current_quantity' => 2,
            'is_available' => true, 'unit_price' => 100, 'line_total' => 100, 'currency' => 'PLN',
        ]]));
        $cart->shouldReceive('subtotal')->andReturn(100);
        $cart->shouldReceive('isEmpty')->andReturnFalse();
        $cart->shouldReceive('count')->andReturn(1);
        $this->get('https://gpswiss.fr/panier')->assertOk()->assertSee('lang="fr"', false)
            ->assertSee('https://gpswiss.fr/panier')->assertSee('https://gpswiss.fr/commande');
        $this->get('https://gpswiss.fr/koszyk')->assertOk()->assertSee('https://gpswiss.fr/commande');
        Http::assertNothingSent();
    }

    public function test_french_checkout_alias_and_post_keep_payment_guard(): void
    {
        $cart = $this->mock(CartService::class);
        $cart->shouldNotReceive('items', 'isEmpty', 'clear');
        $cart->shouldReceive('count')->andReturn(0);
        $this->get('https://gpswiss.fr/commande')->assertOk()
            ->assertSee('Paiements temporairement indisponibles')->assertSee('https://gpswiss.fr/panier')
            ->assertDontSee('name="payment_method"', false);
        foreach (['gpswiss.fr', 'www.gpswiss.fr'] as $host) {
            $this->post('https://'.$host.'/commande', ['payment_method' => 'payu'])
                ->assertRedirect('https://gpswiss.fr/commande')->assertSessionHas('warning');
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        Http::assertNothingSent();
    }

    public function test_cart_actions_return_to_the_correct_storefront_cart(): void
    {
        $this->mock(CartService::class)->shouldReceive('clear')->twice();
        $this->post('https://gpswiss.fr/koszyk/wyczysc')->assertRedirect('https://gpswiss.fr/panier');
        $this->post('https://gpswiss.pl/koszyk/wyczysc')->assertRedirect('https://gpswiss.pl/koszyk');
        Http::assertNothingSent();
    }

    public function test_www_french_aliases_redirect_to_apex(): void
    {
        $this->get('https://www.gpswiss.fr/panier')->assertStatus(301)
            ->assertRedirect('https://gpswiss.fr/panier');
        $this->get('https://www.gpswiss.fr/commande?from=cart')->assertStatus(301)
            ->assertRedirect('https://gpswiss.fr/commande?from=cart');
    }

    public function test_polish_links_and_checkout_route_are_unchanged(): void
    {
        $cart = $this->mock(CartService::class);
        $cart->shouldReceive('items')->andReturn(collect());
        $cart->shouldReceive('subtotal', 'count')->andReturn(0);
        $cart->shouldReceive('isEmpty')->andReturnTrue();
        $this->get('https://gpswiss.pl/koszyk')->assertOk()->assertSee('https://gpswiss.pl/koszyk')
            ->assertDontSee('https://gpswiss.fr/panier');
        $this->get('https://gpswiss.pl/zamowienie')->assertRedirect('https://gpswiss.pl/koszyk');
        $this->get('https://gpswiss.pl/panier')->assertNotFound();
        $this->get('https://gpswiss.pl/commande')->assertNotFound();
        Http::assertNothingSent();
    }
}
