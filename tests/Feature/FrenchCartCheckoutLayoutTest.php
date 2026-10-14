<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FrenchCartCheckoutLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['frontend-maintenance.enabled' => false]);
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A', 'rate' => 5, 'effective_date' => today()->toDateString()], 3600);
        $category = PartCategory::create(['name' => 'Moteurs', 'slug' => 'layout-category']);
        $this->part = Part::create(['name' => 'Moteur test', 'sku' => 'FR-LAYOUT', 'category_id' => $category->id,
            'currency' => 'PLN', 'price' => 400, 'quantity' => 1, 'status' => 'ready']);
        MarketplaceCategoryMapping::create(['local_category_id' => $category->id, 'channel' => 'ebay_fr',
            'shipping_group' => 'fr_55_eur', 'fulfillment_policy_id' => '260547694013']);
        $this->post('https://gpswiss.fr/koszyk/dodaj/'.$this->part->id)->assertRedirect();
    }

    public function test_cart_uses_pl_design_with_fr_data_and_concrete_shipping(): void
    {
        $response = $this->get('https://gpswiss.fr/panier')->assertOk();
        foreach (['sf-cart-layout', 'sf-cart-item__image', 'sf-cart-item__title', 'sf-cart-item__qty',
            'sf-cart-item__price', 'sf-cart-item__remove', 'sf-cart-summary', 'FR-LAYOUT',
            '100,00 EUR', '55,00 EUR', '155,00 EUR', 'Sous-total', 'Livraison', 'Résumé'] as $value) {
            $response->assertSee($value);
        }
        $response->assertSee('https://gpswiss.fr/commande')->assertSee('max="1"', false)
            ->assertDontSee('Ce récapitulatif ne constitue pas une commande')
            ->assertDontSee('PLN')->assertDontSee('Frais de livraison calculés au panier');
        $this->assertNoPolishPaymentChoices($response->getContent());
        $this->assertSame(1, $this->part->fresh()->quantity);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_uses_styled_sections_and_eur_summary_without_pl_payment_choices(): void
    {
        $response = $this->get('https://gpswiss.fr/commande')->assertOk();
        foreach (['sf-checkout-layout', 'sf-checkout-form', 'sf-checkout-grid', 'sf-field--half',
            'sf-checkout-options--segmented', 'sf-checkout-terms', 'sf-checkout-summary',
            'Informations de facturation', 'Adresse de livraison', 'Méthode de livraison',
            'Paiement', 'Acceptations', 'Résumé', 'France', 'Stripe', 'Payer par carte',
            '100,00 EUR', '55,00 EUR', '155,00 EUR'] as $value) {
            $response->assertSee($value);
        }
        $response->assertSee('name="billing_country" value="FR"', false)
            ->assertSee('name="shipping_country" value="FR"', false)
            ->assertDontSee('name="billing_nip"', false)->assertDontSee('PLN')
            ->assertDontSee('Ce récapitulatif')->assertDontSee('Frais de livraison calculés au panier');
        $this->assertNoPolishPaymentChoices($response->getContent());
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_complete_saved_address_enables_the_existing_stripe_form_with_scoped_terms(): void
    {
        config(['stripe-fr.enabled' => true, 'stripe-fr.mode' => 'test', 'stripe-fr.secret_key' => 'sk_test_layout',
            'stripe-fr.publishable_key' => 'pk_test_layout', 'stripe-fr.webhook_secret' => 'whsec_layout']);
        $this->withSession(['storefront.fr.address' => [
            'customer_type' => 'private', 'billing_first_name' => 'Jean', 'billing_last_name' => 'Dupont',
            'billing_address_line1' => '7 rue Exemple', 'billing_postal_code' => '75008', 'billing_city' => 'Paris',
            'billing_country' => 'FR', 'billing_email' => 'jean@example.test', 'billing_phone' => '+33123456789',
            'shipping_same_as_billing' => true, 'shipping_country' => 'FR',
        ]])->get('https://gpswiss.fr/commande')->assertOk()->assertViewHas('draft', fn ($draft) => $draft['can_place_order'])
            ->assertSee('id="fr-stripe-payment"', false)->assertSee('form="fr-stripe-payment"', false)
            ->assertSee('name="checkout_key"', false)->assertSee('https://gpswiss.fr/commande')
            ->assertSee('Payer par carte')->assertSee('155,00 EUR');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_each_approved_shipping_rate_is_rendered_in_cart_and_checkout(): void
    {
        foreach ([70 => '260547464013', 130 => '260547754013'] as $amount => $policy) {
            MarketplaceCategoryMapping::query()->update(['shipping_group' => 'fr_'.$amount.'_eur', 'fulfillment_policy_id' => $policy]);
            foreach (['/panier', '/commande'] as $path) {
                $this->get('https://gpswiss.fr'.$path)->assertOk()->assertSee($amount.',00 EUR')
                    ->assertSee((100 + $amount).',00 EUR');
            }
        }
    }

    public function test_missing_eur_and_unavailable_nbp_disable_continuation(): void
    {
        Cache::forget('nbp_table_a_rate:EUR:latest');
        $this->part->update(['currency' => 'PLN']);
        Http::fake(['api.nbp.pl/*' => Http::response([], 503)]);
        $this->get('https://gpswiss.fr/panier')->assertOk()->assertSee('Prix indisponible')
            ->assertDontSee('<a class="sf-btn" href="https://gpswiss.fr/commande">', false);
        $this->get('https://gpswiss.fr/commande')->assertOk()->assertSee('Prix indisponible')
            ->assertDontSee('id="fr-stripe-payment"', false);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_missing_delivery_disables_cart_continuation_and_payment(): void
    {
        MarketplaceCategoryMapping::query()->delete();
        $this->get('https://gpswiss.fr/panier')->assertOk()->assertSee('Livraison indisponible pour cet article')
            ->assertDontSee('<a class="sf-btn" href="https://gpswiss.fr/commande">', false)
            ->assertSee('disabled');
        $this->get('https://gpswiss.fr/commande')->assertOk()->assertSee('Livraison indisponible pour cet article')
            ->assertDontSee('id="fr-stripe-payment"', false)->assertSee('disabled');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_polish_cart_keeps_its_original_layout_and_data(): void
    {
        $this->post('https://gpswiss.pl/koszyk/wyczysc');
        $this->part->update(['currency' => 'PLN']);
        $this->post('https://gpswiss.pl/koszyk/dodaj/'.$this->part->id)->assertRedirect();
        $this->get('https://gpswiss.pl/koszyk')->assertOk()->assertViewIs('storefront.cart.index')
            ->assertSee('sf-cart-layout')->assertSee('400,00 PLN');
        $this->get('https://gpswiss.pl/zamowienie')->assertOk()->assertViewIs('storefront.checkout.show')
            ->assertSee('name="payment_method" value="payu"', false)->assertSee('name="payment_method" value="blik"', false);
    }

    public function test_second_product_and_repeated_add_are_blocked_without_replacing_the_first(): void
    {
        $original = session('storefront.cart.items');
        $other = $this->part->replicate();
        $other->sku = 'FR-SECOND';
        $other->slug = strtolower($other->sku);
        $other->save();
        foreach ([$other, $this->part] as $part) {
            $this->post('https://gpswiss.fr/koszyk/dodaj/'.$part->id)->assertRedirect()
                ->assertSessionHas('warning');
            $this->assertSame($original, session('storefront.cart.items'));
        }
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_fr_quantity_above_one_is_rejected_by_http_and_service(): void
    {
        $original = session('storefront.cart.items');
        $this->post('https://gpswiss.fr/koszyk/aktualizuj', ['quantities' => [$this->part->id => 2]])
            ->assertSessionHasErrors('quantities.'.$this->part->id);
        $cart = app(CartService::class);
        $this->assertSame('warning', $cart->update($this->part->id, 2)['status']);
        $this->assertSame('warning', $cart->add($this->part, 2)['status']);
        $this->assertSame($original, session('storefront.cart.items'));
    }

    public function test_legacy_multi_item_cart_has_safe_summary_and_can_be_recovered_by_removing(): void
    {
        $other = $this->part->replicate();
        $other->sku = 'FR-LEGACY';
        $other->slug = strtolower($other->sku);
        $other->save();
        $items = session('storefront.cart.items');
        $items[$other->id] = array_merge(reset($items), ['part_id' => $other->id, 'sku' => $other->sku]);
        $response = $this->withSession(['storefront.cart.items' => $items])->get('https://gpswiss.fr/panier')
            ->assertOk()->assertViewHas('draft', fn ($draft) => ! $draft['single_item'])
            ->assertSee('Le panier France accepte un seul article')
            ->assertDontSee('<a class="sf-btn" href="https://gpswiss.fr/commande">', false)
            ->assertDontSee('sf-checkout-summary__row--product');
        $response->assertSee('remove-fr-item-'.$other->id)->assertSee('https://gpswiss.fr/koszyk/wyczysc');
        $this->get('https://gpswiss.fr/commande')->assertOk()->assertDontSee('id="fr-stripe-payment"', false);
        $this->post('https://gpswiss.fr/koszyk/usun/'.$other->id)->assertRedirect();
        $this->get('https://gpswiss.fr/panier')->assertOk()
            ->assertViewHas('draft', fn ($draft) => $draft['single_item'])->assertSee('sf-checkout-summary__row--product');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_legacy_quantity_is_not_silently_clamped_into_valid_fr_checkout(): void
    {
        $items = session('storefront.cart.items');
        $items[$this->part->id]['quantity'] = 2;
        $this->withSession(['storefront.cart.items' => $items])->get('https://gpswiss.fr/panier')->assertOk()
            ->assertViewHas('draft', fn ($draft) => ! $draft['single_item'])
            ->assertDontSee('<a class="sf-btn" href="https://gpswiss.fr/commande">', false);
        $this->post('https://gpswiss.fr/koszyk/aktualizuj', ['quantities' => [$this->part->id => 1]])->assertRedirect();
        $this->get('https://gpswiss.fr/panier')->assertOk()->assertViewHas('draft', fn ($draft) => $draft['single_item']);
    }

    public function test_all_delivery_quotes_are_visible_on_listing_and_product_page(): void
    {
        foreach ([55 => '260547694013', 70 => '260547464013', 130 => '260547754013'] as $amount => $policy) {
            MarketplaceCategoryMapping::query()->update(['shipping_group' => 'fr_'.$amount.'_eur', 'fulfillment_policy_id' => $policy]);
            foreach (['/czesci', '/produkt/'.$this->part->slug] as $path) {
                $this->get('https://gpswiss.fr'.$path)->assertOk()->assertSee('Frais de livraison : '.$amount.',00 EUR')
                    ->assertDontSee('Frais de livraison calculés au panier')->assertDontSee('Livraison gratuite')
                    ->assertDontSee('le prochain jour ouvré')->assertDontSee('13 h 30');
            }
        }
        Http::assertNothingSent();
    }

    public function test_polish_cart_still_accepts_multiple_products_and_units(): void
    {
        $this->post('https://gpswiss.pl/koszyk/wyczysc');
        $this->part->update(['quantity' => 3, 'currency' => 'PLN']);
        $other = $this->part->replicate();
        $other->sku = 'PL-SECOND';
        $other->slug = strtolower($other->sku);
        $other->save();
        foreach ([$this->part, $this->part, $other] as $part) {
            $this->post('https://gpswiss.pl/koszyk/dodaj/'.$part->id)->assertSessionHas('success');
        }
        $items = session('storefront.cart.items');
        $this->assertCount(2, $items);
        $this->assertSame(2, $items[$this->part->id]['quantity']);
        $this->get('https://gpswiss.pl/koszyk')->assertOk()->assertSee('PLN');
    }

    private function assertNoPolishPaymentChoices(string $html): void
    {
        preg_match('/<main>(.*?)<\/main>/s', $html, $match);
        foreach (['PayU', 'BLIK', 'courier_cod', 'pickup', 'billing_nip'] as $value) {
            $this->assertStringNotContainsString($value, $match[1]);
        }
    }
}
