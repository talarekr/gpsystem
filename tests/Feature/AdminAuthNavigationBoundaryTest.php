<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminAuthNavigationBoundaryTest extends TestCase
{
    public function test_login_is_a_canonical_livewire_document_with_the_auth_guard_before_assets(): void
    {
        config()->set('product-hub.ui.filament_spa_enabled', true);

        $html = $this->get('/admin/login')
            ->assertOk()
            ->assertSee('wire:submit="authenticate"', false)
            ->assertDontSee('action="/admin/login"', false)
            ->getContent();

        $boundary = strpos($html, '__gpsAdminAuthBoundaryInstalled');
        $filamentSupport = strpos($html, '/js/filament/support/support.js');
        $filamentApp = strpos($html, '/js/filament/filament/app.js');
        $livewire = strpos($html, '/livewire/livewire');

        $this->assertNotFalse($boundary);
        $this->assertNotFalse($filamentSupport);
        $this->assertNotFalse($filamentApp);
        $this->assertNotFalse($livewire);
        $this->assertLessThan($filamentSupport, $boundary);
        $this->assertLessThan($filamentApp, $filamentSupport);
        $this->assertLessThan($livewire, $filamentApp);
    }

    public function test_auth_guard_covers_http_and_livewire_json_redirects_without_duplicate_bootstrap(): void
    {
        $view = file_get_contents(resource_path('views/filament/admin-auth-navigation-boundary.blade.php'));

        $this->assertStringContainsString('response.redirected', $view);
        $this->assertStringContainsString("key === 'redirect'", $view);
        $this->assertStringContainsString("'/admin/login'", $view);
        $this->assertStringContainsString("'/admin/logout'", $view);
        $this->assertStringNotContainsString('data-navigate-once', preg_replace('/\{\{--.*?--\}\}/s', '', $view));
        $this->assertStringNotContainsString('livewire.min.js', $view);
        $this->assertStringNotContainsString('alpine', strtolower($view));
    }
}
