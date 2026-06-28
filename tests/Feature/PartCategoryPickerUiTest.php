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
        $this->assertStringContainsString('this.$refs.marketplaceForm.submit();', $categoryPicker);
    }

    public function test_marketplace_category_field_and_sales_channels_picker_submit_flow_are_unchanged(): void
    {
        $marketplaceField = file_get_contents(resource_path('views/filament/resources/parts/marketplace-category-field.blade.php'));
        $categoryPicker = file_get_contents(resource_path('views/filament/forms/category-picker.blade.php'));

        $this->assertStringContainsString('categoryDrawerOpen', $marketplaceField);
        $this->assertStringContainsString('x-data="{ categoryDrawerOpen: false }"', $marketplaceField);
        $drawerShell = file_get_contents(resource_path('views/filament/forms/category-drawer-shell.blade.php'));

        $this->assertStringContainsString('data-marketplace-category-tree', $drawerShell);
        $this->assertStringContainsString('x-on:close-category-drawer.window', $drawerShell);
        $this->assertStringContainsString('$event.detail.drawerId === @js($drawerId)', $drawerShell);
        $this->assertStringContainsString('this.$refs.marketplaceForm.submit();', $categoryPicker);
        $this->assertStringContainsString("return;\n            }\n\n            this.$wire.setPartCategoryFromPicker", str_replace("\r\n", "\n", $categoryPicker));
    }
}
