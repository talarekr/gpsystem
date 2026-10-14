<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ResolvesFrenchCategoryUrls;
use Tests\TestCase;

class FrenchProductPricePresentationTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesFrenchCategoryUrls;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['frontend-maintenance.enabled' => false]);
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A', 'rate' => 5, 'effective_date' => today()->toDateString()], 3600);
    }

    public function test_marked_up_pln_prices_are_shared_by_home_catalog_category_detail_and_related_cards(): void
    {
        $part = $this->part('PLN', 493.80);
        foreach (['/', '/czesci', parse_url($this->frenchCategoryUrl($part->category), PHP_URL_PATH), '/produkt/'.$part->slug] as $path) {
            $this->get('https://gpswiss.fr'.$path)->assertOk()->assertSee('123,45 EUR')->assertDontSee('PLN')
                ->assertDontSee('Livraison gratuite')->assertDontSee('Pour un paiement avant')
                ->assertSee('Frais de livraison : 55,00 EUR');
        }
        $other = $this->part('USD', 9999);
        $this->get('https://gpswiss.fr/produkt/'.$other->slug)->assertOk()->assertSee('123,45 EUR')
            ->assertSee('Prix indisponible')->assertDontSee('9 999,00')->assertDontSee('PLN')
            ->assertSee('<link rel="canonical" href="https://gpswiss.fr/produkt/'.$other->slug.'">', false);
        $this->post('https://gpswiss.fr/koszyk/dodaj/'.$part->id)->assertRedirect()->assertSessionHas('success');
        $this->assertSame('493.80', $part->fresh()->price);
        Http::assertNothingSent();
    }

    public function test_local_ebay_de_is_ignored_without_writing_source(): void
    {
        $part = $this->part('PLN', 20000);
        $listing = MarketplaceListing::create(['part_id' => $part->id, 'marketplace' => 'ebay_de', 'match_status' => 'matched',
            'external_offer_id' => 'eur-fr', 'price' => 321.99, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'active']);
        $original = $listing->fresh()->getRawOriginal();
        $partOriginal = $part->fresh()->getRawOriginal();
        foreach (['/czesci', '/produkt/'.$part->slug] as $path) {
            $this->get('https://gpswiss.fr'.$path)->assertOk()->assertSee('5 000,00 EUR')->assertDontSee('321,99 EUR')
                ->assertDontSee('20 000,00')->assertDontSee('PLN');
            $this->withSession(['locale' => 'fr'])->get('https://gpswiss.pl'.$path)->assertOk()->assertSee('20 000,00 PLN');
        }
        $this->assertSame($original, $listing->fresh()->getRawOriginal());
        $this->assertSame($partOriginal, $part->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_missing_nbp_never_displays_pln_or_marketplace_prices_and_blocks_purchase(): void
    {
        Cache::forget('nbp_table_a_rate:EUR:latest');
        Http::fake(['api.nbp.pl/*' => Http::response([], 503)]);
        $part = $this->part('PLN', 27000);
        foreach ([false, true] as $ambiguous) {
            if ($ambiguous) {
                foreach ([100, 200] as $index => $price) {
                    MarketplaceListing::create(['part_id' => $part->id, 'marketplace' => 'ebay_de', 'match_status' => 'matched',
                        'external_offer_id' => 'ambiguous-'.$index, 'price' => $price, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'active']);
                }
            }
            $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee('Prix indisponible')
                ->assertDontSee('27 000,00')->assertDontSee('PLN')->assertDontSee('class="sf-purchase__cart"', false);
            $this->post('https://gpswiss.fr/koszyk/dodaj/'.$part->id)->assertRedirect()->assertSessionHas('warning');
            $this->assertEmpty(session('storefront.cart.items', []));
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(2, $part->fresh()->quantity);
        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), 'api.nbp.pl'));
    }

    public function test_missing_or_blocked_delivery_does_not_promise_shipping_or_enable_purchase(): void
    {
        $part = $this->part('PLN', 400);
        $mapping = MarketplaceCategoryMapping::where('local_category_id', $part->category_id)->first();
        $mapping->update(['is_blocked' => true]);
        foreach (['/czesci', '/produkt/'.$part->slug] as $path) {
            $this->get('https://gpswiss.fr'.$path)->assertOk()->assertSee('100,00 EUR')
                ->assertSee('Livraison indisponible')->assertDontSee('Frais de livraison calculés au panier')
                ->assertDontSee('Livraison gratuite')->assertDontSee('13 h 30');
        }
        $mapping->delete();
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee('Livraison indisponible')
            ->assertDontSee('class="sf-purchase__cart"', false);
        $this->post('https://gpswiss.fr/koszyk/dodaj/'.$part->id)->assertRedirect()->assertSessionHas('warning');
        $this->assertEmpty(session('storefront.cart.items', []));
    }

    public function test_polish_prices_shipping_and_add_to_cart_remain_unchanged(): void
    {
        $part = $this->part('PLN', 2000);
        $this->get('https://gpswiss.pl/czesci')->assertOk()->assertSee('2 000,00 PLN')
            ->assertSee('Darmowa dostawa: najbliższy dzień roboczy')->assertSee('13:30');
        $this->get('https://gpswiss.pl/produkt/'.$part->slug)->assertOk()->assertSee('2 000,00 PLN')
            ->assertSee('class="sf-purchase__cart"', false);
        $this->post('https://gpswiss.pl/koszyk/dodaj/'.$part->id)->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, $part->fresh()->quantity);
    }

    private function part(string $currency, float $price): Part
    {
        $category = PartCategory::firstOrCreate(['slug' => 'kompletne-silniki'], ['name' => 'Silniki',
            'full_slug_path' => 'silnik-i-osprzet/silniki-i-osprzet/kompletne-silniki', 'is_visible' => true]);
        MarketplaceCategoryMapping::firstOrCreate(['local_category_id' => $category->id, 'channel' => 'ebay_fr'],
            ['shipping_group' => 'fr_55_eur', 'fulfillment_policy_id' => '260547694013']);

        return Part::create(['name' => 'Moteur test '.Part::count(), 'category_id' => $category->id,
            'currency' => $currency, 'price' => $price, 'quantity' => 2, 'status' => 'ready']);
    }
}
