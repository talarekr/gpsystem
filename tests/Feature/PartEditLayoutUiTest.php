<?php

namespace Tests\Feature;

use Tests\TestCase;

class PartEditLayoutUiTest extends TestCase
{
    public function test_part_edit_actions_keep_expected_order_and_layout_hooks(): void
    {
        $editPage = file_get_contents(app_path('Filament/Resources/PartResource/Pages/EditPart.php'));
        $view = file_get_contents(resource_path('views/filament/resources/parts/pages/edit-part.blade.php'));

        $this->assertStringContainsString("protected static string \$view = 'filament.resources.parts.pages.edit-part';", $editPage);

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

        $this->assertStringContainsString('gps-part-edit-page-header', $view);
        $this->assertStringContainsString('Edytuj część', $view);
        $this->assertStringContainsString(':actions="$this->getCachedHeaderActions()"', $view);
        $this->assertStringContainsString(':actions="$this->getCachedFormActions()"', $view);
        $this->assertSame(1, substr_count($view, 'getCachedHeaderActions'));
    }

    public function test_part_edit_custom_header_form_and_footer_share_one_container_rule(): void
    {
        $css = file_get_contents(public_path('css/filament-admin.css'));

        $this->assertStringContainsString('After syncing public CSS, the production layout still showed', $css);
        $this->assertStringContainsString('--gps-part-edit-form-container-max-width: var(--gps-admin-content-max-width);', $css);
        $this->assertStringContainsString('.fi-main:has(.gps-part-edit-page) .fi-header {', $css);
        $this->assertStringContainsString('display: none !important;', $css);
        $this->assertStringContainsString('.fi-main:has(.gps-part-edit-page) :where(.gps-part-edit-page-header, .gps-part-edit-page > form, .gps-part-edit-page > form .fi-form-actions) {', $css);
        $this->assertStringContainsString('max-width: var(--gps-part-edit-form-container-max-width) !important;', $css);
        $this->assertStringContainsString('padding-left: 0 !important;', $css);
        $this->assertStringContainsString('padding-right: 0 !important;', $css);
        $this->assertStringContainsString('justify-content: flex-end !important;', $css);
        $this->assertStringContainsString('justify-content: flex-start !important;', $css);
        $this->assertStringNotContainsString('.fi-main:has(.gps-part-edit-layout-action) .fi-header-actions', $css);
    }
}
