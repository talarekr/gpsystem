<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\ShopEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceSupportReadOnlyDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose_endpoint_is_read_only_and_does_not_write_shop_events(): void
    {
        Http::preventStrayRequests();

        MarketplaceAccount::query()->create([
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'code' => 'allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.pl',
            'api_credentials' => ['access_token' => 'secret', 'scope' => 'allegro:api:orders:read'],
        ]);

        $before = ShopEvent::query()->count();

        $this->getJson('/admin/tools/support-sync/allegro/diagnose?json=1')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('marketplace', 'allegro')
            ->assertJsonPath('no_mutation', true)
            ->assertJsonPath('authentication_ready', true)
            ->assertJsonPath('sample_count', 0)
            ->assertJsonPath('probe_executed', false);

        $this->assertSame($before, ShopEvent::query()->count());
    }

    public function test_preview_maps_local_order_without_marketplace_mutation(): void
    {
        Http::preventStrayRequests();

        Order::query()->create([
            'order_number' => 'ALLEGRO-123',
            'marketplace_order_id' => 'EXT-123',
            'marketplace' => 'allegro',
            'status' => 'new',
            'currency' => 'PLN',
        ]);

        $this->getJson('/admin/tools/support-sync/preview?marketplace=allegro&json=1')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('marketplace', 'allegro')
            ->assertJsonPath('sample.0.normalized_status', 'unknown')
            ->assertJsonPath('sample.0.requires_action', false)
            ->assertJsonPath('sample.0.unread', false)
            ->assertJsonPath('sample.0.external_order_id', 'EXT-123')
            ->assertJsonPath('sample.0.no_mutation', true);
    }

    public function test_ovoko_diagnose_reports_undocumented_support_capabilities(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/admin/tools/support-sync/ovoko/diagnose?json=1')
            ->assertOk()
            ->assertJsonPath('marketplace', 'ovoko')
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('messages_supported', false)
            ->assertJsonPath('returns_supported', false)
            ->assertJsonPath('complaints_supported', false)
            ->assertJsonPath('no_mutation', true);
    }
}
