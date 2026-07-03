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
            ->assertJsonPath('items.0.changes.0.will_update', true)
            ->assertJsonPath('items.0.changes.1.field', 'category')
            ->assertJsonPath('items.0.changes.1.new_value', null)
            ->assertJsonPath('items.0.changes.1.will_update', false)
            ->assertJsonPath('items.0.changes.1.reason', 'missing_from_ovoko')
            ->assertJsonPath('items.0.changes.7.field', 'title')
            ->assertJsonPath('items.0.changes.7.new_value', 'Imported name')
            ->assertJsonPath('items.0.changes.12.field', 'needs_listing')
            ->assertJsonPath('items.0.changes.12.old_value', true)
            ->assertJsonPath('items.0.changes.12.new_value', false)
            ->assertJsonPath('items.0.changes.12.will_update', true)
            ->assertJsonPath('items.0.changes.13.field', 'status')
            ->assertJsonPath('items.0.changes.13.old_value', 'draft')
            ->assertJsonPath('items.0.changes.13.new_value', 'ready')
            ->assertJsonPath('items.0.changes.13.will_update', true);

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

    public function test_dry_run_maps_realistic_get_part_fields_and_debug_payload(): void
    {
        $this->actingAsAdminUser();
        $this->account();

        $part = Part::query()->forceCreate(['id' => 505, 'name' => 'Old', 'status' => 'draft', 'needs_listing' => true]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '11691', 'status' => 'active']);

        Http::fake([
            'ovoko.test/get/part/11691' => Http::response([
                'status_code' => 'R200',
                'part' => [
                    'rrr_id' => '11691',
                    'manufacturerCode' => '4G0145804D',
                    'categoryId' => 'CAT-9',
                    'categoryName' => 'Intercooler',
                    'partPosition' => 'Przód',
                    'car' => ['id' => '321'],
                    'internalNotes' => 'Cena sklep: 250.00; Cena Allegro: 270,00',
                    'sell_price' => ['seller' => ['amount' => '280.00', 'currency' => 'PLN']],
                    'content' => 'Audi A6 intercooler 4G0145804D',
                    'weightKg' => '3.4',
                    'lengthCm' => '60',
                    'widthCm' => '40',
                    'heightCm' => '20',
                    'user_token' => 'must-not-leak',
                ],
            ], 200),
        ]);

        $this->getJson('/admin/tools/ovoko/import-product-data?ids=11691&dry_run=1&debug_id=11691')
            ->assertOk()
            ->assertJsonPath('products_with_price_count', 1)
            ->assertJsonPath('products_with_category_count', 1)
            ->assertJsonPath('products_with_car_count', 1)
            ->assertJsonPath('products_with_dimensions_count', 1)
            ->assertJsonPath('items.0.changes.0.new_value', '4G0145804D')
            ->assertJsonPath('items.0.changes.1.new_value', 'Intercooler')
            ->assertJsonPath('items.0.changes.2.new_value', 'Przód')
            ->assertJsonPath('items.0.changes.3.new_value', 321)
            ->assertJsonPath('items.0.changes.4.new_value', '250.00')
            ->assertJsonPath('items.0.changes.5.new_value', '270.00')
            ->assertJsonPath('items.0.changes.6.new_value', '280.00')
            ->assertJsonPath('items.0.changes.7.new_value', 'Audi A6 intercooler 4G0145804D')
            ->assertJsonPath('items.0.changes.8.new_value', '3.40')
            ->assertJsonPath('items.0.changes.9.new_value', '60.00')
            ->assertJsonPath('items.0.changes.10.new_value', '40.00')
            ->assertJsonPath('items.0.changes.11.new_value', '20.00')
            ->assertJsonPath('items.0.ovoko_debug.raw_excerpt_sanitized.user_token', '***')
            ->assertJsonPath('items.0.ovoko_debug.field_sources_tried.ovoko_price.0', 'price');
    }

    public function test_dry_run_reports_no_changes_when_all_import_values_match_or_are_missing(): void
    {
        $this->actingAsAdminUser();
        $this->account();

        $part = Part::query()->forceCreate([
            'id' => 504,
            'name' => 'Same title',
            'part_number' => 'MC-SAME',
            'status' => 'ready',
            'needs_listing' => false,
        ]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '11694', 'status' => 'active']);

        Http::fake([
            'ovoko.test/get/part/11694' => Http::response([
                'status_code' => 'R200',
                'item' => ['id' => '11694', 'description' => 'Same title', 'manufacturer_code' => 'MC-SAME'],
            ], 200),
        ]);

        $this->getJson('/admin/tools/ovoko/import-product-data?ids=11694&dry_run=1')
            ->assertOk()
            ->assertJsonPath('would_update_count', 0)
            ->assertJsonPath('items.0.status', 'no_changes')
            ->assertJsonPath('items.0.changes.0.field', 'main_part_code')
            ->assertJsonPath('items.0.changes.0.will_update', false)
            ->assertJsonPath('items.0.changes.1.field', 'category')
            ->assertJsonPath('items.0.changes.1.new_value', null)
            ->assertJsonPath('items.0.changes.1.reason', 'missing_from_ovoko')
            ->assertJsonPath('items.0.changes.13.field', 'status')
            ->assertJsonPath('items.0.changes.13.will_update', false);
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
