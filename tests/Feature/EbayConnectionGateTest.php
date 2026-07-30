<?php

namespace Tests\Feature;

use App\Exceptions\EbayConnectionDisabledException;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\EbayConnectionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EbayConnectionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_ebay_is_enabled_by_default_and_can_be_toggled_without_touching_other_integrations(): void
    {
        $gate = app(EbayConnectionGate::class);
        $this->assertTrue($gate->isEbayEnabled());
        $gate->setEnabled(false, null);
        $this->assertFalse($gate->isEbayEnabled());
        $this->assertDatabaseHas('system_settings', ['key' => 'marketplace.ebay.enabled', 'value' => 'false']);
        $this->assertDatabaseCount('system_settings', 1);
        $gate->setEnabled(true, null);
        $this->assertTrue($gate->isEbayEnabled());
        $this->assertSame(['ebay_connection_disabled', 'ebay_connection_enabled'], MarketplaceSyncLog::query()->pluck('action')->all());
    }

    public function test_disabled_gate_blocks_sync_and_every_external_ebay_http_request(): void
    {
        $gate = app(EbayConnectionGate::class);
        $gate->setEnabled(false, null);
        Http::fake();
        try {
            Http::get('https://api.ebay.com/sell/inventory/v1/inventory_item');
            $this->fail('Disabled eBay request was not blocked.');
        } catch (EbayConnectionDisabledException $exception) {
            $this->assertStringContainsString('eBay jest aktualnie wyłączony', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ebay', 'action' => 'ebay_action_blocked_connection_disabled']);
    }

    public function test_admin_can_disable_and_enable_using_confirmed_csrf_protected_post(): void
    {
        Role::findOrCreate(UserRole::OwnerAdmin->value);
        $user = User::factory()->create();
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);

        $this->post('/admin/tools/marketplace/ebay-connection-toggle', ['confirm' => 'disable-ebay'])->assertRedirect();
        $this->assertFalse(app(EbayConnectionGate::class)->isEbayEnabled());
        $this->post('/admin/tools/marketplace/ebay-connection-toggle', ['confirm' => 'wrong'])->assertSessionHas('error');
        $this->assertFalse(app(EbayConnectionGate::class)->isEbayEnabled());
        $this->post('/admin/tools/marketplace/ebay-connection-toggle', ['confirm' => 'enable-ebay'])->assertRedirect();
        $this->assertTrue(app(EbayConnectionGate::class)->isEbayEnabled());
    }

    public function test_enabled_gate_does_not_block_ebay_or_other_marketplaces(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        Http::get('https://api.ebay.com/status');
        Http::get('https://api.allegro.pl/status');
        Http::get('https://api.ovoko.example/status');
        Http::assertSentCount(3);
    }
}
