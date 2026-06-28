<?php

namespace Tests\Feature;

use Tests\TestCase;

class PartCategoryPickerUiTest extends TestCase
{
    public function test_shared_category_field_truncates_visible_label_and_exposes_title_binding(): void
    {
        $shell = file_get_contents(resource_path('views/filament/forms/category-field-shell.blade.php'));
        $marketplace = file_get_contents(resource_path('views/filament/resources/parts/marketplace-category-field.blade.php'));

        $this->assertStringContainsString('truncate', $shell);
        $this->assertStringContainsString('min-w-0 flex-1', $shell);
        $this->assertStringContainsString('x-bind:title', $marketplace);
        $this->assertStringContainsString('categoryTitle', $marketplace);
    }

    public function test_marketplace_category_picker_uses_shared_right_drawer_shell(): void
    {
        $marketplace = file_get_contents(resource_path('views/filament/resources/parts/marketplace-category-field.blade.php'));
        $drawer = file_get_contents(resource_path('views/filament/forms/category-drawer-shell.blade.php'));

        $this->assertStringContainsString("@include('filament.forms.category-drawer-shell'", $marketplace);
        $this->assertStringContainsString('fixed inset-y-0 right-0 z-50 w-full max-w-xl', $drawer);
        $this->assertStringContainsString('fixed inset-0 z-40 bg-gray-950/50', $drawer);
        $this->assertStringContainsString('x-teleport="body"', $marketplace);
    }

    public function test_choose_button_is_non_submit_and_does_not_post_marketplace_form(): void
    {
        $picker = file_get_contents(resource_path('views/filament/forms/category-picker.blade.php'));
        $marketplace = file_get_contents(resource_path('views/filament/resources/parts/marketplace-category-field.blade.php'));

        $this->assertStringContainsString('type="button"', $picker);
        $this->assertStringContainsString('x-on:click="saveSelectedCategory()"', $picker);
        $this->assertStringContainsString("'saveUrl' => null", $marketplace);
        $this->assertStringContainsString('marketplace-category-selected', $picker);
        $this->assertStringNotContainsString('marketplaceForm.submit()', $picker);
    }
}
