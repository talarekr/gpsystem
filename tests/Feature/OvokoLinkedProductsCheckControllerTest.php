<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoLinkedProductsCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_diagnostic_reports_found_missing_ambiguous_duplicates_and_logs_dry_run(): void
    {
        $this->actingAsAdminUser();

        $location = StorageLocation::query()->create(['name' => 'A1', 'is_active' => true]);
        $partA = Part::query()->forceCreate(['id' => 1001, 'name' => 'Lamp left', 'part_number' => 'L-1', 'status' => 'draft', 'needs_listing' => true, 'storage_location_id' => $location->id]);
        $partB = Part::query()->forceCreate(['id' => 1002, 'name' => 'Bumper', 'part_number' => 'B-1', 'status' => 'published', 'needs_listing' => false]);
        $partC = Part::query()->forceCreate(['id' => 1003, 'name' => 'Door', 'part_number' => 'D-1', 'status' => 'draft', 'needs_listing' => true]);

        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $partA->id, 'external_offer_id' => '11691', 'status' => 'active']);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $partB->id, 'external_offer_id' => '11690', 'status' => 'active']);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $partB->id, 'external_offer_id' => '11689', 'status' => 'active']);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $partC->id, 'external_offer_id' => '11688', 'status' => 'active']);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $partA->id, 'external_listing_id' => '11688', 'status' => 'active']);

        $response = $this->getJson('/admin/tools/ovoko/linked-products-check?ids=11691,11690,11689,11688,11687');

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('requested_count', 5)
            ->assertJsonPath('missing_count', 1)
            ->assertJsonPath('ambiguous_count', 1)
            ->assertJsonPath('duplicate_local_part_count', 2)
            ->assertJsonPath('in_parts_to_list_count', 2)
            ->assertJsonPath('items.0.match_status', 'duplicate_local_part')
            ->assertJsonPath('items.0.local_part_id', 1001)
            ->assertJsonPath('items.0.local_storage_location', 'A1')
            ->assertJsonPath('items.3.match_status', 'ambiguous')
            ->assertJsonPath('items.4.match_status', 'missing')
            ->assertJsonPath('summary.missing_ovoko_product_ids.0', '11687');

        $this->assertDatabaseHas('marketplace_sync_logs', [
            'marketplace' => 'ovoko',
            'action' => 'linked_products_check',
            'status' => 'success',
        ]);
        $this->assertSame(1, MarketplaceSyncLog::query()->count());
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Owner Admin',
            'email' => 'linked-products-owner@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
