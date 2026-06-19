<?php

namespace Tests\Feature;

use Tests\TestCase;

class StorefrontAccessLockTest extends TestCase
{
    public function test_storefront_home_requires_password_before_rendering_public_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Storefront staging');
        $response->assertSee('Odblokuj storefront');
        $response->assertSessionHas('storefront_intended_url', url('/'));
    }

    public function test_invalid_password_returns_error_message(): void
    {
        $response = $this
            ->from('/')
            ->post('/storefront-unlock', ['password' => 'wrong-password']);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors(['password' => 'Nieprawidłowe hasło']);
        $this->assertFalse((bool) session('storefront_unlocked'));
    }

    public function test_valid_password_unlocks_storefront_and_redirects_to_intended_url(): void
    {
        $this->withSession(['storefront_intended_url' => url('/kontakt')]);

        $response = $this->post('/storefront-unlock', ['password' => 'talarekr']);

        $response->assertRedirect(url('/kontakt'));
        $response->assertSessionHas('storefront_unlocked', true);
    }

    public function test_tools_routes_are_not_protected_by_storefront_lock(): void
    {
        $response = $this->get('/tools/check-product-image');

        $response->assertDontSee('Storefront staging');
    }

    public function test_lock_route_clears_storefront_session_and_shows_password_screen(): void
    {
        $response = $this
            ->withSession(['storefront_unlocked' => true])
            ->get('/lock');

        $response->assertOk();
        $response->assertSee('Storefront staging');
        $response->assertSessionMissing('storefront_unlocked');
        $response->assertSessionHas('storefront_intended_url', route('storefront.home'));
    }
}
