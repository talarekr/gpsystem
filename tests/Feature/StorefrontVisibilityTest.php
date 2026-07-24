<?php

namespace Tests\Feature;

use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_visible_scope_does_not_require_visibility_flag(): void
    {
        $visibleFlagFalse = Part::query()->create([
            'name' => 'Widoczna stara część',
            'status' => 'ready',
            'quantity' => 1,
            'price' => 100,
            'is_visible_storefront' => false,
            'needs_listing' => false,
        ]);

        $needsListing = Part::query()->create([
            'name' => 'Ukryta część do wystawienia',
            'status' => 'ready',
            'quantity' => 1,
            'price' => 100,
            'is_visible_storefront' => true,
            'needs_listing' => true,
        ]);

        $archived = Part::query()->create([
            'name' => 'Archiwalna część',
            'status' => 'archived',
            'quantity' => 1,
            'price' => 100,
            'is_visible_storefront' => true,
            'needs_listing' => false,
        ]);

        $sold = Part::query()->create([
            'name' => 'Sprzedana część',
            'status' => 'sold',
            'quantity' => 1,
            'price' => 100,
            'is_visible_storefront' => true,
            'needs_listing' => false,
        ]);

        $outOfStock = Part::query()->create([
            'name' => 'Część bez stocku',
            'status' => 'ready',
            'quantity' => 0,
            'price' => 100,
            'is_visible_storefront' => true,
            'needs_listing' => false,
        ]);

        $visibleIds = Part::query()->storefrontVisible()->pluck('id')->all();

        $this->assertContains($visibleFlagFalse->id, $visibleIds);
        $this->assertNotContains($needsListing->id, $visibleIds);
        $this->assertNotContains($archived->id, $visibleIds);
        $this->assertNotContains($sold->id, $visibleIds);
        $this->assertNotContains($outOfStock->id, $visibleIds);
    }

    public function test_storefront_visibility_diagnostic_endpoint_reports_required_counts(): void
    {
        Part::query()->create([
            'name' => 'Widoczna stara część',
            'status' => 'ready',
            'quantity' => 1,
            'price' => 100,
            'is_visible_storefront' => false,
            'needs_listing' => false,
        ]);

        Part::query()->create([
            'name' => 'Ukryta część do wystawienia',
            'status' => 'ready',
            'quantity' => 1,
            'price' => 100,
            'is_visible_storefront' => true,
            'needs_listing' => true,
        ]);

        $response = $this->getJson('/tools/check-storefront-visibility?token=gps_images_import_2026');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('parts_total', 2)
            ->assertJsonPath('needs_listing_true_count', 1)
            ->assertJsonPath('needs_listing_false_count', 1)
            ->assertJsonPath('storefront_visible_count', 1)
            ->assertJsonPath('needs_listing_visible_in_storefront_count', 0)
            ->assertJsonPath('storefront_hidden_by_visibility_flag_count', 0)
            ->assertJsonStructure([
                'status_counts',
                'is_visible_storefront_counts',
                'quantity_positive_count',
                'quantity_zero_count',
                'storefront_hidden_by_needs_listing_count',
                'storefront_hidden_by_status_count',
                'storefront_hidden_by_quantity_count',
                'sample_visible_parts',
                'sample_hidden_by_visibility_flag',
                'sample_hidden_by_status',
                'sample_hidden_by_quantity',
            ]);
    }

    public function test_draft_part_with_stock_and_price_is_hidden_and_cannot_be_added_to_cart(): void
    {
        $part = Part::query()->create([
            'name' => 'Roboczy produkt z ceną',
            'slug' => 'roboczy-produkt-z-cena',
            'status' => 'draft',
            'quantity' => 1,
            'price' => 99.99,
            'needs_listing' => false,
            'is_visible_storefront' => true,
        ]);

        $this->assertFalse(Part::query()->whereKey($part->id)->storefrontVisible()->exists());
        $this->get(route('storefront.catalog'))->assertDontSee('Roboczy produkt z ceną');
        $this->get(route('storefront.product', $part->slug))->assertNotFound();

        $this->post(route('storefront.cart.add', $part))->assertSessionHas('error');
        $this->assertFalse(app(\App\Services\Storefront\CartService::class)->isAvailable($part));
    }

    public function test_draft_part_without_valid_price_cannot_be_bought(): void
    {
        $part = Part::query()->create([
            'name' => 'Roboczy produkt bez ceny',
            'status' => 'draft',
            'quantity' => 1,
            'price' => 0,
            'needs_listing' => false,
            'is_visible_storefront' => true,
        ]);

        $this->post(route('storefront.cart.add', $part))->assertSessionHas('error');
        $this->assertFalse(app(\App\Services\Storefront\CartService::class)->isAvailable($part));
    }

    public function test_ready_part_with_stock_and_positive_price_is_storefront_buyable(): void
    {
        $part = Part::query()->create([
            'name' => 'Gotowy produkt',
            'status' => 'ready',
            'quantity' => 1,
            'price' => 123.45,
            'needs_listing' => false,
            'is_visible_storefront' => false,
        ]);

        $this->assertTrue(Part::query()->whereKey($part->id)->storefrontVisible()->exists());
        $this->assertTrue(app(\App\Services\Storefront\CartService::class)->isAvailable($part));
        $this->post(route('storefront.cart.add', $part))->assertSessionHas('success');
    }

    public function test_ready_part_without_positive_price_cannot_be_bought_for_zero(): void
    {
        foreach ([0, null] as $price) {
            $part = Part::query()->create([
                'name' => 'Gotowy produkt bez ceny '.($price === null ? 'null' : 'zero'),
                'status' => 'ready',
                'quantity' => 1,
                'price' => $price,
                'needs_listing' => false,
                'is_visible_storefront' => true,
            ]);

            $this->assertFalse(Part::query()->whereKey($part->id)->storefrontVisible()->exists());
            $this->assertFalse(app(\App\Services\Storefront\CartService::class)->isAvailable($part));
            $this->post(route('storefront.cart.add', $part))->assertSessionHas('error');
        }
    }

    public function test_sold_and_archived_parts_are_not_buyable(): void
    {
        foreach (['sold', 'archived'] as $status) {
            $part = Part::query()->create([
                'name' => 'Produkt '.$status,
                'status' => $status,
                'quantity' => 1,
                'price' => 50,
                'needs_listing' => false,
                'is_visible_storefront' => true,
            ]);

            $this->assertFalse(Part::query()->whereKey($part->id)->storefrontVisible()->exists());
            $this->assertFalse(app(\App\Services\Storefront\CartService::class)->isAvailable($part));
            $this->post(route('storefront.cart.add', $part))->assertSessionHas('error');
        }
    }

}
