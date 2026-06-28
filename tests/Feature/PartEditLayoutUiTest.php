<?php

namespace Tests\Feature;

use Tests\TestCase;

class PartEditLayoutUiTest extends TestCase
{
    public function test_part_edit_actions_keep_expected_order_and_layout_hooks(): void
    {
        $editPage = file_get_contents(app_path('Filament/Resources/PartResource/Pages/EditPart.php'));

        $headerStart = strpos($editPage, 'protected function getHeaderActions(): array');
        $footerStart = strpos($editPage, 'protected function getFormActions(): array');
        $publishActionStart = strpos($editPage, 'private function getSaveAndPublishAction(string $name): Actions\\Action');

        $this->assertIsInt($headerStart);
        $this->assertIsInt($footerStart);
        $this->assertIsInt($publishActionStart);

        $headerActions = substr($editPage, $headerStart, $footerStart - $headerStart);
        $footerActions = substr($editPage, $footerStart, $publishActionStart - $footerStart);

        $this->assertLessThan(strpos($headerActions, "Actions\\Action::make('saveHeader')"), strpos($headerActions, "getSaveAndPublishAction('markListingReadyHeader')"));
        $this->assertLessThan(strpos($headerActions, 'Actions\\DeleteAction::make()'), strpos($headerActions, "Actions\\Action::make('saveHeader')"));

        $this->assertLessThan(strpos($footerActions, 'getSaveFormAction()'), strpos($footerActions, "getSaveAndPublishAction('markListingReadyFooter')"));
        $this->assertLessThan(strpos($footerActions, 'getCancelFormAction()'), strpos($footerActions, 'getSaveFormAction()'));

        $this->assertStringContainsString("'class' => 'gps-part-edit-layout-action gps-part-edit-layout-action--publish' . (str_ends_with($name, 'Footer') ? ' gps-part-edit-footer-action' : '')", $editPage);
        $this->assertStringContainsString('gps-part-edit-layout-action gps-part-edit-layout-action--save', $editPage);
        $this->assertStringContainsString('gps-part-edit-layout-action gps-part-edit-layout-action--delete', $editPage);
        $this->assertStringContainsString('gps-part-edit-footer-action gps-part-edit-layout-action--cancel', $editPage);
        $this->assertStringContainsString('gps-part-edit-layout-action', $editPage);
    }

    public function test_part_edit_header_actions_align_to_card_rail_without_form_container_changes(): void
    {
        $css = file_get_contents(public_path('css/filament-admin.css'));

        $this->assertStringContainsString('--gps-part-edit-card-max-width: 54rem;', $css);
        $this->assertStringContainsString('.gps-part-form / .fi-section (about 54rem wide, right edge around x=1210)', $css);
        $this->assertStringContainsString('.fi-header / .fi-form are wider page wrappers (right edge around x=1291)', $css);
        $this->assertStringContainsString('use a relative right offset on .fi-ac itself instead', $css);
        $this->assertStringContainsString('.fi-main:has(.gps-part-edit-layout-action) .fi-header .fi-ac {', $css);
        $this->assertStringContainsString('max-width: min(100%, var(--gps-part-edit-card-max-width)) !important;', $css);
        $this->assertStringContainsString('position: relative !important;', $css);
        $this->assertStringContainsString('right: calc((100% - min(100%, var(--gps-part-edit-card-max-width))) / 2) !important;', $css);
        $this->assertStringContainsString('do not resize or reposition .fi-header, .fi-form, the edit', $css);
        $this->assertStringNotContainsString('.fi-main:has(.gps-part-edit-layout-action) :where(.fi-header, form, form .fi-form-actions) {', $css);
        $this->assertStringNotContainsString('--gps-part-edit-form-container-max-width', $css);
        $this->assertStringContainsString('justify-content: flex-end !important;', $css);
        $this->assertStringContainsString('justify-content: flex-start !important;', $css);
    }
}
