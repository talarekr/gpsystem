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

    public function test_replace_dry_run_reports_allegro_mapping_repair_without_writes(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['id' => 7865, 'name' => 'Passat DSG', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '18723823450', 'external_listing_id' => '18723823450', 'url' => 'https://allegro.pl/oferta/old-18723823450', 'status' => 'ACTIVE', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'last_api_status' => 'ACTIVE']);

        $this->getJson('/admin/tools/marketplace/manual-link-mapping/replace-dry-run?part_id=7865&marketplace=allegro&url='.urlencode('https://allegro.pl/oferta/vw-passat-b6-3-2-fsi-vr6-automatyczna-skrzynia-biegow-dsg-lre-18723770245'))
            ->assertOk()
            ->assertJsonPath('action', 'replace_mapping')
            ->assertJsonPath('previous_external_id', '18723823450')
            ->assertJsonPath('new_external_id', '18723770245')
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('sync_triggered', false)
            ->assertJsonPath('publish', false)
            ->assertJsonPath('relist', false)
            ->assertJsonPath('end', false)
            ->assertJsonPath('current_active_local_mapped_listings.0.external_offer_id', '18723823450');

        $this->assertDatabaseHas('marketplace_listings', ['part_id' => 7865, 'external_offer_id' => '18723823450', 'status' => 'ACTIVE']);
    }

    public function test_replace_apply_requires_confirm(): void
    {
        $this->actingAsAdminUser();
        Part::query()->create(['id' => 7865, 'name' => 'Passat DSG', 'quantity' => 1, 'status' => 'ready']);

        $this->getJson('/admin/tools/marketplace/manual-link-mapping/replace-apply?part_id=7865&marketplace=allegro&url='.urlencode('https://allegro.pl/oferta/vw-passat-b6-18723770245'))
            ->assertStatus(422)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('sync_triggered', false);
    }

    public function test_replace_apply_updates_local_active_mapping_and_description_dry_run_uses_new_offer(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['id' => 7865, 'name' => 'Passat DSG', 'quantity' => 1, 'status' => 'ready', 'description' => 'DSG gearbox']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '18723823450', 'external_listing_id' => '18723823450', 'url' => 'https://allegro.pl/oferta/old-18723823450', 'status' => 'ACTIVE', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'last_api_status' => 'ACTIVE']);
        $url = 'https://allegro.pl/oferta/vw-passat-b6-3-2-fsi-vr6-automatyczna-skrzynia-biegow-dsg-lre-18723770245';

        $this->getJson('/admin/tools/marketplace/manual-link-mapping/replace-apply?part_id=7865&marketplace=allegro&url='.urlencode($url).'&confirm=replace-marketplace-link-mapping')
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('previous_external_id', '18723823450')
            ->assertJsonPath('new_external_id', '18723770245')
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('sync_triggered', false);

        $this->assertSame(1, MarketplaceListing::query()->where('part_id', 7865)->whereIn('marketplace', ['allegro', 'allegro_main'])->where('sync_status', 'mapped')->count());
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => 7865, 'marketplace' => 'allegro', 'external_offer_id' => '18723770245', 'external_listing_id' => '18723770245', 'url' => $url, 'status' => 'ACTIVE', 'sync_status' => 'mapped']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => 7865, 'marketplace' => 'allegro', 'external_offer_id' => '18723823450', 'status' => 'replaced', 'sync_status' => 'archived']);

        $part->load('marketplaceListings');
        $row = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part))->firstWhere('key', 'allegro');
        $this->assertSame('18723770245', $row['external_offer_id']);

        $this->getJson('/admin/tools/allegro/offers/description-update-dry-run?part_id=7865')
            ->assertOk()
            ->assertJsonPath('offer_id', '18723770245');

        $this->assertDatabaseCount('marketplace_sync_logs', 0);
    }


    public function test_saving_same_ovoko_id_is_idempotent_and_refreshes_url(): void
    {
        $part = Part::query()->create(['name' => 'VOLVO XC60 sterownik moduł świateł xenon', 'sku' => 'GPS-7132', 'part_number' => '31427776', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '7249', 'external_listing_id' => '7249', 'price' => 321.45, 'currency' => 'PLN', 'status' => 'imported', 'sync_status' => 'mapped', 'match_status' => 'confirmed']);

        $url = 'https://ovoko.pl/czesci-samochodowe/hgf7249-31427776-volvo-xc60-sterownik-modul-swiatel-xenon';
        $result = app(ManualMarketplaceLinkMappingService::class)->save($part, 'ovoko', $url);

        $this->assertSame('updated', $result['action']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '7249', 'external_listing_id' => '7249', 'url' => $url]);
        $this->assertSame(1, MarketplaceListing::query()->where('marketplace', 'ovoko')->where('external_offer_id', '7249')->count());
    }

    public function test_ovoko_backfill_command_dry_run_and_apply_fill_missing_url_and_part_price(): void
    {
        $part = Part::query()->create(['name' => 'VOLVO XC60 sterownik moduł świateł xenon', 'sku' => 'GPS-7132', 'part_number' => '31427776', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN', 'ovoko_price' => null]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '7249', 'external_listing_id' => '7249', 'price' => 321.45, 'currency' => 'PLN', 'status' => 'imported', 'sync_status' => 'mapped', 'match_status' => 'confirmed']);

        $this->artisan('marketplace:backfill-ovoko-links', ['--part-id' => $part->id, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko', 'url' => null]);
        $this->assertNull($part->fresh()->ovoko_price);

        $this->artisan('marketplace:backfill-ovoko-links', ['--part-id' => $part->id, '--apply' => true])->assertSuccessful();

        $part->refresh()->load('marketplaceListings');
        $listing = $part->marketplaceListings->firstWhere('marketplace', 'ovoko');
        $this->assertSame('https://ovoko.pl/czesci-samochodowe/hgf7249-31427776-volvo-xc60-sterownik-modul-swiatel-xenon', $listing->url);
        $this->assertSame('321.45', (string) $part->ovoko_price);

        $row = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part))->firstWhere('key', 'ovoko');
        $this->assertSame('321,45 zł', $row['price']);
        $this->assertSame($listing->url, $row['url']);
    }

    public function test_browser_ovoko_backfill_parses_flags_and_debugs_part_listings(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['name' => 'VOLVO XC60 sterownik moduł świateł xenon', 'sku' => 'GPS-7132', 'part_number' => '31427776', 'quantity' => 0, 'status' => 'archived', 'currency' => 'PLN', 'ovoko_price' => null]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '7249', 'external_listing_id' => '7249', 'price' => 321.45, 'currency' => 'PLN', 'status' => 'inactive', 'sync_status' => 'mapped', 'match_status' => 'confirmed']);

        $this->getJson('/admin/tools/ovoko/backfill-links?part_id='.$part->id.'&missing_only=0&force=1&apply=0&debug=1')
            ->assertOk()
            ->assertJsonPath('parsed_part_id', $part->id)
            ->assertJsonPath('parsed_force', true)
            ->assertJsonPath('parsed_missing_only', false)
            ->assertJsonPath('parsed_apply', false)
            ->assertJsonPath('debug_info.part_exists', true)
            ->assertJsonPath('debug_info.marketplace_listings_for_part.0.external_offer_id', '7249')
            ->assertJsonPath('debug_info.listing_filter_diagnostics.0.ovoko_id_seen_by_backfill', '7249')
            ->assertJsonPath('summary.scanned', 0);

        $this->getJson('/admin/tools/ovoko/backfill-links?part_id='.$part->id.'&missing_only=0&force=1&include_inactive=1&apply=1')
            ->assertOk()
            ->assertJsonPath('parsed_force', true)
            ->assertJsonPath('parsed_apply', true)
            ->assertJsonPath('summary.scanned', 1)
            ->assertJsonPath('summary.updated_url', 1)
            ->assertJsonPath('summary.updated_price', 1);
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
