<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\PartMarketplaceReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartMarketplaceReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_part_returns_ready_without_marketplace_write_intent(): void
    {
        $category = PartCategory::query()->create(['name' => 'Skrzynie biegów']);
        $part = Part::query()->create([
            'name' => 'Kompletna część',
            'description' => 'Pełny opis części.',
            'category_id' => $category->id,
            'price' => 100,
            'ovoko_price' => 110,
            'quantity' => 1,
            'condition_notes' => 'Używany',
            'vehicle_snapshot' => ['make' => 'BMW', 'model' => 'X3'],
            'review_metadata' => ['marketplace_translations' => [
                'ebay_de' => ['title' => 'Complete part', 'description' => 'Full German description.'],
                'ebay_fr' => ['title' => 'Pièce complète', 'description' => 'Description française complète.'],
            ]],
        ]);

        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/complete.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['allegro_main', 'ovoko', 'ebay_de'] as $channel) {
            MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => $channel, 'external_category_id' => '123']);
        }

        $result = app(PartMarketplaceReadinessService::class)->check($part);

        foreach (['allegro', 'ovoko', 'ebay'] as $marketplace) {
            $this->assertFalse($result[$marketplace]['will_make_marketplace_request']);
        }

        $this->assertSame('ready', $result['allegro']['status']);
        $this->assertSame('ready', $result['ovoko']['status']);
        $this->assertContains('mapowanie kategorii Allegro', $result['allegro']['ok']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id]);
    }

    public function test_missing_images_price_and_category_mapping_returns_missing(): void
    {
        $part = Part::query()->create([
            'name' => 'Niekompletna część',
            'price' => null,
            'ovoko_price' => null,
            'quantity' => 1,
        ]);

        $result = app(PartMarketplaceReadinessService::class)->check($part);

        foreach (['allegro', 'ovoko', 'ebay'] as $marketplace) {
            $this->assertSame('missing', $result[$marketplace]['status']);
            $this->assertFalse($result[$marketplace]['ready']);
            $this->assertFalse($result[$marketplace]['will_make_marketplace_request']);
            $this->assertContains('zdjęcia', $result[$marketplace]['missing']);
        }

        $this->assertContains('mapowanie kategorii Allegro', $result['allegro']['missing']);
        $this->assertContains('cena Ovoko', $result['ovoko']['missing']);
        $this->assertContains('mapowanie kategorii eBay', $result['ebay']['missing']);
        $this->assertContains('tłumaczenie eBay DE', $result['ebay']['presentation']['missing']);
        $this->assertContains('tłumaczenie eBay FR', $result['ebay']['presentation']['missing']);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_presentation_hides_debug_fields_and_deduplicates_missing_items(): void
    {
        $part = Part::query()->create([
            'name' => 'Niekompletna część',
            'quantity' => 1,
        ]);

        $result = app(PartMarketplaceReadinessService::class)->check($part);
        $presentation = $result['ebay']['presentation'];

        $this->assertArrayNotHasKey('will_make_marketplace_request', $presentation);
        $this->assertArrayNotHasKey('source', $presentation);
        $this->assertSame(array_values(array_unique($presentation['missing'])), $presentation['missing']);
        $this->assertSame('Uzupełnij braki', $presentation['message']);
        $this->assertTrue($presentation['safe_preview_only']);
    }
}
