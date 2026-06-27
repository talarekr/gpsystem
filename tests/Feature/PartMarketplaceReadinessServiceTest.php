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
    public function test_marketplace_readiness_payload_uses_admin_image_order_and_includes_ovoko_dimensions_warning_only(): void
    {
        $category = PartCategory::query()->create(['name' => 'Lampy']);
        $part = Part::query()->create([
            'name' => 'Lampa prawa',
            'description' => 'Opis lampy.',
            'category_id' => $category->id,
            'price' => 100,
            'ovoko_price' => 120,
            'quantity' => 1,
            'vehicle_snapshot' => ['make' => 'BMW'],
            'weight_kg' => 1.5,
            'length_cm' => 50,
            'width_cm' => 25,
            'height_cm' => 20,
        ]);

        DB::table('part_images')->insert([
            ['part_id' => $part->id, 'path' => 'parts/photos/second.jpg', 'sort_order' => 20, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
            ['part_id' => $part->id, 'path' => 'parts/photos/first.jpg', 'sort_order' => 10, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ovoko', 'external_category_id' => 'OV-1']);

        $readiness = app(\App\Services\Marketplace\MarketplaceListingReadinessService::class)->checkPartReadiness($part->fresh(), 'ovoko');

        $this->assertSame([
            'first.jpg',
            'second.jpg',
        ], array_map('basename', $readiness['prepared_payload_preview_safe']['image_urls']));
        $this->assertSame(['weight_kg' => 1.5, 'length_cm' => 50.0, 'width_cm' => 25.0, 'height_cm' => 20.0], $readiness['prepared_payload_preview_safe']['dimensions']);
        $this->assertNotContains('weight_kg', $readiness['missing_fields']);
        $this->assertNotContains('Ovoko dimensions are incomplete (weight_kg, length_cm, width_cm, height_cm).', $readiness['warnings']);

        $part->forceFill(['height_cm' => null])->save();
        $withWarning = app(\App\Services\Marketplace\MarketplaceListingReadinessService::class)->checkPartReadiness($part->fresh(), 'ovoko');
        $this->assertContains('Ovoko dimensions are incomplete (weight_kg, length_cm, width_cm, height_cm).', $withWarning['warnings']);
        $this->assertNotContains('height_cm', $withWarning['missing_fields']);
    }


    public function test_ebay_de_fr_description_templates_render_and_readiness_preview_without_marketplace_write(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create([
            'name' => 'Alternator 06H903017J',
            'description' => 'Sprawdzony alternator.',
            'category_id' => $category->id,
            'part_number' => '06H903017J',
            'oem_number' => 'OEM-123',
            'manufacturer_code' => 'MFR-123',
            'price' => 100,
            'quantity' => 1,
            'condition_notes' => 'Używany / sprawdzony',
            'vehicle_snapshot' => ['make' => 'Audi', 'model' => 'A4', 'production_year' => '2018', 'engine_code' => 'CNCD', 'steering_side' => 'left'],
        ]);

        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/alternator.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['ebay_de', 'ebay_fr'] as $channel) {
            MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => $channel, 'external_category_id' => '123']);
            \App\Models\MarketplaceAccount::query()->create(['marketplace' => $channel, 'name' => $channel, 'code' => $channel, 'status' => 'active', 'api_enabled' => true, 'api_settings' => []]);
        }

        $renderer = app(\App\Services\Marketplace\EbayDescriptionTemplateRenderer::class);
        $this->assertTrue($renderer->isAvailable('ebay_de'));
        $this->assertTrue($renderer->isAvailable('ebay_fr'));

        $deHtml = $renderer->render('ebay_de', $part->fresh());
        $this->assertStringContainsString('Schneller weltweiter Versand', $deHtml);
        $this->assertStringContainsString('Beschreibung', $deHtml);
        $this->assertStringContainsString('Spezifikationen', $deHtml);
        $this->assertStringContainsString('Kaufen Sie mit Vertrauen', $deHtml);
        $this->assertStringNotContainsString('/wp-content/uploads/', $deHtml);
        $this->assertStringContainsString('/ebay-template/assets/icon-shipping.png', $deHtml);

        $frHtml = $renderer->render('ebay_fr', $part->fresh());
        $this->assertStringContainsString('Livraison rapide dans le monde entier', $frHtml);
        $this->assertStringContainsString('Description', $frHtml);
        $this->assertStringContainsString('Spécifications', $frHtml);
        $this->assertStringContainsString('Achetez en toute confiance', $frHtml);
        $this->assertStringNotContainsString('/wp-content/uploads/', $frHtml);

        foreach (['ebay_de', 'ebay_fr'] as $channel) {
            $readiness = app(\App\Services\Marketplace\MarketplaceListingReadinessService::class)->checkPartReadiness($part->fresh(), $channel);
            $preview = $readiness['prepared_payload_preview_safe'];

            $this->assertNotContains('description_template', $readiness['missing_fields']);
            $this->assertContains('business_policies', $readiness['missing_fields']);
            $this->assertContains('eBay business policies are missing: payment, fulfillment/shipping, or return.', $readiness['blockers']);
            $this->assertFalse($preview['will_make_marketplace_request']);
            $this->assertTrue($preview['description_template_present']);
            $this->assertSame($channel, $preview['description_template_channel']);
            $this->assertTrue($preview['description_rendered_present']);
            $this->assertArrayHasKey('icon_shipping', $preview['description_template_asset_urls']);
        }
    }

}
