<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource\Pages\EditPart;
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
            ->assertSet('data.category_id', $newCategory->id);

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
            'category_id' => $oldCategory->id,
        ]);
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
        $this->assertStringContainsString('closeFilamentCategoryModalViaCloseButton()', $html);
        $this->assertStringContainsString(".querySelector?.('.fi-modal-header :is(.fi-modal-close-btn, .fi-modal-close-button, [aria-label=\"Close\"], [aria-label=\"Zamknij\"])')", $html);
        $this->assertStringContainsString('closeButton.click();', $html);
        $this->assertStringContainsString("this.\$dispatch('close-category-drawer', { drawerId: this.drawerId });", $html);
        $this->assertStringContainsString('categoryDrawerOpen = false', $html);
        $this->assertMatchesRegularExpression('/closeCategoryPicker\(\);\s*this\.selectedId = null;/s', $html);
        $this->assertStringNotContainsString('$refs.marketplaceForm.submit();\n                this.closeCategoryPicker();', $html);
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
