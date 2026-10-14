<?php

namespace Tests\Feature;

use App\Models\JarekFrProduct;
use App\Models\JarekGearbox;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Storefront\FrenchMixedCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ResolvesFrenchCategoryUrls;
use Tests\TestCase;

class JarekUnifiedFrenchCatalogTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesFrenchCategoryUrls;

    private PartCategory $leaf;

    private array $categories;

    private JarekFrProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['frontend-maintenance.enabled' => false]);
        Http::preventStrayRequests();
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A',
            'rate' => 4, 'effective_date' => today()->toDateString(), 'table_no' => 'fixture'], 3600);
        $root = PartCategory::create(['name' => 'Układ napędowy', 'slug' => 'uklad-napedowy', 'full_slug_path' => 'uklad-napedowy', 'source_system' => 'woo', 'is_visible' => true]);
        $branch = PartCategory::create(['name' => 'Skrzynie biegów i inne elementy', 'slug' => 'skrzynie-biegow-i-inne-elementy', 'full_slug_path' => $root->slug.'/skrzynie-biegow-i-inne-elementy', 'parent_id' => $root->id, 'source_system' => 'woo', 'is_visible' => true]);
        $this->leaf = PartCategory::create(['name' => 'Manualna 6-biegowa skrzynia biegów', 'slug' => 'manualna-6-biegowa-skrzynia-biegow', 'full_slug_path' => FrenchMixedCatalog::CATEGORY_PATH, 'parent_id' => $branch->id, 'source_system' => 'woo', 'is_visible' => true]);
        $this->categories = [$root, $branch, $this->leaf];
        foreach ($this->categories as $i => $category) {
            $category->translations()->create(['locale' => 'fr', 'name' => ['Transmission', 'Boîtes de vitesses et accessoires', 'Boîte manuelle à 6 rapports'][$i], 'status' => 'reviewed']);
        }
        $this->product = $this->supplier();
    }

    public function test_fr_category_tree_includes_supplier_only_branch_even_after_pl_warmed_cache(): void
    {
        $this->get('https://gpswiss.pl/czesci')->assertOk()->assertDontSee('Supplier gearbox');
        $response = $this->get($this->categoryUrl())->assertOk()->assertSee('Supplier gearbox')
            ->assertSee('Transmission')->assertSee('Boîte manuelle à 6 rapports')->assertSee('sf-product-card', false)
            ->assertSee('312,50 EUR')->assertSee('130,00 EUR')->assertDontSee('Boîtes de vitesses — Jarek')->assertDontSee('hreflang="pl"', false);
        $this->assertSame(1, $response->viewData('parts')->total());
        $this->get('https://gpswiss.fr/czesci')->assertOk()->assertSee('Supplier gearbox')->assertDontSee('Układ napędowy');
        $this->get($this->frenchCategoryUrl($this->categories[0]))->assertOk()->assertSee('Supplier gearbox');
        $this->get('https://gpswiss.pl/boites-jarek/'.$this->product->id)->assertNotFound();
        $this->get('https://gpswiss.fr/sitemaps/pages.xml')->assertOk()->assertSee($this->frenchCategoryUrl($this->leaf));
        Http::assertNothingSent();
    }

    public function test_mixed_grid_has_one_total_and_one_page_sequence(): void
    {
        $this->ordinary();
        $this->get($this->categoryUrl())->assertOk()->assertSee('hreflang="pl"', false);
        $this->get('https://gpswiss.pl/kategoria-produktu/'.FrenchMixedCatalog::CATEGORY_PATH)->assertOk()->assertSee('Ordinary gearbox')->assertDontSee('Supplier gearbox');
        for ($i = 1; $i <= 60; $i++) {
            $this->supplier(['title' => 'Supplier box '.$i]);
        }
        $first = $this->get($this->categoryUrl().'?sort=price_asc')->assertOk()->assertSee('Ordinary gearbox')->assertSee('sf-grid sf-grid--3', false);
        $second = $this->get($this->categoryUrl().'?sort=price_asc&page=2')->assertOk()->assertSee('61–62 sur 62 résultats');
        $this->assertSame(62, $first->viewData('parts')->total());
        $keys = fn ($r) => $r->viewData('parts')->getCollection()->map(fn ($p) => (string) $p->id)->all();
        $this->assertCount(60, $keys($first));
        $this->assertCount(2, $keys($second));
        $this->assertSame([], array_intersect($keys($first), $keys($second)));
        $this->get('https://gpswiss.pl/czesci')->assertOk()->assertSee('Ordinary gearbox')->assertDontSee('Supplier gearbox');
        Http::assertNothingSent();
    }

    public function test_home_and_legacy_collection_have_no_separate_supplier_section(): void
    {
        $this->get('https://gpswiss.fr/')->assertOk()->assertDontSee('Boîtes de vitesses — Jarek')->assertSee('Supplier gearbox');
        $this->get('https://gpswiss.fr/boites-jarek')->assertStatus(301)->assertRedirect($this->frenchCategoryUrl($this->leaf));
        $this->get('https://gpswiss.pl/boites-jarek')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_product_uses_shared_gallery_purchase_panel_breadcrumbs_and_original_cart_route(): void
    {
        $before = $this->product->gearbox->fresh()->getAttributes();
        $response = $this->get('https://gpswiss.fr/boites-jarek/'.$this->product->id)->assertOk()->assertViewIs('storefront.parts.show');
        foreach (['gp-product-page', 'sf-product-detail', 'sf-gallery__layout', 'data-gallery-thumb', 'data-gallery-lightbox', 'sf-info-card', 'sf-purchase', 'sf-purchase__cart', '/boites-jarek/'.$this->product->id.'/panier'] as $text) {
            $response->assertSee($text, false);
        }
        foreach (['Transmission', 'Boîtes de vitesses et accessoires', 'Boîte manuelle à 6 rapports', '312,50 EUR', '130,00 EUR'] as $text) {
            $response->assertSee($text);
        }
        $response->assertDontSee('PLN')->assertDontSee('Prix brut. Le prix le plus bas des 30 derniers jours est disponible lors de la commande.')->assertDontSee('hreflang="pl"', false);
        $this->post('https://gpswiss.fr/boites-jarek/'.$this->product->id.'/panier', ['quantity' => 1])->assertRedirect();
        $this->get('https://gpswiss.fr/panier')->assertOk()->assertSee('312,50 EUR')->assertSee('130,00 EUR');
        $this->assertSame($before, $this->product->gearbox->fresh()->getAttributes());
        $this->assertDatabaseCount('parts', 0);
        $this->get('https://gpswiss.fr/sitemaps/jarek-1.xml')->assertOk()->assertSee('/boites-jarek/'.$this->product->id);
        Http::assertNothingSent();
    }

    public function test_card_and_gallery_prefer_existing_local_images_without_remote_fetch(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('jarek-gearboxes/local-offer/01.jpg', 'fixture');
        Storage::disk('public')->put('jarek-gearboxes/local-offer/02.jpg', 'fixture');
        $this->product->gearbox->update(['allegro_offer_id' => 'local-offer']);
        $this->get($this->categoryUrl())->assertOk()->assertSee('https://gpswiss.pl/storage/jarek-gearboxes/local-offer/01.jpg', false)->assertDontSee('remote.test');
        $this->get('https://gpswiss.fr/boites-jarek/'.$this->product->id)->assertOk()->assertSee('local-offer/02.jpg', false)->assertSee('data-gallery-thumb', false)->assertDontSee('remote.test');
        Http::assertNothingSent();
    }

    public function test_search_uses_ready_supplier_translation_and_filters_exclude_unmapped_vehicle_data(): void
    {
        $this->product->gearbox->translations()->create(['locale' => 'fr', 'title' => 'Boîte Peugeot spéciale', 'description' => 'Description française', 'status' => 'translated']);
        $this->get('https://gpswiss.fr/czesci?q=Peugeot')->assertOk()->assertSee('Boîte Peugeot spéciale');
        $this->get($this->categoryUrl().'?q=Peugeot')->assertOk()->assertSee('Boîte Peugeot spéciale');
        $this->get('https://gpswiss.fr/czesci?category=Boîte%20manuelle')->assertOk()->assertSee('Boîte Peugeot spéciale');
        foreach (['producer=BMW', 'model=A4', 'vehicle_model=Audi', 'price_min=2000', 'q=unrelated'] as $filter) {
            $this->get($this->categoryUrl().'?'.$filter)->assertOk()->assertDontSee('Boîte Peugeot spéciale');
        }
        $this->get('https://gpswiss.fr/czesci?category=unrelated')->assertOk()->assertDontSee('Boîte Peugeot spéciale');
        Http::assertNothingSent();
    }

    public function test_inactive_supplier_and_other_categories_do_not_leak_into_grid(): void
    {
        PartCategory::create(['name' => 'Autres', 'slug' => 'autres', 'full_slug_path' => 'autres', 'woo_product_count' => 1, 'is_visible' => true]);
        $this->get('https://gpswiss.fr/kategoria-produktu/autres')->assertOk()->assertDontSee('Supplier gearbox');
        $this->product->update(['is_active' => false]);
        $this->get('https://gpswiss.fr/czesci')->assertOk()->assertDontSee('Supplier gearbox');
        $this->get('https://gpswiss.fr/boites-jarek/'.$this->product->id)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_price_sort_and_bounds_use_displayed_eur_for_both_sources(): void
    {
        $this->ordinary()->update(['price' => 1000]);
        $response = $this->get($this->categoryUrl().'?sort=price_asc')->assertOk();
        $this->assertSame(['jarek-'.$this->product->id, '1'], $response->viewData('parts')->getCollection()->map(fn ($p) => (string) $p->id)->all());
        $this->get($this->categoryUrl().'?price_min=300&price_max=400')->assertOk()->assertSee('Supplier gearbox')->assertDontSee('Ordinary gearbox');
        $this->get($this->categoryUrl().'?price_min=900')->assertOk()->assertSee('Ordinary gearbox')->assertDontSee('Supplier gearbox');
        Http::assertNothingSent();
    }

    public function test_mixed_paging_respects_existing_local_ebay_eur_priority_and_conflict_block(): void
    {
        $part = $this->ordinary();
        $part->update(['currency' => 'PLN', 'price' => 4000]);
        MarketplaceListing::create(['part_id' => $part->id, 'marketplace' => 'ebay_de', 'match_status' => 'matched', 'price' => 50, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'active']);
        $response = $this->get($this->categoryUrl().'?price_max=100')->assertOk()->assertSee('Ordinary gearbox')->assertSee('50,00 EUR')->assertDontSee('Supplier gearbox');
        $this->assertSame(1, $response->viewData('parts')->total());
        MarketplaceListing::create(['part_id' => $part->id, 'marketplace' => 'ebay_de', 'match_status' => 'matched', 'price' => 60, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'active']);
        $this->get($this->categoryUrl().'?price_max=100')->assertOk()->assertDontSee('Ordinary gearbox');
        Http::assertNothingSent();
    }

    public function test_name_sort_uses_ready_french_names_for_both_sources(): void
    {
        $part = $this->ordinary();
        $part->translations()->create(['locale' => 'fr', 'name' => 'Zzz pièce française', 'status' => 'reviewed']);
        $this->product->gearbox->translations()->create(['locale' => 'fr', 'title' => 'Aaa boîte française', 'status' => 'reviewed']);
        $response = $this->get($this->categoryUrl().'?sort=name')->assertOk();
        $this->assertSame('jarek-'.$this->product->id, $response->viewData('parts')->first()->id);
        $response->assertSee('Aaa boîte française')->assertSee('Zzz pièce française')->assertDontSee('Ordinary gearbox');
        Http::assertNothingSent();
    }

    public function test_missing_target_category_does_not_invent_a_supplier_category_or_create_parts(): void
    {
        $this->leaf->update(['is_visible' => false]);
        $this->get('https://gpswiss.fr/czesci')->assertOk()->assertDontSee('Supplier gearbox');
        $this->get($this->categoryUrl())->assertNotFound();
        $this->assertDatabaseCount('part_categories', 3);
        $this->assertDatabaseCount('parts', 0);
        Http::assertNothingSent();
    }

    private function supplier(array $attributes = []): JarekFrProduct
    {
        $gearbox = JarekGearbox::create($attributes + ['allegro_offer_id' => 'offer-'.JarekGearbox::count(), 'title' => 'Supplier gearbox', 'price' => 1000, 'currency' => 'PLN', 'quantity' => 0, 'images' => ['https://remote.test/one.jpg', 'https://remote.test/two.jpg'], 'plain_description' => 'Supplier description']);

        return JarekFrProduct::create(['jarek_gearbox_id' => $gearbox->id, 'is_active' => true, 'published_at' => now()]);
    }

    private function ordinary(): Part
    {
        return Part::create(['name' => 'Ordinary gearbox', 'sku' => 'NORMAL', 'slug' => 'ordinary', 'price' => 100, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'ready', 'category_id' => $this->leaf->id]);
    }

    private function categoryUrl(): string
    {
        return $this->frenchCategoryUrl($this->leaf);
    }
}
