<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Order;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\PartAvailabilityEventService;
use App\Services\Payments\PayuService;
use App\Services\Storefront\CartService;
use App\Services\Storefront\FrenchDeliveryQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FrenchCheckoutDraftTest extends TestCase
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

    public function test_draft_has_french_addresses_snapshot_and_no_payment_even_with_valid_quote(): void
    {
        $part = $this->part();
        $part->translations()->create(['locale' => 'fr', 'name' => 'Moteur relu', 'status' => 'reviewed']);
        $this->mapping($part, 'fr_55_eur', '260547694013');
        config(['storefront-fr-checkout.pricing_policy' => 'catalog_eur']);
        $this->cart($part);
        $response = $this->get('https://gpswiss.fr/commande')->assertOk()
            ->assertSee('Moteur relu')->assertSee('55,00 EUR')->assertDontSee('billing_nip')
            ->assertDontSee('value="courier_cod"', false)->assertDontSee('value="pickup"', false);
        $draft = $response->viewData('draft');
        $this->assertSame('FR', $draft['billing']['country']);
        $this->assertSame('FR', $draft['shipping']['country']);
        $this->assertSame('EUR', $draft['order_attributes']['currency']);
        $this->assertSame('gpswiss_fr', $draft['order_attributes']['meta']['storefront_code']);
        $this->assertSame('fr', $draft['order_attributes']['meta']['locale']);
        $this->assertSame('stripe', $draft['order_attributes']['meta']['payment_provider']);
        $this->assertSame('Moteur relu', $draft['order_items'][0]['product_name']);
        $this->assertSame('100.00', $draft['order_items'][0]['unit_price']);
        $this->assertSame(15500, $draft['total_cents']);
        $this->assertFalse($draft['can_place_order']);
        $this->post('https://gpswiss.fr/commande', ['payment_method' => 'payu'])->assertRedirect();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(2, $part->fresh()->quantity);
        Http::assertNothingSent();
    }

    public function test_pln_is_not_relabelled_or_converted_and_missing_delivery_is_unavailable(): void
    {
        $part = $this->part('PLN');
        config(['storefront-fr-checkout.pricing_policy' => 'catalog_eur']);
        $this->cart($part);
        $response = $this->get('https://gpswiss.fr/panier')->assertOk()->assertSee('Prix indisponible');
        $draft = $response->viewData('draft');
        $this->assertNull($draft['subtotal_cents']);
        $this->assertNull($draft['shipping_cents']);
        $this->assertNull($draft['total_cents']);
        $this->assertSame('EUR', $draft['order_items'][0]['currency']);
        $this->assertNull($draft['order_items'][0]['unit_price']);
        Http::assertNothingSent();
    }

    public function test_preview_validates_fr_company_without_nip_and_only_saves_session(): void
    {
        $this->cart($this->part());
        $data = [
            'customer_type' => 'company', 'billing_company_name' => 'Entreprise FR',
            'billing_first_name' => 'Jean', 'billing_last_name' => 'Dupont', 'billing_email' => 'jean@example.test',
            'billing_phone' => '+33123456789', 'billing_address_line1' => '7 rue Exemple',
            'billing_postal_code' => '75008', 'billing_city' => 'Paris', 'billing_country' => 'FR',
            'shipping_same_as_billing' => '1', 'shipping_country' => 'FR',
        ];
        $this->post('https://gpswiss.fr/commande/apercu', $data)->assertRedirect('https://gpswiss.fr/commande')
            ->assertSessionHas('storefront.fr.address.billing_country', 'FR');
        $this->get('https://gpswiss.fr/commande')->assertSee('Entreprise FR');
        $data['billing_country'] = 'PL';
        $this->post('https://gpswiss.fr/commande/apercu', $data)->assertSessionHasErrors('billing_country');
        $this->post('https://gpswiss.pl/commande/apercu', $data)->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_existing_three_fr_policies_are_read_without_changing_mapping(): void
    {
        $part = $this->part();
        foreach ([['fr_55_eur', '260547694013', 5500], ['fr_70_eur', '260547464013', 7000], ['fr_130_eur', '260547754013', 13000]] as [$group, $policy, $amount]) {
            MarketplaceCategoryMapping::query()->delete();
            $mapping = $this->mapping($part, $group, $policy);
            $before = $mapping->fresh()->toArray();
            $quote = app(FrenchDeliveryQuoteService::class)->forPart($part);
            $this->assertSame($amount, $quote['amount_cents']);
            $this->assertSame($before, $mapping->fresh()->toArray());
        }
        $mapping->update(['is_blocked' => true]);
        $this->assertFalse(app(FrenchDeliveryQuoteService::class)->forPart($part)['available']);
        Http::assertNothingSent();
    }

    public function test_unapproved_multi_unit_delivery_and_model_identity_stay_safe(): void
    {
        $part = $this->part();
        $this->mapping($part, 'fr_55_eur', '260547694013');
        $this->cart($part, 2);
        $draft = $this->get('https://gpswiss.fr/commande')->assertOk()->viewData('draft');
        $this->assertNull($draft['shipping_cents']);
        $this->assertFalse($draft['can_place_order']);
        $order = new Order(['meta' => ['storefront_code' => 'gpswiss_fr', 'locale' => 'fr', 'payment_provider' => 'stripe']]);
        $this->assertSame('gpswiss_fr', $order->storefrontCode());
        $this->assertTrue($order->isFrenchStorefront());
        $this->assertFalse($order->isPolishStorefront());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_policy_mismatch_and_ambiguous_fallback_are_not_quoted(): void
    {
        $part = $this->part();
        $mapping = $this->mapping($part, 'fr_55_eur', '260547754013');
        $quotes = app(FrenchDeliveryQuoteService::class);
        $this->assertFalse($quotes->forPart($part)['available']);
        $mapping->update(['shipping_group' => null, 'fulfillment_policy_id' => null, 'external_category_id' => 'AMBIGUOUS']);
        foreach ([['fr_55_eur', '260547694013'], ['fr_70_eur', '260547464013']] as $i => [$group, $policy]) {
            MarketplaceCategoryMapping::create(['local_category_id' => 1000 + $i, 'channel' => 'ebay_fr',
                'external_category_id' => 'AMBIGUOUS', 'shipping_group' => $group, 'fulfillment_policy_id' => $policy]);
        }
        $this->assertSame('delivery_mapping_ambiguous', $quotes->forPart($part)['reason']);
        Http::assertNothingSent();
    }

    private function part(string $currency = 'EUR'): Part
    {
        $category = PartCategory::create(['name' => 'Silniki', 'slug' => 'silniki-test']);

        return Part::create(['name' => 'Silnik', 'sku' => 'FR-DRAFT', 'slug' => 'fr-draft', 'price' => 100,
            'currency' => $currency, 'category_id' => $category->id, 'quantity' => 2, 'status' => 'ready']);
    }

    private function mapping(Part $part, string $group, string $policy): MarketplaceCategoryMapping
    {
        return MarketplaceCategoryMapping::create(['local_category_id' => $part->category_id, 'channel' => 'ebay_fr',
            'shipping_group' => $group, 'fulfillment_policy_id' => $policy]);
    }

    private function cart(Part $part, int $quantity = 1): void
    {
        $cart = $this->mock(CartService::class);
        $cart->shouldReceive('items')->andReturn(collect([['part_id' => $part->id, 'current_part' => $part,
            'name' => $part->name, 'sku' => $part->sku, 'slug' => $part->slug, 'quantity' => $quantity,
            'is_available' => true, 'image_url' => null]]));
        $cart->shouldReceive('count')->andReturn($quantity);
        $cart->shouldNotReceive('clear');
    }
}
