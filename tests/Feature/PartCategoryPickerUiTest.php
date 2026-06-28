<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource\Pages\EditPart;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PartCategoryPickerUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_part_category_picker_selects_field_state_without_immediate_part_save(): void
    {
        $oldCategory = PartCategory::query()->create(['name' => 'Stara kategoria']);
        $newCategory = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Robocza część', 'category_id' => $oldCategory->id]);

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->call('setPartCategoryFromPicker', $newCategory->id)
            ->assertSet('data.category_id', $newCategory->id)
            ->assertSet('mountedFormComponentActions', [])
            ->assertDispatched('close-modal', id: 'form-component-action');

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
            'category_id' => $oldCategory->id,
        ]);
    }



    public function test_main_part_category_picker_refreshes_marketplace_cards_from_unsaved_category_state(): void
    {
        $oldCategory = PartCategory::query()->create(['name' => 'Old']);
        $newCategory = PartCategory::query()->create(['name' => 'New']);
        $part = Part::query()->create(['name' => 'Robocza część', 'category_id' => $oldCategory->id, 'price' => 100, 'ovoko_price' => 100, 'quantity' => 1]);

        foreach ([['allegro_main', 'ALG-OLD', 'Allegro old', 'ALG-NEW', 'Allegro new'], ['ovoko', 'OV-OLD', 'Ovoko old', 'OV-NEW', 'Ovoko new'], ['ebay_de', 'EB-OLD', 'eBay old', 'EB-NEW', 'eBay new']] as [$channel, $oldId, $oldName, $newId, $newName]) {
            MarketplaceCategoryMapping::query()->create(['local_category_id' => $oldCategory->id, 'channel' => $channel, 'external_category_id' => $oldId, 'external_category_name' => $oldName]);
            MarketplaceCategoryMapping::query()->create(['local_category_id' => $newCategory->id, 'channel' => $channel, 'external_category_id' => $newId, 'external_category_name' => $newName]);
        }

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->assertSee('Allegro old')
            ->assertSee('Ovoko old')
            ->assertSee('eBay old')
            ->call('setPartCategoryFromPicker', $newCategory->id)
            ->assertSet('data.category_id', $newCategory->id)
            ->assertSee('Allegro new')
            ->assertSee('Ovoko new')
            ->assertSee('eBay new')
            ->assertDontSee('Allegro old')
            ->assertDontSee('Ovoko old')
            ->assertDontSee('eBay old');

        $part->refresh();

        $this->assertSame($oldCategory->id, $part->category_id);
        $this->assertNull(data_get($part->review_metadata, 'marketplace_category_overrides'));
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_main_part_category_picker_keeps_manual_override_card_while_refreshing_other_channels(): void
    {
        $oldCategory = PartCategory::query()->create(['name' => 'Old']);
        $newCategory = PartCategory::query()->create(['name' => 'New']);
        $part = Part::query()->create([
            'name' => 'Robocza część',
            'category_id' => $oldCategory->id,
            'price' => 100,
            'ovoko_price' => 100,
            'quantity' => 1,
            'review_metadata' => ['marketplace_category_overrides' => [
                'ovoko' => ['channel' => 'ovoko', 'external_category_id' => 'OV-MANUAL', 'external_category_name' => 'Ovoko manual', 'source' => 'manual_part_edit_marketplace_preparation'],
            ]],
        ]);

        foreach ([['allegro_main', 'ALG-NEW', 'Allegro new'], ['ovoko', 'OV-NEW', 'Ovoko new'], ['ebay_de', 'EB-NEW', 'eBay new']] as [$channel, $newId, $newName]) {
            MarketplaceCategoryMapping::query()->create(['local_category_id' => $newCategory->id, 'channel' => $channel, 'external_category_id' => $newId, 'external_category_name' => $newName]);
        }

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->assertSee('Ovoko manual')
            ->call('setPartCategoryFromPicker', $newCategory->id)
            ->assertSet('data.category_id', $newCategory->id)
            ->assertSee('Allegro new')
            ->assertSee('Ovoko manual')
            ->assertSee('eBay new')
            ->assertDontSee('Ovoko new');

        $this->assertSame('OV-MANUAL', data_get($part->fresh()->review_metadata, 'marketplace_category_overrides.ovoko.external_category_id'));
        $this->assertDatabaseCount('marketplace_listings', 0);
    }


    public function test_marketplace_category_picker_selects_local_form_state_without_submit_endpoint_or_part_save(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Robocza część', 'category_id' => $category->id]);
        \App\Models\MarketplaceCategory::query()->create([
            'channel' => 'ovoko',
            'external_category_id' => '252',
            'name' => 'Alternator',
            'full_path' => 'Części / Alternator',
            'level' => 1,
            'active' => true,
        ]);

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->call('setMarketplaceCategoryFromPicker', 'ovoko', '252', 'Alternator', 'Części / Alternator')
            ->assertSet('data.marketplace_category_selections.ovoko.external_category_id', '252')
            ->assertSee('Alternator');

        $part->refresh();

        $this->assertNull(data_get($part->review_metadata, 'marketplace_category_overrides.ovoko'));
        $this->assertDatabaseMissing('marketplace_category_mappings', [
            'local_category_id' => $category->id,
            'channel' => 'ovoko',
            'external_category_id' => '252',
        ]);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_marketplace_category_picker_ui_has_no_hidden_mapping_form_submit(): void
    {
        $html = view('filament.resources.parts.marketplace-category-field', [
            'part' => Part::query()->make(['id' => 123]),
            'key' => 'ovoko',
            'labels' => ['ovoko' => 'Ovoko'],
            'category' => ['value' => null],
            'mappingChannels' => ['ovoko' => 'ovoko'],
            'marketplaceCategorySelections' => [],
        ])->render();

        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('marketplaceSelectionChannel: ', $html);
        $this->assertStringContainsString('ovoko', $html);
        $this->assertStringContainsString('setMarketplaceCategoryFromPicker', $html);
        $this->assertStringNotContainsString('data-marketplace-category-local-form', $html);
        $this->assertStringNotContainsString('tools/part-marketplace-category-mapping', $html);
        $this->assertStringNotContainsString('marketplaceForm.submit()', $html);
    }

    public function test_main_save_persists_marketplace_category_override_per_part_only(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        $part = Part::query()->create(['name' => 'Robocza część', 'category_id' => $category->id]);
        \App\Models\MarketplaceCategory::query()->create([
            'channel' => 'ovoko',
            'external_category_id' => '252',
            'name' => 'Alternator',
            'full_path' => 'Części / Alternator',
            'level' => 1,
            'active' => true,
        ]);

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->call('setMarketplaceCategoryFromPicker', 'ovoko', '252', 'Alternator', 'Części / Alternator')
            ->call('save');

        $part->refresh();

        $this->assertSame('252', data_get($part->review_metadata, 'marketplace_category_overrides.ovoko.external_category_id'));
        $this->assertSame('manual_part_edit_marketplace_preparation', data_get($part->review_metadata, 'marketplace_category_overrides.ovoko.source'));
        $this->assertDatabaseMissing('marketplace_category_mappings', [
            'local_category_id' => $category->id,
            'channel' => 'ovoko',
            'external_category_id' => '252',
        ]);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_main_part_category_picker_button_is_non_submit_and_closes_drawer_before_resetting_selection(): void
    {
        $html = view('filament.forms.category-picker', [
            'categories' => [[
                'id' => 1,
                'parent_id' => null,
                'name' => 'Alternatory',
                'path' => 'Alternatory',
                'has_children' => false,
            ]],
        ])->render();

        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('x-on:click="saveSelectedCategory()"', $html);
        $this->assertStringContainsString("const filamentFormComponentActionModalId = 'form-component-action';", $html);
        $this->assertStringContainsString("this.\$dispatch('close-modal', { id: filamentFormComponentActionModalId });", $html);
        $this->assertStringContainsString("window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: filamentFormComponentActionModalId } }));", $html);
        $this->assertStringContainsString('categoryDrawerOpen = false', $html);
        $this->assertStringContainsString('Promise.resolve(this.$wire.unmountFormComponentAction(true, true))', $html);
        $this->assertMatchesRegularExpression('/Promise\.resolve\(this\.\$wire\.unmountFormComponentAction\(true, true\)\).*?\.then\(finishFilamentClose\)/s', $html);
        $this->assertMatchesRegularExpression('/closeCategoryPicker\(\);\s*this\.selectedId = null;/s', $html);
        $this->assertStringNotContainsString('.fi-modal-header button', $html);
        $this->assertStringNotContainsString('$refs.marketplaceForm.submit();\n                this.closeCategoryPicker();', $html);
    }

    public function test_main_part_category_picker_uses_filament_form_component_action_close_path_without_touching_rendering(): void
    {
        $resource = file_get_contents(app_path('Filament/Resources/PartResource.php'));
        $editPage = file_get_contents(app_path('Filament/Resources/PartResource/Pages/EditPart.php'));
        $categoryPicker = file_get_contents(resource_path('views/filament/forms/category-picker.blade.php'));
        $marketplaceField = file_get_contents(resource_path('views/filament/resources/parts/marketplace-category-field.blade.php'));
        $shell = file_get_contents(resource_path('views/filament/forms/category-field-shell.blade.php'));

        $this->assertStringContainsString("Action::make('chooseCategoryFromTree')", $resource);
        $this->assertStringContainsString("->extraModalWindowAttributes(['class' => 'gps-category-picker-modal'])", $resource);
        $this->assertStringContainsString('->slideOver()', $resource);
        $this->assertStringContainsString("->view('filament.forms.category-picker')", $resource);

        $this->assertStringContainsString('$this->unmountFormComponentAction(true, true);', $editPage);
        $this->assertStringContainsString("\$this->dispatch('close-modal', id: 'form-component-action');", $editPage);
        $this->assertStringContainsString('this.$wire.unmountFormComponentAction(true, true)', $categoryPicker);
        $this->assertStringContainsString('requestAnimationFrame(() =>', $categoryPicker);
        $this->assertStringNotContainsString('.fi-modal-header button', $categoryPicker);

        $this->assertStringContainsString('x-text="`/ ${category.name}`"', $categoryPicker);
        $this->assertStringContainsString('x-bind:aria-label="`Pokaż podkategorie: ${category.name}`"', $categoryPicker);
        $this->assertStringContainsString('x-data="{ categoryDrawerOpen: false }"', $marketplaceField);
        $this->assertStringContainsString('short_display_name', $marketplaceField);
        $this->assertStringContainsString('min-h-10 min-w-0', $shell);
        $this->assertStringContainsString('overflow-hidden truncate whitespace-nowrap', $shell);
        $this->assertStringContainsString('text-ellipsis whitespace-nowrap', $shell);
        $this->assertStringNotContainsString('this.$refs.marketplaceForm.submit();', $categoryPicker);
    }

    public function test_marketplace_category_field_and_sales_channels_picker_submit_flow_are_unchanged(): void
    {
        $marketplaceField = file_get_contents(resource_path('views/filament/resources/parts/marketplace-category-field.blade.php'));
        $categoryPicker = file_get_contents(resource_path('views/filament/forms/category-picker.blade.php'));

        $shell = file_get_contents(resource_path('views/filament/forms/category-field-shell.blade.php'));

        $this->assertStringContainsString('categoryDrawerOpen', $marketplaceField);
        $this->assertStringContainsString('x-data="{ categoryDrawerOpen: false }"', $marketplaceField);
        $this->assertStringContainsString('short_display_name', $marketplaceField);
        $this->assertStringContainsString('min-h-10 min-w-0', $shell);
        $this->assertStringContainsString('overflow-hidden truncate whitespace-nowrap', $shell);
        $this->assertStringContainsString('text-ellipsis whitespace-nowrap', $shell);
        $drawerShell = file_get_contents(resource_path('views/filament/forms/category-drawer-shell.blade.php'));

        $this->assertStringContainsString('data-marketplace-category-tree', $drawerShell);
        $this->assertStringContainsString('fixed inset-y-0 right-0 left-auto z-50 ml-auto w-full max-w-xl flex-col', $drawerShell);
        $this->assertStringContainsString('gps-marketplace-category-drawer', $drawerShell);
        $this->assertStringNotContainsString('left-0', $drawerShell);
        $this->assertStringContainsString('x-on:close-category-drawer.window', $drawerShell);
        $this->assertStringContainsString('$event.detail.drawerId === @js($drawerId)', $drawerShell);
        $this->assertStringNotContainsString('this.$refs.marketplaceForm.submit();', $categoryPicker);
        $this->assertStringContainsString("return;\n            }\n\n            this.$wire.setPartCategoryFromPicker", str_replace("\r\n", "\n", $categoryPicker));
    }
    public function test_main_part_category_picker_initial_render_is_lazy_and_does_not_embed_full_tree(): void
    {
        $resource = file_get_contents(app_path('Filament/Resources/PartResource.php'));
        $categoryPicker = file_get_contents(resource_path('views/filament/forms/category-picker.blade.php'));

        $this->assertStringContainsString("'categories' => []", $resource);
        $this->assertStringContainsString("route('tools.part-category-children'", $resource);
        $this->assertStringContainsString("'lazyLoadOnInit' => true", $resource);
        $this->assertStringNotContainsString("'categories' => self::categoryPickerCategories()", $resource);
        $this->assertStringContainsString('url.searchParams.set(this.lazyChannel ? \'parent_external_category_id\' : \'parent_id\', parentId);', $categoryPicker);
        $this->assertStringContainsString('if (this.lazyLoadOnInit)', $categoryPicker);
    }

    public function test_part_category_children_endpoint_loads_only_roots_and_then_children_from_local_db(): void
    {
        $root = PartCategory::query()->create(['name' => 'Silnik', 'sort_order' => 1]);
        $child = PartCategory::query()->create(['name' => 'Alternatory', 'parent_id' => $root->id, 'category_path' => 'Silnik / Alternatory']);
        PartCategory::query()->create(['name' => 'Rozruszniki', 'parent_id' => $child->id, 'category_path' => 'Silnik / Alternatory / Rozruszniki']);
        $otherRoot = PartCategory::query()->create(['name' => 'Karoseria', 'sort_order' => 2]);

        $this->getJson('/tools/part-category-children?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('source', 'local_db_only')
            ->assertJsonPath('will_make_marketplace_request', false)
            ->assertJsonPath('publish', false)
            ->assertJsonCount(2, 'children')
            ->assertJsonFragment(['id' => $root->id, 'parent_id' => null, 'has_children' => true])
            ->assertJsonFragment(['id' => $otherRoot->id, 'parent_id' => null, 'has_children' => false])
            ->assertJsonMissing(['id' => $child->id]);

        $this->getJson('/tools/part-category-children?token=gps_images_import_2026&parent_id='.$root->id)
            ->assertOk()
            ->assertJsonCount(1, 'children')
            ->assertJsonFragment(['id' => $child->id, 'parent_id' => $root->id, 'has_children' => true])
            ->assertJsonMissing(['id' => $otherRoot->id]);
    }

    public function test_part_category_children_endpoint_search_is_limited_and_does_not_change_marketplace_picker_endpoint(): void
    {
        PartCategory::query()->create(['name' => 'Silnik']);
        $leaf = PartCategory::query()->create(['name' => 'Alternator Bosch', 'category_path' => 'Silnik / Alternator Bosch']);

        $this->getJson('/tools/part-category-children?token=gps_images_import_2026&q=alternator')
            ->assertOk()
            ->assertJsonPath('search', true)
            ->assertJsonCount(1, 'children')
            ->assertJsonFragment(['id' => $leaf->id, 'path' => 'Silnik / Alternator Bosch']);

        $marketplaceField = file_get_contents(resource_path('views/filament/resources/parts/marketplace-category-field.blade.php'));
        $this->assertStringContainsString("route('tools.marketplace-category-children'", $marketplaceField);
        $this->assertStringContainsString("'categories' => []", $marketplaceField);
    }

}
