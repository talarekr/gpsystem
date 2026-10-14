<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Storefront\FrenchCatalogTranslationPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ResolvesFrenchCategoryUrls;
use Tests\TestCase;

class FrenchCatalogUiLabelsTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesFrenchCategoryUrls;

    protected function setUp(): void
    {
        parent::setUp();
        config(['frontend-maintenance.enabled' => false]);
        Http::preventStrayRequests();
    }

    public static function readyStatuses(): array
    {
        return [['translated'], ['reviewed']];
    }

    #[DataProvider('readyStatuses')]
    public function test_fr_menu_category_breadcrumbs_sidebar_and_metadata_use_ready_translations(string $status): void
    {
        [$root, $branch, $leaf] = $this->categories();
        foreach ([$root, $branch, $leaf] as $index => $category) {
            $this->translate($category, $status, ['Moteurs et accessoires', 'Équipements moteur', 'Moteurs complets'][$index]);
        }
        $this->withSession(['locale' => 'pl'])->get('https://gpswiss.fr/czesci')->assertOk()
            ->assertSee('Moteurs et accessoires')->assertSee('Équipements moteur')->assertSee('Moteurs complets')
            ->assertSee('Catégorie sélectionnée')->assertSee('Voir toutes les pièces')
            ->assertDontSee('Wybrana kategoria')->assertDontSee('Zobacz wszystkie')
            ->assertDontSee('Silniki i osprzęt')->assertDontSee('Kompletne silniki');
        $response = $this->get($this->categoryUrl('fr', $leaf))->assertOk()
            ->assertSee('<h1 id="category-title">Moteurs complets</h1>', false)
            ->assertSee('Description française des moteurs')
            ->assertSee('Catégorie')->assertSee('Sous-catégories')->assertSee('Fil d’Ariane')
            ->assertDontSee('Silniki i osprzęt')->assertDontSee('Kompletne silniki')->assertDontSee('Opis kategorii PL');
        $this->assertSame('Moteurs complets - GPSwiss', $response->viewData('metaTitle'));
        $this->assertSame('Description française des moteurs', $response->viewData('metaDescription'));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $breadcrumb = (new \DOMXPath($dom))->query('//nav[contains(@class, "sf-breadcrumbs")]')->item(0)->textContent;
        foreach (['Accueil', 'Moteurs et accessoires', 'Équipements moteur', 'Moteurs complets'] as $label) {
            $this->assertStringContainsString($label, $breadcrumb);
        }
        Http::assertNothingSent();
    }

    public static function fallbackStatuses(): array
    {
        return [[null], ['missing'], ['queued'], ['failed'], ['needs_update']];
    }

    #[DataProvider('fallbackStatuses')]
    public function test_fr_category_names_fall_back_only_without_a_ready_nonempty_name(?string $status): void
    {
        [, , $leaf] = $this->categories();
        if ($status !== null) {
            $this->translate($leaf, $status, 'Nom français non public');
        }
        $this->get($this->categoryUrl('fr', $leaf))->assertOk()->assertSee('Kompletne silniki')->assertDontSee('Nom français non public');
        Http::assertNothingSent();
    }

    public function test_blank_ready_fields_fall_back_individually(): void
    {
        [, , $leaf] = $this->categories();
        $translation = $this->translate($leaf, 'reviewed', ' ');
        $this->get($this->categoryUrl('fr', $leaf))->assertOk()->assertSee('Kompletne silniki')->assertSee('Description française des moteurs');
        $translation->update(['name' => 'Moteurs complets', 'description' => null]);
        $response = $this->get($this->categoryUrl('fr', $leaf))->assertOk()->assertSee('Moteurs complets')->assertSee('Opis kategorii PL');
        $this->assertSame('Moteurs complets - GPSwiss', $response->viewData('metaTitle'));
        Http::assertNothingSent();
    }

    public function test_ready_translations_are_refreshed_after_pl_warms_the_shared_tree_cache(): void
    {
        [$root, $branch, $leaf] = $this->categories();
        $this->get($this->categoryUrl('pl', $leaf))->assertOk()->assertSee('Kompletne silniki');
        foreach ([$root, $branch, $leaf] as $index => $category) {
            $this->translate($category, 'reviewed', ['Moteurs et accessoires', 'Équipements moteur', 'Moteurs complets'][$index]);
        }
        $this->get($this->categoryUrl('fr', $leaf))->assertOk()->assertSee('Moteurs complets')->assertDontSee('Kompletne silniki');
        $leaf->translations()->update(['name' => 'Moteurs complets actualisés']);
        $this->get($this->categoryUrl('fr', $leaf))->assertOk()->assertSee('Moteurs complets actualisés');
        $this->get($this->categoryUrl('pl', $leaf))->assertOk()->assertSee('Kompletne silniki')->assertDontSee('Moteurs complets actualisés');
        Http::assertNothingSent();
    }

    public function test_fr_filters_and_empty_states_are_localized_and_pl_literals_are_preserved(): void
    {
        [, , $leaf] = $this->categories();
        $this->translate($leaf, 'reviewed', 'Moteurs complets');
        $response = $this->get($this->categoryUrl('fr', $leaf))->assertOk();
        foreach (['Filtres', 'Fabricant', 'Modèle', 'Prix minimum', 'Prix maximum', 'Tous', 'Filtrer', 'Effacer les filtres', 'Aucun produit dans cette catégorie.'] as $label) {
            $response->assertSee($label);
        }
        foreach (['Wszyscy', 'Wszystkie', 'Cena minimum', 'Cena maximum', 'Filtruj', 'Producent', 'Podkategorie'] as $label) {
            $response->assertDontSee($label);
        }
        foreach (['pl', 'fr'] as $locale) {
            $pl = $this->withSession(['locale' => $locale])->get($this->categoryUrl('pl', $leaf))->assertOk();
            foreach (['Filtry', 'Producent', 'Model', 'Wszyscy', 'Wszystkie', 'Filtruj', 'Wybrana kategoria', 'Zobacz wszystkie', 'Podkategorie'] as $label) {
                $pl->assertSee($label);
            }
        }
        Http::assertNothingSent();
    }

    public function test_gross_price_note_is_absent_from_fr_product_html_and_js_but_retained_on_pl(): void
    {
        $part = Part::create(['name' => 'Moteur Audi', 'slug' => 'ui-moteur', 'sku' => 'UI-NOTE', 'price' => 100, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'ready']);
        $note = 'Prix brut. Le prix le plus bas des 30 derniers jours est disponible lors de la commande.';
        $this->withSession(['locale' => 'pl'])->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertDontSee($note)->assertDontSee('gross_price_note');
        $this->withSession(['locale' => 'pl'])->get('https://gpswiss.pl/produkt/'.$part->slug)->assertOk()->assertSee(__('storefront.gross_price_note', [], 'pl'))->assertSee('gross_price_note');
        Http::assertNothingSent();
    }

    public function test_fr_pagination_is_localized_and_preserves_category_and_search_links(): void
    {
        [, , $leaf] = $this->categories();
        $this->translate($leaf, 'reviewed', 'Moteurs complets');
        for ($i = 1; $i <= 61; $i++) {
            Part::create(['name' => 'Moteur UI '.$i, 'sku' => 'UI-PAGE-'.$i, 'price' => 100, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'ready', 'category_id' => $leaf->id]);
        }
        $this->get('https://gpswiss.fr/czesci?q=Moteur&sort=price_asc')->assertOk()->assertSee('Page suivante')->assertSee('Précédent')->assertSee('Suivant')->assertSee('1–60 sur 61 résultats')->assertSee('Aller à la page 2')->assertDontSee('Showing')->assertDontSee('pagination.next');
        $this->get($this->categoryUrl('fr', $leaf).'?page=2')->assertOk()->assertSee('Page précédente')->assertSee('61–61 sur 61 résultats');
        $this->withSession(['locale' => 'pl'])->get($this->categoryUrl('pl', $leaf))->assertOk()->assertDontSee('Page suivante')->assertDontSee('1–60 sur 61 résultats');
        Http::assertNothingSent();
    }

    public function test_existing_category_translation_preview_covers_public_tree_records_without_google(): void
    {
        $this->categories();
        $preview = app(FrenchCatalogTranslationPreviewService::class)->dryRun(1);
        $this->assertSame(3, $preview['categories']['total']);
        $this->assertSame(3, $preview['categories']['eligible']);
        $this->assertSame(3, $preview['inventory']['missing']);
        $this->assertDatabaseCount('category_translations', 0);
        Http::assertNothingSent();
    }

    private function categories(): array
    {
        $root = PartCategory::create(['name' => 'Silniki i osprzęt', 'slug' => 'silnik-i-osprzet', 'full_slug_path' => 'silnik-i-osprzet', 'source_system' => 'woo', 'is_visible' => true, 'woo_product_count' => 1]);
        $branch = PartCategory::create(['name' => 'Silniki i osprzęt — Silniki i osprzęt', 'category_path' => 'Silniki i osprzęt', 'slug' => 'silniki-i-osprzet', 'full_slug_path' => $root->slug.'/silniki-i-osprzet', 'parent_id' => $root->id, 'source_system' => 'woo', 'is_visible' => true, 'woo_product_count' => 1]);
        $leaf = PartCategory::create(['name' => 'Kompletne silniki', 'description' => 'Opis kategorii PL', 'slug' => 'kompletne-silniki', 'full_slug_path' => $branch->full_slug_path.'/kompletne-silniki', 'parent_id' => $branch->id, 'source_system' => 'woo', 'is_visible' => true, 'woo_product_count' => 1]);

        return [$root, $branch, $leaf];
    }

    private function translate(PartCategory $category, string $status, string $name)
    {
        return $category->translations()->create(['locale' => 'fr', 'status' => $status, 'name' => $name, 'description' => 'Description française des moteurs', 'source_hash' => $category->translationSourceHash()]);
    }

    private function categoryUrl(string $locale, PartCategory $category): string
    {
        return $locale === 'fr' ? $this->frenchCategoryUrl($category) : 'https://gpswiss.pl/kategoria-produktu/'.$category->full_slug_path;
    }
}
