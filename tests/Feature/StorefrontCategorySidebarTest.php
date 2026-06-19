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
        $response->assertSee('−</a>', false);
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
        ]);

        PartCategory::query()->create([
            'parent_id' => $root->id,
            'name' => 'Kompletne silniki',
            'slug' => 'kompletne-silniki',
            'full_slug_path' => 'silnik-i-osprzet/kompletne-silniki',
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
}
