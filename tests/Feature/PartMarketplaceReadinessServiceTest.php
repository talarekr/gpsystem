<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\PartMarketplaceReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    public function test_marketplace_preparation_panel_renders_three_operational_cards_without_old_technical_copy(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create([
            'name' => 'Alternator BMW',
            'description' => 'Opis alternatora.',
            'category_id' => $category->id,
            'price' => 100,
            'ovoko_price' => 120,
            'quantity' => 1,
            'vehicle_snapshot' => ['make' => 'BMW'],
            'review_metadata' => ['marketplace_translations' => [
                'ebay_de' => ['title' => 'Generator BMW', 'description' => 'Deutsche Beschreibung.'],
                'ebay_fr' => ['title' => 'Alternateur BMW', 'description' => 'Description française.'],
            ]],
        ]);

        foreach ([
            'allegro_main' => ['261054', 'Alternator', 'Motoryzacja / Części / Alternatory'],
            'ovoko' => ['252', 'Alternator', 'Części / Alternator'],
            'ebay_de' => ['177697', 'Lichtmaschine', 'Auto & Motorrad / Lichtmaschinen'],
        ] as $channel => [$id, $name, $path]) {
            MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => $channel, 'external_category_id' => $id, 'external_category_name' => $name, 'external_category_path' => $path]);
            \App\Models\MarketplaceCategory::query()->create(['channel' => $channel, 'external_category_id' => $id, 'name' => $name, 'full_path' => $path, 'level' => 1, 'active' => true]);
        }

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertStringContainsString('data-marketplace-card="allegro"', $html);
        $this->assertStringContainsString('data-marketplace-card="ovoko"', $html);
        $this->assertStringContainsString('data-marketplace-card="ebay"', $html);
        $this->assertStringNotContainsString('To jest podgląd przygotowania produktu', $html);
        $this->assertStringNotContainsString('Przygotuj eBay DE', $html);
        $this->assertStringNotContainsString('Przygotuj eBay FR', $html);
        $this->assertStringContainsString('Motoryzacja / Części / Alternatory', $html);
        $this->assertStringContainsString('data-category-chooser-field', $html);
        $this->assertStringContainsString('data-shared-category-input', $html);
        $this->assertStringContainsString('data-category-drawer-trigger', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('x-on:click.prevent.stop="categoryDrawerOpen = true"', $html);
        $this->assertStringContainsString('data-category-drawer', $html);
        $this->assertStringContainsString('data-marketplace-category-tree="allegro_main"', $html);
        $this->assertStringContainsString('data-marketplace-category-tree="ovoko"', $html);
        $this->assertStringContainsString('data-marketplace-category-tree="ebay_de"', $html);
        $this->assertStringNotContainsString('Drzewo kategorii Allegro', $html);
        $this->assertStringNotContainsString('ID kategorii:', $html);
        $this->assertStringNotContainsString('>Gotowy</span>', $html);
        $this->assertStringContainsString('Przygotuj', $html);
        $this->assertStringContainsString('Aukcja przygotowana', $html);
        $this->assertStringContainsString('Podgląd aukcji', $html);
        $this->assertStringContainsString('Szczegóły techniczne', $html);
    }

    public function test_marketplace_category_field_matches_shared_category_field_structure_and_fallbacks(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);

        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => '177697']);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'ebay_de', 'external_category_id' => '177697', 'name' => 'Lichtmaschine', 'full_path' => 'Auto & Motorrad / Lichtmaschinen', 'level' => 1, 'active' => true]);

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertStringContainsString('gps-shared-category-field fi-input-wrp', $html);
        $this->assertStringContainsString('gps-shared-category-field__legend', $html);
        $this->assertStringContainsString('>Kategoria</legend>', $html);
        $this->assertStringNotContainsString('fi-fo-field-wrp-label inline-flex items-center gap-x-3', $html);
        $this->assertStringNotContainsString('rounded-r-lg border-l border-gray-200', $html);
        $this->assertStringContainsString('data-category-drawer-trigger', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('x-on:click.prevent.stop="categoryDrawerOpen = true"', $html);
        $this->assertStringContainsString('data-category-drawer', $html);
        $this->assertStringContainsString('data-category-drawer-id="marketplace-category-drawer-ebay-de-', $html);
        $this->assertStringNotContainsString('data-category-drawer-toggle', $html);
        $this->assertStringNotContainsString('peer-checked', $html);
        $this->assertStringContainsString('Auto &amp; Motorrad / Lichtmaschinen', $html);
        $this->assertStringNotContainsString('Wybrana kategoria eBay', $html);
        $this->assertStringContainsString('Tryb lokalny: bez publish i bez marketplace API write.', $html);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_marketplace_category_drawers_have_unique_channel_ids(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertStringContainsString('id="marketplace-category-drawer-allegro-main-'.$part->id.'"', $html);
        $this->assertStringContainsString('id="marketplace-category-drawer-ovoko-'.$part->id.'"', $html);
        $this->assertStringContainsString('id="marketplace-category-drawer-ebay-de-'.$part->id.'"', $html);
        $this->assertSame(1, substr_count($html, 'id="marketplace-category-drawer-allegro-main-'.$part->id.'"'));
        $this->assertSame(1, substr_count($html, 'id="marketplace-category-drawer-ovoko-'.$part->id.'"'));
        $this->assertSame(1, substr_count($html, 'id="marketplace-category-drawer-ebay-de-'.$part->id.'"'));
    }

    public function test_marketplace_category_field_shows_neutral_fallback_without_category_name(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);

        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => '177697']);

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertStringContainsString('Wybierz kategorię', $html);
        $this->assertStringNotContainsString('Wybrana kategoria eBay', $html);
        $this->assertStringContainsString('Szczegóły techniczne', $html);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_manual_marketplace_category_selection_updates_local_mapping_without_listing_write(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'ovoko', 'external_category_id' => '252', 'name' => 'Alternator', 'full_path' => 'Części / Alternator', 'level' => 1, 'active' => true]);

        $this->post(route('tools.part-marketplace-category-mapping.store'), [
            'part_id' => $part->id,
            'channel' => 'ovoko',
            'external_category_id' => '252',
        ])->assertRedirect();

        $this->assertDatabaseHas('marketplace_category_mappings', [
            'local_category_id' => $category->id,
            'channel' => 'ovoko',
            'external_category_id' => '252',
            'source' => 'manual_part_edit_marketplace_preparation',
        ]);
        $this->assertDatabaseCount('marketplace_listings', 0);
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

        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);

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


    public function test_ebay_de_preview_converts_source_pln_price_to_eur_with_nbp_rate(): void
    {
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        $part = $this->ebayReadinessPart(['ebay_price' => 2.5, 'price' => 100]);

        $readiness = app(\App\Services\Marketplace\MarketplaceListingReadinessService::class)->checkPartReadiness($part->fresh(), 'ebay_de');
        $preview = $readiness['prepared_payload_preview_safe'];

        $this->assertSame(2.5, $preview['price_source_pln']);
        $this->assertSame(0.58, $preview['price_eur']);
        $this->assertSame('EUR', $preview['currency']);
        $this->assertSame('EUR', $readiness['currency']);
        $this->assertSame(4.3, $preview['exchange_rate']['rate']);
        $this->assertSame('NBP_TABLE_A', $preview['exchange_rate']['source']);
        $this->assertTrue($preview['description_template_present']);
        $this->assertFalse($preview['will_make_marketplace_request']);
    }

    public function test_ebay_fr_preview_uses_eur_currency(): void
    {
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27']);
        $part = $this->ebayReadinessPart(['ebay_price' => 2.5], 'ebay_fr');

        $readiness = app(\App\Services\Marketplace\MarketplaceListingReadinessService::class)->checkPartReadiness($part->fresh(), 'ebay_fr');

        $this->assertSame('EUR', $readiness['currency']);
        $this->assertSame('EUR', $readiness['prepared_payload_preview_safe']['currency']);
        $this->assertSame(0.58, $readiness['prepared_payload_preview_safe']['price_eur']);
        $this->assertFalse($readiness['prepared_payload_preview_safe']['will_make_marketplace_request']);
    }

    public function test_ebay_readiness_blocks_when_nbp_rate_is_unavailable(): void
    {
        Cache::forget('nbp_table_a_eur_rate');
        \Illuminate\Support\Facades\Http::fake(['api.nbp.pl/*' => \Illuminate\Support\Facades\Http::response([], 500)]);
        $part = $this->ebayReadinessPart(['ebay_price' => 2.5]);

        $readiness = app(\App\Services\Marketplace\MarketplaceListingReadinessService::class)->checkPartReadiness($part->fresh(), 'ebay_de');

        $this->assertFalse($readiness['can_prepare']);
        $this->assertContains('exchange_rate', $readiness['missing_fields']);
        $this->assertContains('Brak kursu EUR z NBP.', $readiness['blockers']);
        $this->assertFalse($readiness['prepared_payload_preview_safe']['will_make_marketplace_request']);
    }

    public function test_ebay_readiness_blocks_when_source_pln_price_is_zero(): void
    {
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27']);
        $part = $this->ebayReadinessPart(['ebay_price' => 0, 'price' => 0]);

        $readiness = app(\App\Services\Marketplace\MarketplaceListingReadinessService::class)->checkPartReadiness($part->fresh(), 'ebay_de');

        $this->assertFalse($readiness['can_prepare']);
        $this->assertContains('ebay_price_pln', $readiness['missing_fields']);
        $this->assertContains('price_eur', $readiness['missing_fields']);
        $this->assertFalse($readiness['prepared_payload_preview_safe']['will_make_marketplace_request']);
    }

    private function ebayReadinessPart(array $attributes = [], string $channel = 'ebay_de'): Part
    {
        $category = PartCategory::query()->create(['name' => 'eBay test category']);
        $part = Part::query()->create(array_merge([
            'name' => 'eBay test part',
            'description' => 'Opis testowy.',
            'category_id' => $category->id,
            'price' => 100,
            'quantity' => 1,
            'condition_notes' => 'Używany',
        ], $attributes));

        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/ebay.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => $channel, 'external_category_id' => '123']);

        return $part;
    }

}
