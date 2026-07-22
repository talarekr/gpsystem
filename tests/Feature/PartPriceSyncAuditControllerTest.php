<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartPriceSyncAuditControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ebay_de_inventory_api_audit_reports_price_only_flow_guards_without_old_quantity_risk(): void
    {
        Http::fake([
            'https://api.nbp.pl/*' => Http::response(['rates' => [['mid' => 4.3, 'effectiveDate' => '2026-07-21', 'no' => '142/A/NBP/2026']]], 200),
            '*' => Http::response(['unexpected' => true], 500),
        ]);

        $this->actingAsAdminUser();
        $part = Part::query()->create(['id' => 8212, 'name' => 'Audit part 8212', 'status' => 'ready', 'quantity' => 1, 'price' => 430]);
        $account = MarketplaceAccount::query()->create(['marketplace' => 'ebay_de', 'code' => 'ebay_de', 'name' => 'eBay DE', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token']]);
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'external_offer_id' => '199289364011', 'external_inventory_id' => 'GPSW-8212', 'sku' => 'GPSW-8212', 'status' => 'active', 'sync_status' => 'mapped', 'price' => 100, 'quantity' => 1]);
        MarketplaceListing::query()->create(['marketplace' => 'ebay_fr', 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'external_offer_id' => 'FR-IGNORED', 'status' => 'active', 'sync_status' => 'mapped']);
        MarketplaceListing::query()->create(['marketplace' => 'ebay', 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'external_offer_id' => 'GENERIC-IGNORED', 'status' => 'active', 'sync_status' => 'mapped']);

        $response = $this->getJson('/admin/tools/marketplace/parts/8212/price-sync-audit')->assertOk();

        $response->assertJsonPath('read_only', true)
            ->assertJsonPath('no_mutation', true)
            ->assertJsonPath('external_requests', false)
            ->assertJsonPath('channels.ebay_de.listing_type', 'inventory_api')
            ->assertJsonPath('channels.ebay_de.price_only_write_supported', true)
            ->assertJsonPath('channels.ebay_de.remote_quantity_pre_read_required', true)
            ->assertJsonPath('channels.ebay_de.quantity_source', 'remote_pre_read')
            ->assertJsonPath('channels.ebay_de.quantity_mutation_risk', false)
            ->assertJsonPath('channels.ebay_de.quantity_mutation_guarded', true)
            ->assertJsonPath('channels.ebay_de.publication_mutation_guarded', true)
            ->assertJsonPath('channels.ebay_de.post_write_quantity_verification', true)
            ->assertJsonPath('channels.ebay_de.post_write_publication_verification', true);
        $this->assertArrayNotHasKey('ebay_fr', $response->json('channels'));
        $this->assertArrayNotHasKey('ebay', $response->json('channels'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.nbp.pl/'));
    }

    private function actingAsAdminUser(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $user = User::query()->create(['name' => 'Owner Admin', 'email' => uniqid('admin').'@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);

        return $user;
    }
}
