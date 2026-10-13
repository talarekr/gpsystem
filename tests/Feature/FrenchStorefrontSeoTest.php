<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Order;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use App\Support\FrenchOrderPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FrenchStorefrontSeoTest extends TestCase
{
    use RefreshDatabase;

    private Part $part;

    private PartCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false, 'stripe-fr.enabled' => false]);
        $this->category = PartCategory::create(['name' => 'Silniki', 'slug' => 'silniki', 'full_slug_path' => 'silniki', 'is_visible' => true]);
        $this->category->translations()->create(['locale' => 'fr', 'status' => 'reviewed', 'name' => 'Moteurs', 'description' => 'Moteurs d’occasion']);
        $this->part = Part::create(['name' => 'Silnik Audi', 'slug' => 'silnik-audi', 'sku' => 'SEO-123',
            'category_id' => $this->category->id, 'status' => 'ready', 'quantity' => 1, 'price' => 100, 'currency' => 'EUR']);
        MarketplaceCategoryMapping::create(['local_category_id' => $this->category->id, 'channel' => 'ebay_fr',
            'shipping_group' => 'fr_55_eur', 'fulfillment_policy_id' => '260547694013']);
        $this->part->translations()->create(['locale' => 'fr', 'status' => 'translated', 'name' => 'Moteur Audi', 'short_description' => 'Moteur d’occasion Audi']);
    }

    public function test_public_pages_have_fr_language_canonical_and_real_reciprocal_alternates(): void
    {
        foreach (['/', '/czesci', '/produkt/'.$this->part->slug, '/kategoria-produktu/silniki', '/kontakt', '/regulamin', '/polityka-prywatnosci'] as $path) {
            $fr = $this->get('https://gpswiss.fr'.$path)->assertOk()->assertSee('lang="fr"', false);
            $fr->assertSee('<link rel="canonical" href="https://gpswiss.fr'.$path.'">', false)
                ->assertSee('hreflang="fr" href="https://gpswiss.fr'.$path.'"', false)
                ->assertSee('hreflang="pl" href="https://gpswiss.pl'.$path.'"', false)
                ->assertSee('hreflang="x-default" href="https://gpswiss.pl'.$path.'"', false);
            $this->get('https://gpswiss.pl'.$path)->assertOk()
                ->assertSee('hreflang="fr" href="https://gpswiss.fr'.$path.'"', false)
                ->assertSee('hreflang="pl" href="https://gpswiss.pl'.$path.'"', false);
        }
        $this->get('https://gpswiss.fr/czesci')->assertSee('Catalogue de pièces automobiles d’occasion — GPSwiss.fr');
        $this->get('https://gpswiss.pl/czesci')->assertSee('Katalog części GPSwiss - używane części samochodowe');
    }

    public function test_tracking_and_aliases_normalize_canonical_without_tracking_tokens(): void
    {
        $this->get('https://gpswiss.fr/produkt/'.$this->part->id.'?utm_source=test')->assertOk()
            ->assertSee('<link rel="canonical" href="https://gpswiss.fr/produkt/'.$this->part->slug.'">', false);
        $this->get('https://gpswiss.pl/produkt/'.$this->part->id)->assertOk()
            ->assertSee('<link rel="canonical" href="https://gpswiss.pl/produkt/'.$this->part->slug.'">', false)
            ->assertSee('hreflang="pl" href="https://gpswiss.pl/produkt/'.$this->part->slug.'"', false);
        $this->get('http://gpswiss.fr/czesci?utm_source=test')->assertOk()
            ->assertSee('<link rel="canonical" href="https://gpswiss.fr/czesci">', false);
        $this->get('https://www.gpswiss.fr/czesci')->assertRedirect('https://gpswiss.fr/czesci')->assertStatus(301);
        $this->get('https://gpswiss.fr/produkt/no-such-product')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, noarchive');
        $this->get('https://gpswiss.fr/no-such-page')->assertNotFound()->assertSee('lang="fr"', false)->assertSee('Page introuvable');
    }

    public function test_french_maintenance_is_localized_without_changing_polish_maintenance(): void
    {
        config(['frontend-maintenance.enabled' => true]);
        $this->get('https://gpswiss.fr/czesci')->assertStatus(503)->assertSee('lang="fr"', false)
            ->assertSee('Maintenance en cours')->assertSee('contact@gpswiss.fr')
            ->assertDontSee('Trwa przerwa techniczna')->assertHeader('X-Robots-Tag', 'noindex, noarchive');
        $this->get('https://gpswiss.pl/czesci')->assertStatus(503)->assertSee('Trwa przerwa techniczna');
        $this->get('https://gpswiss.fr/robots.txt')->assertOk();
    }

    public function test_french_html_server_errors_are_localized_and_do_not_expose_debug_details(): void
    {
        Route::middleware('web')->get('/_seo-error-probe', function () {
            throw new \RuntimeException('Sensitive internal detail');
        })->name('storefront.seo-error-probe');
        $this->get('https://gpswiss.fr/_seo-error-probe')->assertStatus(500)->assertSee('lang="fr"', false)
            ->assertSee('Une erreur est survenue')->assertDontSee('Sensitive internal detail')
            ->assertHeader('X-Robots-Tag', 'noindex, noarchive');
    }

    public function test_filters_have_no_fake_alternates_and_pagination_is_self_canonical(): void
    {
        $this->get('https://gpswiss.fr/czesci?q=Moteur')->assertOk()->assertSee('content="noindex, follow"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, follow')->assertDontSee('hreflang=')
            ->assertSee('<link rel="canonical" href="https://gpswiss.fr/czesci">', false);
        foreach (['gpswiss.fr', 'gpswiss.pl'] as $host) {
            $this->get('https://'.$host.'/czesci?page=2')->assertOk()
                ->assertSee('<link rel="canonical" href="https://'.$host.'/czesci?page=2">', false)
                ->assertSee('hreflang="fr" href="https://gpswiss.fr/czesci?page=2"', false)
                ->assertSee('hreflang="pl" href="https://gpswiss.pl/czesci?page=2"', false);
        }
    }

    public function test_private_pages_are_not_indexable_or_cacheable_and_have_no_hreflang(): void
    {
        foreach (['/panier', '/commande', '/logowanie', '/rejestracja', '/przypomnij-haslo'] as $path) {
            $response = $this->get('https://gpswiss.fr'.$path)->assertOk()
                ->assertSee('content="noindex, noarchive"', false)->assertDontSee('hreflang=')
                ->assertHeader('X-Robots-Tag', 'noindex, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $user = User::create(['name' => 'Client', 'email' => 'seo@example.test', 'password' => 'test-password']);
        $this->actingAs($user)->get('https://gpswiss.fr/moje-konto')->assertOk()
            ->assertSee('content="noindex, noarchive"', false)->assertDontSee('hreflang=');
        $order = Order::create(['order_number' => 'GPS-FR-SEO', 'status' => 'new', 'payment_status' => 'pending',
            'currency' => 'EUR', 'subtotal' => 100, 'shipping_total' => 0, 'total' => 100,
            'customer_name' => 'Client SEO', 'email' => 'seo@example.test', 'phone' => '+33123456789',
            'address_line1' => '7 rue Exemple', 'postal_code' => '75008', 'city' => 'Paris', 'country' => 'FR', 'meta' => ['storefront_code' => 'gpswiss_fr', 'locale' => 'fr', 'payment_provider' => 'stripe']]);
        $this->get(FrenchOrderPresentation::link($order))->assertOk()
            ->assertSee('content="noindex, noarchive"', false)->assertDontSee('hreflang=');
        auth()->logout();
        $this->get('https://gpswiss.fr/rejestracja')->assertOk()->assertDontSee('name="tax_id"', false);
        $this->get('https://gpswiss.pl/rejestracja')->assertOk()->assertSee('name="tax_id"', false);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_sitemap_index_and_children_are_valid_xml_and_contain_only_public_urls(): void
    {
        $hidden = Part::create(['name' => 'Pièce vendue', 'slug' => 'vendue', 'status' => 'sold', 'quantity' => 0, 'price' => 100]);
        PartCategory::create(['name' => 'Hidden', 'slug' => 'hidden-category', 'is_visible' => false, 'woo_product_count' => 999]);
        PartCategory::create(['name' => 'Empty', 'slug' => 'empty-category', 'woo_product_count' => 0]);
        $slugless = PartCategory::create(['name' => 'Slugless', 'slug' => null, 'full_slug_path' => null, 'woo_product_count' => 1]);
        $index = $this->get('https://gpswiss.fr/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $doc = simplexml_load_string($index->getContent());
        $this->assertNotFalse($doc);
        $children = $doc->children('http://www.sitemaps.org/schemas/sitemap/0.9');
        $this->assertCount(2, $children->sitemap);
        $xml = '';
        foreach ($children->sitemap as $sitemap) {
            $body = $this->get((string) $sitemap->loc)->assertOk()->getContent();
            $this->assertNotFalse(simplexml_load_string($body));
            $xml .= $body;
        }
        foreach (['https://gpswiss.fr/', 'https://gpswiss.fr/czesci', 'https://gpswiss.fr/kategoria-produktu/silniki',
            'https://gpswiss.fr/produkt/'.$this->part->slug] as $url) {
            $this->assertStringContainsString('<loc>'.$url.'</loc>', $xml);
        }
        foreach (['gpswiss.pl', '/panier', '/commande', '/moje-konto', 'signature=', '/zamowienie', '/koszyk',
            $hidden->slug, 'hidden-category', 'empty-category'] as $value) {
            $this->assertStringNotContainsString($value, $xml);
        }
        $this->assertStringNotContainsString('<loc>https://gpswiss.fr/kategoria-produktu/'.$slugless->id.'</loc>', $xml);
        $this->get('https://gpswiss.fr/sitemaps/products-0.xml')->assertNotFound();
        $this->get('https://gpswiss.fr/sitemaps/products-2.xml')->assertNotFound();
        // No PL route or static robots file is introduced by this PR.
        $this->get('https://gpswiss.pl/sitemap.xml')->assertNotFound();
    }

    public function test_sitemap_splits_public_products_without_duplicates(): void
    {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['name' => 'Sitemap fixture '.$i, 'slug' => 'sitemap-fixture-'.$i,
                'status' => 'ready', 'quantity' => 1, 'price' => 100, 'currency' => 'EUR',
                'needs_listing' => false, 'needs_review' => false, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('parts')->insert($chunk);
        }
        $this->get('https://gpswiss.fr/sitemap.xml')->assertOk()->assertSee('products-2.xml');
        $locs = [];
        foreach ([1 => 1000, 2 => 1] as $page => $expected) {
            $xml = simplexml_load_string($this->get('https://gpswiss.fr/sitemaps/products-'.$page.'.xml')->assertOk()->getContent());
            $urls = $xml->children('http://www.sitemaps.org/schemas/sitemap/0.9')->url;
            $this->assertCount($expected, $urls);
            foreach ($urls as $url) {
                $locs[] = (string) $url->loc;
            }
        }
        $this->assertCount(1001, array_unique($locs));
    }

    public function test_robots_advertises_fr_sitemap_and_does_not_disallow_catalog_or_products(): void
    {
        $response = $this->get('https://gpswiss.fr/robots.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Sitemap: https://gpswiss.fr/sitemap.xml');
        foreach (['/panier', '/commande', '/moje-konto', '/admin', '/tools', '/reset-hasla'] as $path) {
            $response->assertSee('Disallow: '.$path);
        }
        foreach (['/czesci', '/produkt', '/kategoria-produktu', '/sitemaps'] as $path) {
            $response->assertDontSee('Disallow: '.$path);
        }
        $this->get('https://www.gpswiss.fr/robots.txt')->assertRedirect('https://gpswiss.fr/robots.txt');
        $this->get('https://gpswiss.pl/robots.txt')->assertNotFound();
    }

    public function test_french_cart_messages_and_delivery_copy_preserve_pl(): void
    {
        $this->get('https://gpswiss.fr/czesci')->assertOk()
            ->assertSee('Frais de livraison : 55,00 EUR')->assertDontSee('Livraison gratuite : le prochain jour ouvré');
        $this->post('https://gpswiss.fr/koszyk/dodaj/'.$this->part->id)->assertRedirect()
            ->assertSessionHas('success', 'Article ajouté au panier.');
        $this->get('https://gpswiss.fr/panier')->assertOk()->assertSee('Article ajouté au panier.')->assertDontSee('Dodano produkt');
        $this->post('https://gpswiss.fr/koszyk/aktualizuj', ['quantities' => [$this->part->id => 0]])
            ->assertRedirect()->assertSessionHasErrors('quantities.'.$this->part->id);
        $message = session('errors')->first('quantities.'.$this->part->id);
        $this->assertStringContainsString('Le champ', $message);
        $this->post('https://gpswiss.fr/koszyk/wyczysc');
        $this->post('https://gpswiss.pl/koszyk/dodaj/'.$this->part->id)->assertRedirect()
            ->assertSessionHas('success', 'Dodano produkt do koszyka.');
        $this->assertSame(1, $this->part->fresh()->quantity);
    }
}
