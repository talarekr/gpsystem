<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\User;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoPartMappingResetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose_is_read_only_and_reports_existing_ovoko_mapping(): void
    {
        $this->actingAsAdminUser();
        $this->mockPublisher();

        $part = Part::query()->create(['name' => 'Test part', 'sku' => 'GPS-GMAIL-61054', 'quantity' => 1, 'status' => 'ready', 'price' => 100, 'ovoko_price' => 120, 'description' => 'Keep me']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '61054', 'external_listing_id' => '61054', 'url' => 'https://ovoko.pl/czesci-samochodowe/hgf61054', 'status' => 'mapped', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'price' => null]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => 'A-1', 'url' => 'https://allegro.test/A-1', 'status' => 'ACTIVE']);

        $this->getJson('/admin/tools/ovoko/part-mapping-diagnose?part_id='.$part->id.'&json=1')
            ->assertOk()
            ->assertJsonPath('marker', 'ovoko_recreate_identity_not_visible_part_code_v3')
            ->assertJsonPath('part_id', $part->id)
            ->assertJsonPath('sku', 'GPS-GMAIL-61054')
            ->assertJsonPath('has_local_ovoko_id', true)
            ->assertJsonPath('has_ovoko_url', true)
            ->assertJsonPath('has_local_marketplace_listing_for_ovoko', true)
            ->assertJsonPath('publish_decision.would_choose', 'update_or_skip_existing')
            ->assertJsonPath('safety_flags.read_only', true)
            ->assertJsonPath('safety_flags.no_mutation', true)
            ->assertJsonPath('safety_flags.no_ovoko_request', true);

        $this->assertSame('61054', MarketplaceListing::query()->where('marketplace', 'ovoko')->value('external_offer_id'));
        $this->assertSame('A-1', MarketplaceListing::query()->where('marketplace', 'allegro')->value('external_offer_id'));
    }

    public function test_reset_clears_only_ovoko_mapping_and_preserves_part_and_other_marketplaces(): void
    {
        $this->actingAsAdminUser();
        $this->mockPublisher();

        $part = Part::query()->create(['name' => 'Test part', 'sku' => 'GPS-GMAIL-61054', 'quantity' => 3, 'status' => 'ready', 'price' => 100, 'ovoko_price' => 120, 'description' => 'Keep me']);
        $ovoko = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '61054', 'external_listing_id' => '61054', 'external_inventory_id' => '61054', 'url' => 'https://ovoko.pl/czesci-samochodowe/hgf61054', 'status' => 'mapped', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'price' => null, 'raw_payload' => ['ovoko_part_id' => '61054', 'metadata' => ['ovoko_part_id' => '61054']]]);
        $allegro = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => 'A-1', 'external_listing_id' => 'A-1', 'url' => 'https://allegro.test/A-1', 'status' => 'ACTIVE']);
        $ebay = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ebay', 'external_offer_id' => 'E-1', 'external_listing_id' => 'E-1', 'url' => 'https://ebay.test/E-1', 'status' => 'active']);

        $this->postJson('/admin/tools/ovoko/part-mapping-reset', ['part_id' => $part->id, 'mode' => 'detach_ovoko_mapping_for_recreate', 'confirm' => 'reset-ovoko-part-mapping-for-recreate'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('marker', 'ovoko_recreate_identity_not_visible_part_code_v3')
            ->assertJsonPath('after.publish_decision.would_choose', 'create')
            ->assertJsonPath('safety_flags.no_allegro_change', true)
            ->assertJsonPath('safety_flags.no_ebay_change', true)
            ->assertJsonPath('safety_flags.no_ovoko_request', true);

        $ovoko->refresh();
        $this->assertNull($ovoko->external_offer_id);
        $this->assertNull($ovoko->external_listing_id);
        $this->assertNull($ovoko->external_inventory_id);
        $this->assertNull($ovoko->url);
        $this->assertSame('unlinked', $ovoko->status);
        $this->assertSame('stale', $ovoko->sync_status);
        $this->assertSame('unmatched', $ovoko->match_status);

        $this->assertSame(['price' => '100.00', 'ovoko_price' => '120.00', 'quantity' => 3, 'description' => 'Keep me'], $part->fresh()->only(['price', 'ovoko_price', 'quantity', 'description']));
        $this->assertSame($allegro->only(['external_offer_id', 'external_listing_id', 'url', 'status']), $allegro->fresh()->only(['external_offer_id', 'external_listing_id', 'url', 'status']));
        $this->assertSame($ebay->only(['external_offer_id', 'external_listing_id', 'url', 'status']), $ebay->fresh()->only(['external_offer_id', 'external_listing_id', 'url', 'status']));
    }

    public function test_publish_path_diagnose_reports_import_part_identity_and_latest_response_without_mutation(): void
    {
        $this->actingAsAdminUser();
        $this->mockPublisher();

        $part = Part::query()->create(['name' => 'Test part', 'sku' => 'GPS-GMAIL-7730', 'part_number' => 'HGF7730', 'quantity' => 1, 'status' => 'ready', 'price' => 100, 'ovoko_price' => 120]);
        $listing = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => null, 'external_listing_id' => null, 'status' => 'unlinked', 'sync_status' => 'stale', 'match_status' => 'unmatched', 'raw_payload' => ['metadata' => ['previous_external_offer_id' => '11582']]]);
        MarketplaceSyncLog::query()->create(['marketplace' => 'ovoko', 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'action' => 'crm/importPart', 'status' => 'success', 'http_status' => 200, 'external_id' => '11582', 'message' => 'Marketplace publish API call completed.', 'payload' => ['request' => ['external_id' => 'GPS-GMAIL-7730'], 'response' => ['ovoko_part_id' => '11582']], 'created_at' => now()]);

        $this->getJson('/admin/tools/ovoko/part-publish-path-diagnose?part_id='.$part->id.'&json=1')
            ->assertOk()
            ->assertJsonPath('marker', 'ovoko_recreate_identity_not_visible_part_code_v3')
            ->assertJsonPath('publish_path.would_choose', 'create')
            ->assertJsonPath('publish_path.endpoint', 'POST /crm/importPart')
            ->assertJsonPath('payload_identity.external_id', 'GPS-GMAIL-7730')
            ->assertJsonPath('payload_identity.contains_old_ovoko_id', false)
            ->assertJsonPath('local_rematch_controls.uses_previous_external_offer_id_as_candidate', false)
            ->assertJsonPath('local_rematch_controls.lookup_or_rematch_by_sku_before_publish', false)
            ->assertJsonPath('latest_import_part_log.api_response_ovoko_id', '11582')
            ->assertJsonPath('safety_flags.no_ovoko_request', true);

        $this->assertNull($listing->fresh()->external_offer_id);
    }


    public function test_publish_path_diagnose_separates_technical_identity_from_visible_part_codes(): void
    {
        $this->actingAsAdminUser();
        $this->mockPublisher();

        $part = Part::query()->create(['id' => 7730, 'name' => 'Citroen DS3 Amortyzator osi przedniej ze sprężyną 9672656180', 'sku' => 'GPS-GMAIL-61054', 'part_number' => '9672656180', 'oem_number' => 'GPSPART7730', 'manufacturer_code' => 'GPS-GMAIL-61054', 'quantity' => 1, 'status' => 'ready', 'price' => 100, 'ovoko_price' => 120]);
        $listing = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => null, 'external_listing_id' => null, 'external_inventory_id' => null, 'sku' => null, 'status' => 'unlinked', 'sync_status' => 'stale', 'match_status' => 'unmatched', 'raw_payload' => ['metadata' => ['ovoko_part_mapping_reset_for_recreate' => true, 'previous_external_offer_id' => '11582', 'previous_sku' => 'GPS-GMAIL-61054']]]);

        $this->getJson('/admin/tools/ovoko/part-publish-path-diagnose?part_id=7730&json=1')
            ->assertOk()
            ->assertJsonPath('technical_identity_fields.external_id', 'gps-part-7730')
            ->assertJsonPath('technical_identity_fields.id_bridge', 'gps-part-7730')
            ->assertJsonPath('visible_part_code_fields.main_part_code', '9672656180')
            ->assertJsonPath('visible_part_code_fields.visible_code', '9672656180')
            ->assertJsonPath('technical_identity_leaks_to_visible_codes', false)
            ->assertJsonPath('technical_identity_leaks_to_title', false)
            ->assertJsonPath('payload_contains_gps_part_as_visible_code', false)
            ->assertJsonPath('payload_contains_gps_gmail_as_visible_code', false)
            ->assertJsonPath('payload_contains_previous_ovoko_id', false);

        $this->assertNull($listing->fresh()->external_offer_id);
    }

    private function mockPublisher(): void
    {
        $this->mock(PublishPartToMarketplacesService::class, function ($mock): void {
            $mock->shouldReceive('preview')->andReturn(['channels' => ['ovoko' => ['success' => true, 'readiness' => ['can_publish_later' => true]]]]);
        });
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::query()->create(['name' => 'Owner Admin', 'email' => 'owner-reset@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        return $user;
    }
}
