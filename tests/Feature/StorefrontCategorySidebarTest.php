<?php

namespace Tests\Feature;

use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StorefrontCategorySidebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_page_uses_contextual_sidebar_without_subcategory_tiles(): void
    {
        Cache::flush();

        $root = PartCategory::query()->create([
            'name' => 'Silnik i osprzęt',
            'slug' => 'silnik-i-osprzet',
            'full_slug_path' => 'silnik-i-osprzet',
        ]);

        $sibling = PartCategory::query()->create([
            'parent_id' => $root->id,
            'name' => 'Osprzęt silnika',
            'slug' => 'osprzet-silnika',
            'full_slug_path' => 'silnik-i-osprzet/osprzet-silnika',
            'woo_product_count' => 3,
        ]);

        $category = PartCategory::query()->create([
            'parent_id' => $root->id,
            'name' => 'Kompletne silniki',
            'slug' => 'kompletne-silniki',
            'full_slug_path' => 'silnik-i-osprzet/kompletne-silniki',
            'woo_product_count' => 7,
        ]);

        PartCategory::query()->create([
            'parent_id' => $category->id,
            'name' => 'Silniki benzynowe',
            'slug' => 'silniki-benzynowe',
            'full_slug_path' => 'silnik-i-osprzet/kompletne-silniki/silniki-benzynowe',
            'woo_product_count' => 2,
        ]);

        $unrelatedRoot = PartCategory::query()->create([
            'name' => 'Karoseria',
            'slug' => 'karoseria',
            'full_slug_path' => 'karoseria',
        ]);

        PartCategory::query()->create([
            'parent_id' => $unrelatedRoot->id,
            'name' => 'Drzwi',
            'slug' => 'drzwi',
            'full_slug_path' => 'karoseria/drzwi',
        ]);

        $response = $this->get(route('storefront.category', ['path' => 'silnik-i-osprzet/kompletne-silniki']));

        $response->assertOk();
        $response->assertDontSee('sf-subcategory-tiles', false);
        $response->assertSee('<h3>Kategoria</h3>', false);
        $response->assertSee('sf-category-sidebar__section-title', false);
        $response->assertSee('<option value="http://localhost/kategoria-produktu/silnik-i-osprzet" selected>', false);
        $response->assertSee('Kompletne silniki');
        $response->assertSee('Silniki benzynowe');
        $response->assertSee('Osprzęt silnika');
        $response->assertSee('−</button>', false);
        $response->assertDontSee('Karoseria');
        $response->assertDontSee('Drzwi');
    }

    public function test_leaf_category_sidebar_falls_back_to_siblings_only(): void
    {
        Cache::flush();

        $root = PartCategory::query()->create([
            'name' => 'Silnik i osprzęt',
            'slug' => 'silnik-i-osprzet',
            'full_slug_path' => 'silnik-i-osprzet',
        ]);

        PartCategory::query()->create([
            'parent_id' => $root->id,
            'name' => 'Osprzęt silnika',
            'slug' => 'osprzet-silnika',
            'full_slug_path' => 'silnik-i-osprzet/osprzet-silnika',
            'woo_product_count' => 1,
        ]);

        PartCategory::query()->create([
            'parent_id' => $root->id,
            'name' => 'Kompletne silniki',
            'slug' => 'kompletne-silniki',
            'full_slug_path' => 'silnik-i-osprzet/kompletne-silniki',
            'woo_product_count' => 1,
        ]);

        PartCategory::query()->create([
            'name' => 'Karoseria',
            'slug' => 'karoseria',
            'full_slug_path' => 'karoseria',
        ]);

        $response = $this->get(route('storefront.category', ['path' => 'silnik-i-osprzet/kompletne-silniki']));

        $response->assertOk();
        $response->assertSee('Kompletne silniki');
        $response->assertSee('Osprzęt silnika');
        $response->assertDontSee('Karoseria');
    }

    public function test_sidebar_highlights_active_path_and_hides_empty_branches(): void
    {
        Cache::flush();

        $root = PartCategory::query()->create([
            'name' => 'Układ silnika',
            'slug' => 'uklad-silnika',
            'full_slug_path' => 'uklad-silnika',
        ]);

        $parent = PartCategory::query()->create([
            'parent_id' => $root->id,
            'name' => 'Silniki i osprzęt',
            'slug' => 'silniki-i-osprzet',
            'full_slug_path' => 'uklad-silnika/silniki-i-osprzet',
        ]);

        PartCategory::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Czujnik spalania stukowego',
            'slug' => 'czujnik-spalania-stukowego',
            'full_slug_path' => 'uklad-silnika/silniki-i-osprzet/czujnik-spalania-stukowego',
            'woo_product_count' => 5,
        ]);

        PartCategory::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Pusta trzecia kategoria',
            'slug' => 'pusta-trzecia-kategoria',
            'full_slug_path' => 'uklad-silnika/silniki-i-osprzet/pusta-trzecia-kategoria',
        ]);

        PartCategory::query()->create([
            'parent_id' => $root->id,
            'name' => 'Pusta podkategoria',
            'slug' => 'pusta-podkategoria',
            'full_slug_path' => 'uklad-silnika/pusta-podkategoria',
        ]);

        PartCategory::query()->create([
            'name' => 'Pusty korzeń',
            'slug' => 'pusty-korzen',
            'full_slug_path' => 'pusty-korzen',
        ]);

        $response = $this->get(route('storefront.category', ['path' => 'uklad-silnika/silniki-i-osprzet/czujnik-spalania-stukowego']));

        $response->assertOk();
        $response->assertSee('Silniki i osprzęt');
        $response->assertSee('Czujnik spalania stukowego');
        $response->assertSee('is-ancestor', false);
        $response->assertSee('is-active', false);
        $response->assertSee('−</button>', false);
        $response->assertDontSee('Pusta trzecia kategoria');
        $response->assertDontSee('Pusta podkategoria');
        $response->assertDontSee('Pusty korzeń');
    }

    public function test_category_public_labels_hide_imported_path_suffixes(): void
    {
        Cache::flush();

        $root = PartCategory::query()->create([
            'name' => 'Drzwi i inne elementy',
            'slug' => 'drzwi-i-inne-elementy',
            'full_slug_path' => 'drzwi-i-inne-elementy',
        ]);

        $category = PartCategory::query()->create([
            'parent_id' => $root->id,
            'name' => 'Zamek drzwi przednich — Drzwi i inne elementy > Zamek drzwi przednich',
            'slug' => 'zamek-drzwi-przednich',
            'full_slug_path' => 'drzwi-i-inne-elementy/zamek-drzwi-przednich',
            'category_path' => 'Drzwi i inne elementy > Zamek drzwi przednich',
            'woo_product_count' => 2,
        ]);

        $response = $this->get(route('storefront.category', ['path' => $category->full_slug_path]));

        $response->assertOk();
        $response->assertSee('<h1 id="category-title">Zamek drzwi przednich</h1>', false);
        $response->assertSee('>Zamek drzwi przednich</span>', false);
        $response->assertDontSee('Zamek drzwi przednich — Drzwi i inne elementy');
    }

}
