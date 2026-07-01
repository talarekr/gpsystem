<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\ManualMarketplaceLinkMappingService;
use App\Services\Marketplace\ManualMarketplaceMappingConflictException;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManualMarketplaceLinkMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_mapping_column_is_inserted_without_changing_channels_partial(): void
    {
        $cardList = file_get_contents(resource_path('views/filament/resources/parts/partials/parts-card-list.blade.php'));
        $channelsPartial = file_get_contents(resource_path('views/filament/resources/parts/table-channels.blade.php'));
        $manualPartial = file_get_contents(resource_path('views/filament/resources/parts/table-manual-marketplace-links.blade.php'));

        $this->assertStringContainsString('<div>Kanały sprzedaży</div><div>Mapowanie</div><div>Status</div>', $cardList);
        $this->assertStringNotContainsString('saveManualMarketplaceLink', $channelsPartial);
        $this->assertStringContainsString('Allegro', $manualPartial);
        $this->assertStringContainsString('Ovoko', $manualPartial);
        $this->assertStringContainsString('URL {{ $label }}', $manualPartial);
        $this->assertStringContainsString('Zapisz', $manualPartial);
    }

    public function test_parses_allegro_and_ovoko_urls(): void
    {
        $service = app(ManualMarketplaceLinkMappingService::class);

        $this->assertSame('18724841486', $service->parseAllegroOfferId('https://allegro.pl/oferta/bmw-f20-f21-klapa-tylna-bagaznika-w-kolor-igla-18724841486'));
        $this->assertSame('11705', $service->parseOvokoPartId('https://ovoko.pl/czesci-samochodowe/hgf11705-3c1955419a-volkswagen-passat-b6-mechanizm-i-silniczek-wycieraczek-szyby-przedniej-czolowej'));
    }

    public function test_save_creates_local_mapping_and_status_resolver_shows_link_without_api_write(): void
    {
        $part = Part::query()->create(['name' => 'Test part', 'sku' => 'GPS-1', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN', 'ovoko_price' => 123]);

        $result = app(ManualMarketplaceLinkMappingService::class)->save($part, 'ovoko', 'https://ovoko.pl/czesci-samochodowe/hgf11705-3c1955419a-test');

        $this->assertSame('created', $result['action']);
        $this->assertDatabaseHas('marketplace_listings', [
            'part_id' => $part->id,
            'marketplace' => 'ovoko',
            'external_offer_id' => '11705',
            'external_listing_id' => '11705',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
        ]);
        $this->assertDatabaseCount('marketplace_sync_logs', 0);

        $part->load('marketplaceListings');
        $row = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part))->firstWhere('key', 'ovoko');

        $this->assertTrue($row['listed']);
        $this->assertSame('https://ovoko.pl/czesci-samochodowe/hgf11705-3c1955419a-test', $row['url']);
    }


    public function test_allegro_diagnostics_reports_ready_local_mapping_without_writes(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['name' => 'Test part', 'sku' => 'GPS-ALG', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN']);

        app(ManualMarketplaceLinkMappingService::class)->save($part, 'allegro', 'https://allegro.pl/oferta/bmw-18724841486');

        $this->getJson('/admin/tools/marketplace/manual-link-mapping/diagnostics?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('part_id', $part->id)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('sync_triggered', false)
            ->assertJsonPath('marketplace_listings.0.marketplace', 'allegro')
            ->assertJsonPath('marketplace_listings.0.external_offer_id', '18724841486')
            ->assertJsonPath('marketplace_listings.0.url', 'https://allegro.pl/oferta/bmw-18724841486')
            ->assertJsonPath('marketplace_listings.0.stock_sync_mapping_ready', true)
            ->assertJsonPath('marketplace_listings.0.resolved_is_listed', true)
            ->assertJsonPath('marketplace_listings.0.link_visible', true);

        $this->assertDatabaseCount('marketplace_sync_logs', 0);
    }

    public function test_ovoko_diagnostics_reports_ready_local_mapping_without_writes(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['name' => 'Test part', 'sku' => 'GPS-OV', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN']);

        app(ManualMarketplaceLinkMappingService::class)->save($part, 'ovoko', 'https://ovoko.pl/czesci-samochodowe/hgf11705-test');

        $this->getJson('/admin/tools/marketplace/manual-link-mapping/diagnostics?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('sync_triggered', false)
            ->assertJsonPath('marketplace_listings.0.marketplace', 'ovoko')
            ->assertJsonPath('marketplace_listings.0.external_listing_id', '11705')
            ->assertJsonPath('marketplace_listings.0.metadata.ovoko_part_id', '11705')
            ->assertJsonPath('marketplace_listings.0.url', 'https://ovoko.pl/czesci-samochodowe/hgf11705-test')
            ->assertJsonPath('marketplace_listings.0.stock_sync_mapping_ready', true)
            ->assertJsonPath('marketplace_listings.0.resolved_is_listed', true)
            ->assertJsonPath('marketplace_listings.0.link_visible', true);

        $this->assertDatabaseCount('marketplace_sync_logs', 0);
    }

    public function test_diagnostics_reports_not_ready_when_listing_has_only_url_without_external_id(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['name' => 'Test part', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'url' => 'https://allegro.pl/oferta/without-id']);

        $this->getJson('/admin/tools/marketplace/manual-link-mapping/diagnostics?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('marketplace_listings.0.url', 'https://allegro.pl/oferta/without-id')
            ->assertJsonPath('marketplace_listings.0.stock_sync_mapping_ready', false)
            ->assertJsonPath('marketplace_listings.0.reason', 'missing_allegro_offer_id')
            ->assertJsonPath('marketplace_listings.0.resolved_is_listed', false)
            ->assertJsonPath('marketplace_listings.0.link_visible', false);
    }

    public function test_save_updates_url_for_same_id_without_duplicate(): void
    {
        $part = Part::query()->create(['name' => 'Test part', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '18724841486', 'external_listing_id' => '18724841486', 'url' => 'https://old.example']);

        app(ManualMarketplaceLinkMappingService::class)->save($part, 'allegro', 'https://allegro.pl/oferta/bmw-18724841486');

        $this->assertSame(1, MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', 'allegro')->count());
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '18724841486', 'url' => 'https://allegro.pl/oferta/bmw-18724841486']);
    }

    public function test_conflict_existing_different_id_blocks_save(): void
    {
        $part = Part::query()->create(['name' => 'Test part', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '111', 'external_listing_id' => '111', 'url' => 'https://old.example']);

        $this->expectException(ManualMarketplaceMappingConflictException::class);
        $this->expectExceptionMessage('existing_mapping_conflict');

        app(ManualMarketplaceLinkMappingService::class)->save($part, 'allegro', 'https://allegro.pl/oferta/name-222');
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Owner Admin',
            'email' => 'owner'.uniqid().'@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
