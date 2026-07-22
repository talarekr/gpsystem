<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PartMarketplacePriceSyncStage2Test extends TestCase
{
    use RefreshDatabase;

    public function test_price_sync_on_part_save_is_disabled_by_default(): void
    {
        Http::fake();

        $part = Part::query()->create(['name' => 'GPSwiss disabled sync', 'price' => 100, 'ovoko_price' => 120]);
        $part->update(['price' => 150]);

        $this->assertDatabaseCount('marketplace_sync_logs', 0);
        Http::assertNothingSent();
    }

    public function test_enabled_stage_two_creates_diagnostic_logs_without_marketplace_writes(): void
    {
        config()->set('marketplace.price_sync_on_part_save_enabled', true);
        config()->set('marketplace.price_sync_channels', 'allegro,ovoko,ebay_de');
        Http::fake();

        $part = Part::query()->create(['name' => 'GPSwiss staged sync', 'price' => 100, 'ovoko_price' => 120]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => 'ALG-1', 'price' => 100, 'currency' => 'PLN']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => 'OVO-1', 'price' => 120, 'currency' => 'PLN']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ebay', 'external_offer_id' => 'EBAY-DE-1', 'price' => 125, 'currency' => 'EUR']);

        $part->update(['price' => 200, 'ovoko_price' => 240]);

        $this->assertSame('200.00', (string) $part->fresh()->allegro_price);
        $this->assertSame('250.00', (string) $part->fresh()->ebay_price);
        $this->assertSame(3, MarketplaceSyncLog::query()->where('action', 'part_save_price_sync_plan')->where('status', 'skipped')->count());
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'allegro', 'action' => 'part_save_price_sync_plan', 'external_id' => 'ALG-1']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'part_save_price_sync_plan', 'external_id' => 'OVO-1']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ebay', 'action' => 'part_save_price_sync_plan', 'external_id' => 'EBAY-DE-1']);

        $payloads = MarketplaceSyncLog::query()->pluck('payload');
        $this->assertTrue($payloads->every(fn (array $payload): bool => $payload['marketplace_write'] === false));
        $this->assertTrue($payloads->every(fn (array $payload): bool => $payload['protected_fields_not_touched'] === ['quantity', 'status', 'publication', 'description', 'photos', 'category']));
        Http::assertNothingSent();
    }

    public function test_diagnostics_endpoint_reports_read_only_price_sources(): void
    {
        $part = Part::query()->create(['name' => 'GPSwiss diagnostics', 'price' => 80, 'ovoko_price' => 90]);

        $this->getJson(route('tools.marketplace-price-sync-diagnostics', ['part_id' => $part->id]))
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('live_api_tests', false)
            ->assertJsonPath('price_sources.storefront', 'parts.price PLN')
            ->assertJsonPath('part_preview.allegro.target_price', 80.0)
            ->assertJsonPath('part_preview.ovoko.target_price', 90.0)
            ->assertJsonPath('part_preview.ebay_de.target_price', 100.0);
    }
}
