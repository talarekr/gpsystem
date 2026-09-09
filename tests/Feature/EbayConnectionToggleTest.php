<?php

namespace Tests\Feature;

use App\Http\Middleware\Authenticate;
use App\Models\MarketplaceAccount;
use App\Services\Marketplace\EbayConnectionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EbayConnectionToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_page_uses_expected_url_and_performs_no_ebay_request(): void
    {
        config(['marketplace.external_api_writes_enabled' => false]);
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'name' => 'eBay DE', 'code' => 'ebay_de', 'api_enabled' => true]);
        Http::fake();

        $this->withoutMiddleware()->get('/admin/tools/marketplace/ebay-connection-toggle')
            ->assertOk()
            ->assertSee('Global eBay write connection')
            ->assertSee('DISABLED')
            ->assertSee('ENABLE_EBAY_WRITE_CONNECTION');

        Http::assertNothingSent();
    }

    public function test_toggle_requires_exact_confirmation_and_only_changes_local_setting(): void
    {
        config(['marketplace.external_api_writes_enabled' => false]);
        $account = MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'name' => 'eBay DE', 'code' => 'ebay_de', 'api_enabled' => true, 'api_settings' => ['marketplace_id' => 'EBAY_DE']]);
        Http::fake();
        $url = '/admin/tools/marketplace/ebay-connection-toggle';

        $this->withoutMiddleware()->post($url, ['enabled' => '1', 'confirm' => 'wrong'])->assertForbidden();
        $this->assertFalse(app(EbayConnectionGate::class)->writeEnabled($account->fresh()));

        $this->withoutMiddleware()->post($url, ['enabled' => '1', 'confirm' => 'ENABLE_EBAY_WRITE_CONNECTION'])
            ->assertRedirect(route('admin.tools.marketplace.ebay-connection-toggle'));

        $account->refresh();
        $this->assertTrue(data_get($account->api_settings, EbayConnectionGate::SETTING_KEY));
        $this->assertTrue(app(EbayConnectionGate::class)->writeEnabled($account));
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ebay', 'action' => 'ebay_write_connection_toggle', 'status' => 'success']);
        Http::assertNothingSent();
    }

    public function test_routes_are_auth_and_admin_protected_and_runner_links_to_toggle(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->keyBy(fn ($route) => $route->getName());
        foreach (['admin.tools.marketplace.ebay-connection-toggle', 'admin.tools.marketplace.ebay-connection-toggle.update'] as $name) {
            $middleware = $routes[$name]->gatherMiddleware();
            $this->assertTrue(in_array('auth', $middleware, true) || in_array(Authenticate::class, $middleware, true));
            $this->assertContains('admin.panel', $middleware);
            $this->assertContains('throttle:tools', $middleware);
        }

        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'name' => 'eBay DE', 'code' => 'ebay_de', 'api_enabled' => true, 'api_settings' => [EbayConnectionGate::SETTING_KEY => false]]);
        $this->withoutMiddleware()->get('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-runner')
            ->assertOk()
            ->assertSee(route('admin.tools.marketplace.ebay-connection-toggle'), false)
            ->assertSee('eBay write connection jest wyłączone');
    }
}
