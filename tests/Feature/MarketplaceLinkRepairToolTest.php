<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Admin\PartMarketplaceStatusResolver;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MarketplaceLinkRepairToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_ovoko_id_9992_preview_and_apply_repairs_listing_and_resolver(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['name' => 'Ovoko part', 'legacy_url' => 'https://ovoko.pl/czesci-samochodowe/hgf9992-test', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN', 'ovoko_price' => 10]);

        $this->getJson('/admin/tools/marketplace/link-repair?format=json&channel=ovoko&part_id='.$part->id)
            ->assertOk()->assertJsonPath('rows.0.action', 'create')->assertJsonPath('rows.0.external_id', '9992');

        $this->postJson('/admin/tools/marketplace/link-repair?format=json', ['channel' => 'ovoko', 'part_id' => $part->id, 'confirm' => 'apply-marketplace-link-repair'])
            ->assertOk()->assertJsonPath('report.created', 1);

        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '9992', 'external_listing_id' => '9992', 'status' => 'imported', 'sync_status' => 'mapped', 'match_status' => 'confirmed']);
        $row = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->refresh()->load('marketplaceListings')))->firstWhere('key', 'ovoko');
        $this->assertTrue($row['has_link']);
        $this->assertTrue($row['is_active']);
        $this->assertSame('check', $row['icon']);
    }

    public function test_allegro_id_18331392855_apply_repairs_listing_and_link(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['name' => 'Allegro part', 'legacy_payload' => ['legacy_payload_json' => ['_allegro_offer_id' => '18331392855']], 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN']);

        $response = $this->postJson('/admin/tools/marketplace/link-repair?format=json', ['channel' => 'allegro', 'part_id' => $part->id, 'confirm' => 'apply-marketplace-link-repair'])
            ->assertOk()
            ->assertJsonPath('report.created', 1)
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.part_id', $part->id)
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.marketplace', 'allegro')
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.external_offer_id', '18331392855')
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.external_listing_id', '18331392855')
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.url', 'https://allegro.pl/oferta/18331392855')
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.status', 'ACTIVE')
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.sync_status', 'mapped')
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.match_status', 'confirmed')
            ->assertJsonPath('rows.0.actual_after_write.resolver.has_link', true)
            ->assertJsonPath('rows.0.actual_after_write.resolver.url', 'https://allegro.pl/oferta/18331392855')
            ->assertJsonPath('rows.0.actual_after_write.resolver.is_active', true)
            ->assertJsonPath('rows.0.actual_after_write.resolver.icon', 'check')
            ->assertJsonPath('rows.0.actual_after_write.resolver.display_icon', '✓')
            ->assertJsonPath('rows.0.actual_after_write.resolver.reason', 'allegro_active');

        $savedListingId = $response->json('rows.0.saved_listing_id');
        $this->assertIsInt($savedListingId);
        $this->assertSame($savedListingId, $response->json('rows.0.actual_after_write.saved_listing_id'));
        $this->assertSame($savedListingId, $response->json('rows.0.actual_after_write.saved_fields.id'));
        $this->assertDatabaseHas('marketplace_listings', ['id' => $savedListingId, 'part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => '18331392855', 'external_listing_id' => '18331392855', 'url' => 'https://allegro.pl/oferta/18331392855', 'status' => 'ACTIVE', 'sync_status' => 'mapped', 'match_status' => 'confirmed']);
    }


    public function test_allegro_apply_updates_target_listing_and_verifies_from_database(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create([
            'id' => 756,
            'name' => 'Allegro target listing',
            'legacy_payload' => ['legacy_payload_json' => ['_allegro_offer_id' => '18331392855']],
            'quantity' => 1,
            'status' => 'ready',
            'currency' => 'PLN',
        ]);
        $listing = MarketplaceListing::query()->create([
            'id' => 22837,
            'part_id' => $part->id,
            'marketplace' => 'allegro',
            'external_offer_id' => '18331392855',
            'external_listing_id' => null,
            'url' => null,
            'status' => null,
            'sync_status' => 'imported',
            'match_status' => 'unmatched',
        ]);

        $this->postJson('/admin/tools/marketplace/link-repair?format=json', [
            'channel' => 'allegro',
            'part_id' => $part->id,
            'listing_id' => $listing->id,
            'confirm' => 'apply-marketplace-link-repair',
        ])
            ->assertOk()
            ->assertJsonPath('report.updated', 1)
            ->assertJsonPath('rows.0.action', 'update')
            ->assertJsonPath('rows.0.saved_listing_id', 22837)
            ->assertJsonPath('rows.0.actual_after_write.saved_listing_id', 22837)
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.id', 22837)
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.external_offer_id', '18331392855')
            ->assertJsonPath('rows.0.actual_after_write.saved_fields.url', 'https://allegro.pl/oferta/18331392855')
            ->assertJsonPath('rows.0.actual_after_write.resolver.has_link', true)
            ->assertJsonPath('rows.0.actual_after_write.resolver.icon', 'check');

        $this->assertDatabaseHas('marketplace_listings', [
            'id' => 22837,
            'part_id' => 756,
            'marketplace' => 'allegro',
            'external_offer_id' => '18331392855',
            'external_listing_id' => '18331392855',
            'url' => 'https://allegro.pl/oferta/18331392855',
            'status' => 'ACTIVE',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
        ]);
    }

    public function test_same_id_for_same_part_updates_without_conflict(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['name' => 'Same ID', 'legacy_url' => 'https://ovoko.pl/czesci-samochodowe/hgf9992-new', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '9992']);

        $this->postJson('/admin/tools/marketplace/link-repair?format=json', ['channel' => 'ovoko', 'part_id' => $part->id, 'confirm' => 'apply-marketplace-link-repair'])
            ->assertOk()->assertJsonPath('report.updated', 1);
    }

    public function test_same_id_for_other_part_is_conflict_skip(): void
    {
        $this->actingAsAdminUser();
        $other = Part::query()->create(['name' => 'Other', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create(['part_id' => $other->id, 'marketplace' => 'allegro', 'external_offer_id' => '18331392855', 'external_listing_id' => '18331392855']);
        $part = Part::query()->create(['name' => 'Conflict', 'legacy_url' => 'https://allegro.pl/oferta/test-18331392855', 'quantity' => 1, 'status' => 'ready']);

        $this->getJson('/admin/tools/marketplace/link-repair?format=json&channel=allegro&part_id='.$part->id)
            ->assertOk()->assertJsonPath('rows.0.action', 'conflict')->assertJsonPath('rows.0.reason', 'external_id_belongs_to_other_part');
    }

    public function test_sold_zero_quantity_gets_link_but_resolver_icon_stays_x(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create(['name' => 'Sold', 'legacy_url' => 'https://ovoko.pl/czesci-samochodowe/hgf9992-sold', 'quantity' => 0, 'status' => 'sold']);

        $this->postJson('/admin/tools/marketplace/link-repair?format=json', ['channel' => 'ovoko', 'part_id' => $part->id, 'confirm' => 'apply-marketplace-link-repair'])
            ->assertOk()->assertJsonPath('report.created', 1);

        $row = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->refresh()->load('marketplaceListings')))->firstWhere('key', 'ovoko');
        $this->assertTrue($row['has_link']);
        $this->assertFalse($row['is_active']);
        $this->assertSame('x', $row['icon']);
    }

    public function test_ovoko_does_not_extract_id_from_gpswiss_url_or_mercedes_w176_text(): void
    {
        $this->actingAsAdminUser();
        $other = Part::query()->create(['name' => 'Other Ovoko 176', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create(['part_id' => $other->id, 'marketplace' => 'ovoko', 'external_offer_id' => '176', 'external_listing_id' => '176']);
        $part = Part::query()->create([
            'id' => 756,
            'name' => 'Mercedes-Benz A45 W176 2.0T AMG 4MATIC rozrusznik A6549060800',
            'legacy_url' => 'https://gpswiss.pl/produkt/mercedes-benz-a45-w176-2-0t-amg-4matic-rozrusznik-a6549060800/',
            'quantity' => 1,
            'status' => 'ready',
            'currency' => 'PLN',
        ]);

        $this->getJson('/admin/tools/marketplace/link-repair?format=json&channel=ovoko&part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('rows.0.action', 'skip')
            ->assertJsonPath('rows.0.reason', 'missing_id')
            ->assertJsonPath('rows.0.external_id', null);
    }

    public function test_ovoko_extracts_id_only_from_ovoko_hgf_url(): void
    {
        $this->actingAsAdminUser();
        $part = Part::query()->create([
            'name' => 'Mercedes-Benz A45 W176 rozrusznik',
            'legacy_url' => 'https://ovoko.pl/czesci-samochodowe/hgf9992-a6549060800-mercedes-benz-a-w176-rozrusznik',
            'quantity' => 1,
            'status' => 'ready',
            'currency' => 'PLN',
        ]);

        $this->getJson('/admin/tools/marketplace/link-repair?format=json&channel=ovoko&part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('rows.0.action', 'create')
            ->assertJsonPath('rows.0.external_id', '9992')
            ->assertJsonPath('rows.0.current_id_link.source', 'ovoko_url');
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::query()->create(['name' => 'Owner Admin', 'email' => 'owner'.uniqid().'@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        return $user;
    }
}
