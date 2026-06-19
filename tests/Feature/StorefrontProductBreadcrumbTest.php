<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StorefrontProductBreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_breadcrumb_uses_category_path_without_shop(): void
    {
        Cache::flush();

        $parent = PartCategory::query()->create([
            'name' => 'Silnik i osprzęt',
            'slug' => 'silnik-i-osprzet',
            'full_slug_path' => 'silnik-i-osprzet',
        ]);

        $category = PartCategory::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Kompletne silniki',
            'slug' => 'kompletne-silniki',
            'full_slug_path' => 'silnik-i-osprzet/kompletne-silniki',
        ]);

        $part = Part::query()->create([
            'name' => 'Testowy silnik Maserati',
            'slug' => 'testowy-silnik-maserati',
            'category_id' => $category->id,
            'status' => 'published',
            'is_visible_storefront' => true,
        ]);

        $response = $this->get(route('storefront.product', $part->slug));

        $response->assertOk();
        $response->assertSeeInOrder([
            'Strona główna',
            'Silnik i osprzęt',
            'Kompletne silniki',
            'Testowy silnik Maserati',
        ]);
        $response->assertDontSee('Sklep');
        $response->assertSee('/kategoria-produktu/silnik-i-osprzet', false);
        $response->assertSee('/kategoria-produktu/silnik-i-osprzet/kompletne-silniki', false);
    }

    public function test_product_breadcrumb_falls_back_to_home_and_product_without_category(): void
    {
        $part = Part::query()->create([
            'name' => 'Produkt bez kategorii',
            'slug' => 'produkt-bez-kategorii',
            'status' => 'published',
            'is_visible_storefront' => true,
        ]);

        $response = $this->get(route('storefront.product', $part->slug));

        $response->assertOk();
        $response->assertSeeInOrder([
            'Strona główna',
            'Produkt bez kategorii',
        ]);
        $response->assertDontSee('Sklep');
    }
}
