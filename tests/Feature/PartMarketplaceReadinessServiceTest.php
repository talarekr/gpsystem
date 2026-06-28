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


    public function test_part_resource_marketplace_section_has_sales_channels_title_and_is_expanded_by_default(): void
    {
        $resource = file_get_contents(app_path('Filament/Resources/PartResource.php'));

        $this->assertStringContainsString("Section::make('Kanały sprzedaży')", $resource);
        $this->assertStringNotContainsString("Section::make('Praktyczne przygotowanie produktu')", $resource);
        $this->assertStringNotContainsString("->collapsed()", substr($resource, strpos($resource, "Section::make('Kanały sprzedaży')"), 400));
    }

    public function test_ebay_prepared_translations_hide_static_translation_missing_items(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create([
            'name' => 'Alternator BMW',
            'description' => 'Opis alternatora.',
            'category_id' => $category->id,
            'price' => 100,
            'quantity' => 1,
            'vehicle_snapshot' => ['make' => 'BMW'],
            'review_metadata' => ['marketplace_prepared_translations' => [
                'ebay_de' => ['status' => 'prepared'],
                'ebay_fr' => ['status' => 'prepared'],
            ]],
        ]);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/ready.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => '177697']);

        $result = app(PartMarketplaceReadinessService::class)->check($part->fresh());
        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part->fresh()])->render();

        $this->assertSame('ready', $result['ebay']['status']);
        $this->assertNotContains('tłumaczenie eBay DE', $result['ebay']['presentation']['missing']);
        $this->assertNotContains('tłumaczenie eBay FR', $result['ebay']['presentation']['missing']);
        $this->assertStringContainsString('Aukcja przygotowana', $html);
        $this->assertStringNotContainsString('tłumaczenie eBay DE', $html);
        $this->assertStringNotContainsString('tłumaczenie eBay FR', $html);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_ebay_readiness_shows_only_real_missing_items_when_blocked(): void
    {
        $part = Part::query()->create(['name' => 'Niekompletna część', 'quantity' => 1]);

        $result = app(PartMarketplaceReadinessService::class)->check($part);
        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertContains('zdjęcia', $result['ebay']['presentation']['missing']);
        $this->assertStringContainsString('zdjęcia', $html);
        $this->assertStringContainsString('tłumaczenie eBay DE', $html);
        $this->assertStringContainsString('tłumaczenie eBay FR', $html);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

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
        $this->assertStringContainsString('Alternatory', $html);
        $this->assertStringNotContainsString('>Motoryzacja / Części / Alternatory</button>', $html);
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
        $this->assertStringNotContainsString('Szczegóły techniczne', $html);
    }

    public function test_marketplace_category_field_matches_shared_category_field_structure_and_fallbacks(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);

        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => '177697']);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'ebay_de', 'external_category_id' => '177697', 'name' => 'Lichtmaschine', 'full_path' => 'Auto & Motorrad / Lichtmaschinen', 'level' => 1, 'active' => true]);

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $shellBlade = file_get_contents(resource_path('views/filament/forms/category-field-shell.blade.php'));
        $triggerBlade = file_get_contents(resource_path('views/filament/forms/partials/category-drawer-trigger.blade.php'));

        $drawerShellBlade = file_get_contents(resource_path('views/filament/forms/category-drawer-shell.blade.php'));

        $this->assertStringContainsString("@include('filament.forms.partials.category-drawer-trigger'", $shellBlade);
        $this->assertStringContainsString("@include('filament.forms.category-picker'", $drawerShellBlade);
        $this->assertStringContainsString('fixed inset-y-0 right-0 z-50 w-full max-w-xl flex-col', $drawerShellBlade);
        $this->assertStringContainsString('heroicon-m-bars-3', $triggerBlade);
        $this->assertStringContainsString('data-shared-category-trigger', $triggerBlade);
        $this->assertStringNotContainsString('☰', $shellBlade);
        $this->assertStringNotContainsString('gps-marketplace-category-trigger', $html);
        $this->assertStringContainsString('gps-shared-category-field fi-input-wrp', $html);
        $this->assertStringContainsString('gps-shared-category-field__legend', $html);
        $this->assertStringContainsString('>Kategoria</legend>', $html);
        $this->assertStringNotContainsString('fi-fo-field-wrp-label inline-flex items-center gap-x-3', $html);
        $this->assertStringNotContainsString('rounded-r-lg border-l border-gray-200', $html);
        $this->assertStringContainsString('data-category-drawer-trigger', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('x-on:click.prevent.stop="categoryDrawerOpen = true"', $html);
        $this->assertStringContainsString('data-category-drawer', $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('fixed inset-y-0 right-0 z-50 w-full max-w-xl flex-col bg-white p-6 shadow-xl dark:bg-gray-900 gps-category-picker-modal', $html);
        $this->assertStringContainsString('data-category-drawer-id="marketplace-category-drawer-ebay-de-', $html);
        $this->assertStringNotContainsString('data-category-drawer-toggle', $html);
        $this->assertStringNotContainsString('peer-checked', $html);
        $this->assertStringContainsString('Lichtmaschinen', $html);
        $this->assertStringNotContainsString('>Auto &amp; Motorrad / Lichtmaschinen</button>', $html);
        $this->assertStringNotContainsString('Wybrana kategoria eBay', $html);
        $this->assertStringNotContainsString('Tryb lokalny: bez publish i bez marketplace API write.', $html);
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

    public function test_marketplace_picker_uses_hierarchical_children_in_shared_drawer_without_flattening_roots(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);

        \App\Models\MarketplaceCategory::query()->create(['channel' => 'allegro_main', 'external_category_id' => 'root-moto', 'name' => 'Motoryzacja', 'full_path' => 'Motoryzacja', 'level' => 0, 'active' => true]);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'allegro_main', 'external_category_id' => 'child-parts', 'parent_external_category_id' => 'root-moto', 'name' => 'Części samochodowe', 'full_path' => 'Motoryzacja / Części samochodowe', 'level' => 1, 'active' => true]);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'allegro_main', 'external_category_id' => 'leaf-alternators', 'parent_external_category_id' => 'child-parts', 'name' => 'Alternatory', 'full_path' => 'Motoryzacja / Części samochodowe / Alternatory', 'level' => 2, 'active' => true]);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'ovoko', 'external_category_id' => 'ov-root', 'name' => 'Części', 'full_path' => 'Części', 'level' => 0, 'active' => true]);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'ebay_de', 'external_category_id' => 'eb-root', 'name' => 'Auto & Motorrad', 'full_path' => 'Auto & Motorrad', 'level' => 0, 'active' => true]);

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertStringContainsString('data-category-drawer', $html);
        $this->assertStringContainsString('gps-category-picker', $html);
        $this->assertStringContainsString('data-marketplace-category-tree="allegro_main"', $html);
        $this->assertStringNotContainsString('parent_id&quot;:&quot;root-moto&quot;', $html);
        $this->assertStringNotContainsString('parent_id&quot;:&quot;child-parts&quot;', $html);
        $this->assertStringContainsString('lazyChildrenUrl', $html);
        $this->assertStringContainsString('ensureChildren(null)', $html);
        $this->assertStringContainsString('currentChildren()', $html);
        $this->assertStringContainsString('x-on:click="activate(category)"', $html);
        $this->assertStringNotContainsString('data-flat-marketplace-category-list', $html);
        $this->assertStringNotContainsString('fi-modal-window-ctn', $html);
    }

    public function test_marketplace_picker_keeps_separate_tree_sources_for_supported_channels(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);

        foreach ([
            ['allegro_main', 'alg-root', 'Allegro root'],
            ['ovoko', 'ov-root', 'Ovoko root'],
            ['ebay_de', 'eb-root', 'eBay root'],
        ] as [$channel, $id, $name]) {
            \App\Models\MarketplaceCategory::query()->create(['channel' => $channel, 'external_category_id' => $id, 'name' => $name, 'full_path' => $name, 'level' => 0, 'active' => true]);
        }

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertStringContainsString('data-marketplace-category-tree="allegro_main"', $html);
        $this->assertStringContainsString('data-marketplace-category-tree="ovoko"', $html);
        $this->assertStringContainsString('data-marketplace-category-tree="ebay_de"', $html);
        $this->assertStringNotContainsString('Allegro root', $html);
        $this->assertStringNotContainsString('Ovoko root', $html);
        $this->assertStringNotContainsString('eBay root', $html);
        $this->assertStringNotContainsString('data-marketplace-category-tree="allegro"', $html);
    }



    public function test_ebay_de_category_children_endpoint_returns_roots_from_local_db_and_diagnostics(): void
    {
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'ebay_de', 'external_category_id' => '6000', 'parent_external_category_id' => '0', 'name' => 'Vehicle Parts & Accessories', 'full_path' => 'Vehicle Parts & Accessories', 'level' => 0, 'active' => true]);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'ebay_de', 'external_category_id' => '6030', 'parent_external_category_id' => '6000', 'name' => 'Car & Truck Parts', 'full_path' => 'Vehicle Parts & Accessories / Car & Truck Parts', 'level' => 1, 'active' => true]);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'ebay_fr', 'external_category_id' => '7000', 'name' => 'FR root', 'full_path' => 'FR root', 'level' => 0, 'active' => true]);

        $this->getJson(route('tools.marketplace-category-children', ['token' => 'gps_images_import_2026', 'channel' => 'ebay_de']))
            ->assertOk()
            ->assertJsonPath('channel', 'ebay_de')
            ->assertJsonPath('parent_external_category_id', null)
            ->assertJsonPath('root_mode', true)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('source', 'local_db_only')
            ->assertJsonPath('will_make_marketplace_request', false)
            ->assertJsonPath('publish', false)
            ->assertJsonPath('children.0.id', '6000')
            ->assertJsonPath('children.0.parent_id', null)
            ->assertJsonPath('children.0.has_children', true)
            ->assertJsonMissing(['id' => '6030'])
            ->assertJsonMissing(['id' => '7000']);

        $this->getJson(route('tools.marketplace-category-children', ['token' => 'gps_images_import_2026', 'channel' => 'ebay_de', 'parent_external_category_id' => '6000']))
            ->assertOk()
            ->assertJsonPath('root_mode', false)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('children.0.id', '6030')
            ->assertJsonPath('children.0.parent_id', '6000');

        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_marketplace_readiness_cards_render_headers_with_existing_wordmark_partial(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertStringContainsString('gps-order-source gps-order-source--allegro', $html);
        $this->assertStringContainsString('gps-order-source gps-order-source--ovoko', $html);
        $this->assertStringContainsString('gps-order-source gps-order-source--ebay', $html);
        $this->assertStringContainsString('<span style="color:#0064D2">e</span><span style="color:#E53238">B</span><span style="color:#F5AF02">a</span><span style="color:#86B817">y</span>', $html);
    }

    public function test_marketplace_category_children_endpoint_returns_only_one_local_db_level(): void
    {
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'allegro_main', 'external_category_id' => 'root-moto', 'name' => 'Motoryzacja', 'full_path' => 'Motoryzacja', 'level' => 0, 'active' => true]);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'allegro_main', 'external_category_id' => 'child-parts', 'parent_external_category_id' => 'root-moto', 'name' => 'Części samochodowe', 'full_path' => 'Motoryzacja / Części samochodowe', 'level' => 1, 'active' => true]);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'allegro_main', 'external_category_id' => 'leaf-alternators', 'parent_external_category_id' => 'child-parts', 'name' => 'Alternatory', 'full_path' => 'Motoryzacja / Części samochodowe / Alternatory', 'level' => 2, 'active' => true]);

        $this->getJson(route('tools.marketplace-category-children', ['token' => 'gps_images_import_2026', 'channel' => 'allegro_main']))
            ->assertOk()
            ->assertJsonPath('source', 'local_db_only')
            ->assertJsonPath('will_make_marketplace_request', false)
            ->assertJsonPath('children.0.id', 'root-moto')
            ->assertJsonPath('children.0.parent_id', null)
            ->assertJsonPath('children.0.has_children', true)
            ->assertJsonMissing(['id' => 'child-parts']);

        $this->getJson(route('tools.marketplace-category-children', ['token' => 'gps_images_import_2026', 'channel' => 'allegro_main', 'parent_external_category_id' => 'root-moto']))
            ->assertOk()
            ->assertJsonPath('parent_external_category_id', 'root-moto')
            ->assertJsonPath('children.0.id', 'child-parts')
            ->assertJsonPath('children.0.parent_id', 'root-moto')
            ->assertJsonPath('children.0.has_children', true)
            ->assertJsonMissing(['id' => 'leaf-alternators']);

        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_marketplace_category_picker_initial_render_is_lazy_and_has_no_full_tree_json(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);

        foreach (range(1, 25) as $i) {
            \App\Models\MarketplaceCategory::query()->create(['channel' => 'ebay_de', 'external_category_id' => 'eb-'.$i, 'name' => 'eBay category '.$i, 'full_path' => 'eBay category '.$i, 'level' => 0, 'active' => true]);
        }

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertStringContainsString('data-marketplace-category-tree="ebay_de"', $html);
        $this->assertStringContainsString('lazyChildrenUrl', $html);
        $this->assertStringNotContainsString('data-marketplace-category-tree="[{', $html);
        $this->assertStringNotContainsString('eBay category 25', $html);
        $this->assertLessThan(3, substr_count($html, 'eBay category'));
    }

    public function test_marketplace_categories_have_channel_parent_external_category_index_migration(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_06_24_000000_create_marketplace_categories_table.php'));

        $this->assertStringContainsString("$"."table->index(['channel', 'parent_external_category_id']);", $migration);
        $this->assertStringContainsString('parent_external_category_id', file_get_contents(app_path('Http/Controllers/Tools/PartMarketplaceReadinessController.php')));
        $this->assertStringNotContainsString('parent_external_id', file_get_contents(app_path('Http/Controllers/Tools/PartMarketplaceReadinessController.php')));
    }

    public function test_ebay_card_shows_id_fallback_when_mapping_exists_without_category_name(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Alternator BMW', 'category_id' => $category->id, 'quantity' => 1]);

        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => '177697']);

        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part])->render();

        $this->assertStringContainsString('eBay ID: 177697', $html);
        $this->assertStringNotContainsString('>Wybierz kategorię<', $html);
        $this->assertStringContainsString('data-marketplace-category-tree="ebay_de"', $html);
        $this->assertStringNotContainsString('data-marketplace-category-tree="ebay"', $html);
        $this->assertStringNotContainsString('Wybrana kategoria eBay', $html);
        $this->assertStringNotContainsString('Szczegóły techniczne', $html);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_ebay_card_uses_ebay_de_mapping_and_local_category_path_for_presentation(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create([
            'name' => 'Alternator BMW',
            'description' => 'Opis alternatora.',
            'category_id' => $category->id,
            'price' => 100,
            'quantity' => 1,
            'vehicle_snapshot' => ['make' => 'BMW'],
            'review_metadata' => ['marketplace_translations' => [
                'ebay_de' => ['title' => 'Generator BMW', 'description' => 'Deutsche Beschreibung.'],
                'ebay_fr' => ['title' => 'Alternateur BMW', 'description' => 'Description française.'],
            ]],
        ]);

        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/ebay-ready.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => '177697']);
        \App\Models\MarketplaceCategory::query()->create(['channel' => 'ebay_de', 'external_category_id' => '177697', 'name' => 'Lichtmaschine', 'full_path' => 'Auto & Motorrad / Lichtmaschinen', 'level' => 1, 'active' => true]);

        $result = app(PartMarketplaceReadinessService::class)->check($part->fresh());
        $html = view('filament.resources.parts.marketplace-readiness-cards', ['part' => $part->fresh()])->render();

        $this->assertSame('ready', $result['ebay']['status']);
        $this->assertSame('Auto & Motorrad / Lichtmaschinen', $result['ebay']['presentation']['category']['value']);
        $this->assertStringContainsString('Lichtmaschine', $html);
        $this->assertStringNotContainsString('>Auto &amp; Motorrad / Lichtmaschinen</button>', $html);
        $this->assertStringNotContainsString('>Wybierz kategorię<', $html);
        $this->assertStringContainsString('data-marketplace-category-tree="ebay_de"', $html);
        $this->assertStringNotContainsString('data-marketplace-category-tree="ebay"', $html);
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
