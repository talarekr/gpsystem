<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoImportProductDataControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_import_accepts_wrapped_get_part_response_and_shows_listing_promotion_changes(): void
    {
        $this->actingAsAdminUser();
        $this->account();

        $part = Part::query()->forceCreate([
            'id' => 501,
            'name' => 'Old name',
            'status' => 'draft',
            'needs_listing' => true,
            'is_visible_storefront' => false,
        ]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '11691', 'status' => 'active']);

        Http::fake([
            'ovoko.test/get/part/11691' => Http::response([
                'status_code' => 'R200',
                'data' => ['part' => ['id' => '11691', 'description' => 'Imported name', 'manufacturer_code' => 'MC-1']],
            ], 200),
        ]);

        $this->getJson('/admin/tools/ovoko/import-product-data?ids=11691&dry_run=1')
            ->assertOk()
            ->assertJsonPath('requested_count', 1)
            ->assertJsonPath('mapped_count', 1)
            ->assertJsonPath('fetched_count', 1)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('would_update_count', 1)
            ->assertJsonPath('products_with_price_count', 0)
            ->assertJsonPath('products_missing_price_count', 1)
            ->assertJsonPath('items.0.status', 'would_update')
            ->assertJsonPath('items.0.changes.0.field', 'main_part_code')
            ->assertJsonPath('items.0.changes.0.label', 'Główny kod części')
            ->assertJsonPath('items.0.changes.0.old_value', '')
            ->assertJsonPath('items.0.changes.0.new_value', 'MC-1')
            ->assertJsonPath('items.0.changes.2.field', 'needs_listing')
            ->assertJsonPath('items.0.changes.2.old_value', true)
            ->assertJsonPath('items.0.changes.2.new_value', false)
            ->assertJsonPath('items.0.changes.3.field', 'status')
            ->assertJsonPath('items.0.changes.3.old_value', 'draft')
            ->assertJsonPath('items.0.changes.3.new_value', 'ready');

        $this->assertTrue($part->fresh()->needs_listing);
        $this->assertSame('draft', $part->fresh()->status);
    }

    public function test_apply_import_promotes_successfully_imported_listing_without_marketplace_write(): void
    {
        $this->actingAsAdminUser();
        $this->account();

        $part = Part::query()->forceCreate(['id' => 502, 'name' => 'Old', 'status' => 'draft', 'needs_listing' => true]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '11692', 'status' => 'active']);

        Http::fake(['ovoko.test/get/part/11692' => Http::response(['status_code' => 'R200', 'item' => ['id' => '11692', 'description' => 'New']], 200)]);

        $this->getJson('/admin/tools/ovoko/import-product-data?ids=11692&apply=1')
            ->assertOk()
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('local_update', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('failed_count', 0);

        $part->refresh();
        $this->assertFalse($part->needs_listing);
        $this->assertSame('ready', $part->status);
    }

    public function test_fetch_failure_includes_ovoko_diagnostics(): void
    {
        $this->actingAsAdminUser();
        $this->account();

        $part = Part::query()->forceCreate(['id' => 503, 'name' => 'Old', 'status' => 'draft', 'needs_listing' => true]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '11693', 'status' => 'active']);

        Http::fake(['ovoko.test/get/part/11693' => Http::response(['status_code' => 'R404', 'msg' => 'Part not found'], 200)]);

        $this->getJson('/admin/tools/ovoko/import-product-data?ids=11693&dry_run=1')
            ->assertOk()
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('items.0.errors.0', 'missing_ovoko_product')
            ->assertJsonPath('items.0.ovoko_diagnostics.http_status', 200)
            ->assertJsonPath('items.0.ovoko_diagnostics.ovoko_status_code', 'R404')
            ->assertJsonPath('items.0.ovoko_diagnostics.ovoko_message', 'Part not found');
    }

    private function account(): void
    {
        MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'name' => 'Ovoko',
            'code' => 'ovoko_main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://ovoko.test',
            'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't'],
        ]);
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Owner Admin',
            'email' => 'ovoko-import-owner@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
