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

class OvokoStockSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_resolves_ovoko_id_from_listing_instead_of_local_part_id(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $part = Part::query()->forceCreate(['id' => 7910, 'name' => 'Lamp', 'quantity' => 1, 'status' => 'published', 'is_visible_storefront' => true, 'needs_listing' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '11711', 'status' => 'active']);

        Http::fake([
            'ovoko.test/v2/get/parts?limit=100&page=1' => Http::response(['parts' => [
                ['id' => '11711', 'quantity' => 0, 'status' => 'sold'],
            ]], 200),
        ]);

        $response = $this->getJson('/admin/tools/ovoko-stock-sync-dry-run?part_id=7910');

        $response->assertOk()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('items.0.part_id', 7910)
            ->assertJsonPath('items.0.ovoko_id', '11711')
            ->assertJsonPath('items.0.ovoko_mapping_source', 'marketplace_listing.external_offer_id')
            ->assertJsonPath('items.0.local.quantity', 1)
            ->assertJsonPath('items.0.local.status', 'published')
            ->assertJsonPath('items.0.local.is_visible_storefront', true)
            ->assertJsonPath('items.0.ovoko.quantity', 0)
            ->assertJsonPath('items.0.ovoko.status', 'sold')
            ->assertJsonPath('items.0.planned_local_state.quantity', 0)
            ->assertJsonPath('items.0.action', 'update_local_stock');

        Http::assertSent(fn ($request): bool => $request['part_id'] === '11711' && $request['id'] === '11711');
    }

    public function test_dry_run_resolves_second_sample_ovoko_id_from_listing(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $part = Part::query()->forceCreate(['id' => 7842, 'name' => 'Bumper', 'quantity' => 0, 'status' => 'draft', 'is_visible_storefront' => false, 'needs_listing' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '11694', 'status' => 'imported']);

        Http::fake([
            'ovoko.test/v2/get/parts?limit=100&page=1' => Http::response(['parts' => [
                ['id' => '11694', 'quantity' => 2, 'status' => 'active'],
            ]], 200),
        ]);

        $this->getJson('/admin/tools/ovoko-stock-sync-dry-run?part_id=7842')
            ->assertOk()
            ->assertJsonPath('items.0.part_id', 7842)
            ->assertJsonPath('items.0.ovoko_id', '11694')
            ->assertJsonPath('items.0.ovoko_mapping_source', 'marketplace_listing.external_offer_id')
            ->assertJsonPath('items.0.ovoko.quantity', 2);
    }

    public function test_dry_run_finds_exact_ovoko_id_inside_wrapped_item_response(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $part = Part::query()->forceCreate(['id' => 7910, 'name' => 'Lamp', 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '11711', 'status' => 'active']);

        Http::fake([
            'ovoko.test/v2/get/parts?limit=100&page=1' => Http::response(['status_code' => 'R200', 'item' => ['id' => '11711', 'quantity' => 3, 'status' => 'active']], 200),
        ]);

        $this->getJson('/admin/tools/ovoko-stock-sync-dry-run?part_id=7910')
            ->assertOk()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('items.0.ovoko.quantity', 3)
            ->assertJsonPath('items.0.ovoko.matched_in_attempt', 'detail_by_part_id_and_id')
            ->assertJsonPath('items.0.ovoko.ovoko_response_shape.has_wrappers.item', true)
            ->assertJsonPath('items.0.ovoko.candidate_ids.0', '11711')
            ->assertJsonPath('items.0.blockers', []);
    }

    public function test_dry_run_does_not_accept_first_ovoko_row_when_id_does_not_match(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $part = Part::query()->forceCreate(['id' => 7910, 'name' => 'Lamp', 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '11711', 'status' => 'active']);

        Http::fake([
            'ovoko.test/v2/get/parts?limit=100&page=1' => Http::response(['parts' => [
                ['id' => '99999', 'quantity' => 0, 'status' => 'sold'],
            ], 'user_token' => 'secret-token'], 200),
        ]);

        $this->getJson('/admin/tools/ovoko-stock-sync-dry-run?part_id=7910')
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('items.0.action', 'blocked')
            ->assertJsonPath('items.0.ovoko.blocker', 'missing_ovoko_product')
            ->assertJsonPath('items.0.ovoko.candidate_ids.0', '99999')
            ->assertJsonPath('items.0.ovoko.ovoko_response_shape.raw_sample.user_token', '[redacted]');
    }

    public function test_missing_mapping_blocks_without_ovoko_api_request(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        Part::query()->forceCreate(['id' => 9001, 'name' => 'Mirror', 'quantity' => 1, 'status' => 'published', 'needs_listing' => false]);
        Http::fake();

        $this->getJson('/admin/tools/ovoko-stock-sync-dry-run?part_id=9001')
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('items.0.part_id', 9001)
            ->assertJsonPath('items.0.ovoko_id', null)
            ->assertJsonPath('items.0.blockers.0', 'missing_ovoko_mapping')
            ->assertJsonFragment(['missing_ovoko_mapping']);

        Http::assertNothingSent();
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
            'email' => 'stock-sync-owner@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
