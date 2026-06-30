<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\User;
use App\Services\Marketplace\MarketplaceListingUrlBackfillService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MarketplaceListingUrlBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_ebay_de_url_from_external_item_id(): void
    {
        $listing = MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => 123, 'external_offer_id' => '800266310200']);
        $result = app(MarketplaceListingUrlBackfillService::class)->run('ebay', 'ebay_de', false, 100, 0, true);
        $this->assertSame('https://www.ebay.de/itm/800266310200', $result['results'][0]['generated_url']);
        $this->assertSame('would_update', $result['results'][0]['action']);
        $this->assertSame('', (string) $listing->fresh()->url);
    }

    public function test_generates_ebay_fr_url(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'ebay_fr', 'external_listing_id' => '800266310201']);
        $result = app(MarketplaceListingUrlBackfillService::class)->run('ebay', 'ebay_fr');
        $this->assertSame('https://www.ebay.fr/itm/800266310201', $result['results'][0]['generated_url']);
    }

    public function test_generates_allegro_url_from_numeric_offer_id(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'allegro', 'part_id' => 999, 'external_offer_id' => '1234567890']);
        $result = app(MarketplaceListingUrlBackfillService::class)->run('allegro');
        $this->assertSame('https://allegro.pl/oferta/1234567890', $result['results'][0]['generated_url']);
    }

    public function test_does_not_use_local_part_or_listing_id_as_external_id(): void
    {
        $listing = MarketplaceListing::query()->create(['marketplace' => 'allegro', 'part_id' => 555]);
        $result = app(MarketplaceListingUrlBackfillService::class)->run('allegro');
        $this->assertSame('missing_external_id', $result['results'][0]['action']);
        $this->assertNull($result['results'][0]['resolved_marketplace_item_id']);
        $this->assertNotSame((string) $listing->id, (string) $result['results'][0]['resolved_marketplace_item_id']);
    }

    public function test_does_not_overwrite_valid_existing_url(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'allegro', 'external_offer_id' => '123', 'url' => 'https://allegro.pl/oferta/123']);
        $result = app(MarketplaceListingUrlBackfillService::class)->run('allegro', null, true, 100, 0, false, true);
        $this->assertSame('skipped_has_url', $result['results'][0]['action']);
        $this->assertSame(0, $result['summary']['updated']);
    }

    public function test_rejects_gpswiss_storage_url_without_include_existing_invalid(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'external_offer_id' => '800266310200', 'url' => 'https://gpswiss.pl/storage/foo.jpg']);
        $result = app(MarketplaceListingUrlBackfillService::class)->run('ebay', 'ebay_de');
        $this->assertSame('rejected_existing_url', $result['results'][0]['action']);
        $this->assertSame(1, $result['summary']['image_url_rejected']);
    }

    public function test_apply_without_confirm_is_dry_run_in_controller(): void
    {
        $this->actingAsAdminUser();
        $this->mock(MarketplaceListingUrlBackfillService::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->withArgs(fn ($marketplace, $channel, $apply) => $marketplace === 'allegro' && $apply === false)->andReturn(['mode' => 'dry_run', 'summary' => [], 'results' => [], 'warnings' => []]);
        });
        $this->getJson('/admin/tools/marketplace/listing-url-backfill?marketplace=allegro&apply=1')->assertOk()->assertJsonPath('apply_confirmed', false);
    }

    public function test_offset_is_deterministic_by_listing_id(): void
    {
        for ($i = 0; $i < 3; $i++) MarketplaceListing::query()->create(['marketplace' => 'allegro', 'external_offer_id' => (string) (100 + $i)]);
        $result = app(MarketplaceListingUrlBackfillService::class)->run('allegro', null, false, 1, 1);
        $this->assertSame(2, $result['summary']['first_inspected_listing_id']);
        $this->assertSame([2], $result['summary']['inspected_listing_ids_sample']);
    }

    public function test_ebay_gpsw_external_id_is_blocked_from_url_generation(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => 56, 'external_offer_id' => 'GPSW-2135']);
        $result = app(MarketplaceListingUrlBackfillService::class)->run('ebay', 'ebay_de');
        $this->assertSame('invalid_external_id', $result['results'][0]['action']);
        $this->assertTrue($result['results'][0]['gpsw_external_id']);
        $this->assertNull($result['results'][0]['generated_url']);
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
