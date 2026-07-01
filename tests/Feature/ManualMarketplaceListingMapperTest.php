<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PartResource\Pages\ListParts;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Marketplace\ManualMarketplaceListingMapper;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManualMarketplaceListingMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_allegro_url_parses_offer_id(): void
    {
        $parsed = app(ManualMarketplaceListingMapper::class)->parse('allegro', 'https://allegro.pl/oferta/bmw-f20-f21-klapa-tylna-bagaznika-w-kolor-igla-18724841486');

        $this->assertSame('18724841486', $parsed['external_id']);
    }

    public function test_allegro_url_saves_local_mapping_for_part_without_marketplace_write_or_sync(): void
    {
        $part = Part::query()->create(['name' => 'Klapa BMW', 'quantity' => 1]);

        $result = app(ManualMarketplaceListingMapper::class)->map($part, 'allegro', 'https://allegro.pl/oferta/bmw-f20-f21-klapa-tylna-bagaznika-w-kolor-igla-18724841486');

        $this->assertSame('created_mapping', $result['action']);
        $this->assertFalse($result['marketplace_write']);
        $this->assertFalse($result['sync_triggered']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '18724841486', 'external_listing_id' => '18724841486', 'url' => $result['url'], 'sync_status' => 'mapped']);
    }

    public function test_ovoko_slug_url_parses_listing_id(): void
    {
        $parsed = app(ManualMarketplaceListingMapper::class)->parse('ovoko', 'https://ovoko.pl/czesci-samochodowe/hgf11705-3c1955419a-volkswagen-passat-b6-mechanizm-i-silniczek-wycieraczek-szyby-przedniej-czolowej');

        $this->assertSame('11705', $parsed['external_id']);
    }

    public function test_ovoko_short_url_parses_listing_id(): void
    {
        $parsed = app(ManualMarketplaceListingMapper::class)->parse('ovoko', 'https://ovoko.pl/czesci-samochodowe/hgf11705');

        $this->assertSame('11705', $parsed['external_id']);
    }

    public function test_existing_mapping_with_same_id_updates_url_without_duplicate(): void
    {
        $part = Part::query()->create(['name' => 'Mechanizm wycieraczek', 'quantity' => 1]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_listing_id' => '11705']);

        $result = app(ManualMarketplaceListingMapper::class)->map($part, 'ovoko', 'https://ovoko.pl/czesci-samochodowe/hgf11705-abc');

        $this->assertSame('updated_url_only', $result['action']);
        $this->assertSame(1, MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', 'ovoko')->count());
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_listing_id' => '11705', 'url' => 'https://ovoko.pl/czesci-samochodowe/hgf11705-abc']);
    }

    public function test_existing_mapping_with_different_id_returns_conflict_without_overwrite(): void
    {
        $part = Part::query()->create(['name' => 'Konflikt', 'quantity' => 1]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '111', 'url' => 'https://allegro.pl/oferta/111']);

        $result = app(ManualMarketplaceListingMapper::class)->map($part, 'allegro', 'https://allegro.pl/oferta/222');

        $this->assertSame('conflict', $result['action']);
        $this->assertSame('existing_mapping_conflict', $result['error']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '111', 'url' => 'https://allegro.pl/oferta/111']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '222']);
    }

    public function test_after_save_ui_shows_checkmark_and_link_for_channel(): void
    {
        $this->actingAsWarehouseUser();
        $part = Part::query()->create(['name' => 'Widoczna aukcja', 'quantity' => 1, 'needs_listing' => false]);

        Livewire::test(ListParts::class)
            ->call('saveMarketplaceLinkMapping', $part->id, 'allegro', 'https://allegro.pl/oferta/bmw-f20-f21-klapa-tylna-bagaznika-w-kolor-igla-18724841486')
            ->assertHasNoErrors()
            ->assertSee('is-listed', false)
            ->assertSee('https://allegro.pl/oferta/bmw-f20-f21-klapa-tylna-bagaznika-w-kolor-igla-18724841486', false);
    }

    public function test_manual_mapping_does_not_trigger_marketplace_api_write_or_sync(): void
    {
        $part = Part::query()->create(['name' => 'Bez API', 'quantity' => 1]);

        $result = app(ManualMarketplaceListingMapper::class)->map($part, 'ovoko', 'https://ovoko.pl/czesci-samochodowe/hgf11705');

        $this->assertFalse($result['marketplace_write']);
        $this->assertFalse($result['sync_triggered']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['part_id' => $part->id, 'marketplace' => 'ovoko', 'action' => 'manual_link_mapping:created_mapping', 'status' => 'success']);
    }

    private function actingAsWarehouseUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create(['name' => 'Warehouse User', 'email' => 'manual-map@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::WarehouseProductStaff->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
