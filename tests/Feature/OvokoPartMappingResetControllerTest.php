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
            ->assertJsonPath('marker', 'ovoko_recreate_numeric_id_bridge_v5')
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
            ->assertJsonPath('marker', 'ovoko_recreate_numeric_id_bridge_v5')
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
            ->assertJsonPath('marker', 'ovoko_recreate_numeric_id_bridge_v5')
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

    public function test_reset_candidates_endpoint_is_read_only_and_shows_gps_gmail_candidates(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Candidate', 'sku' => 'GPS-GMAIL-7730', 'quantity' => 1, 'status' => 'published', 'price' => null, 'ovoko_price' => null, 'source_system' => 'woo']);
        $ovoko = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '11582', 'external_listing_id' => '11582', 'external_inventory_id' => 'INV-11582', 'sku' => 'GPS-GMAIL-7730', 'url' => 'https://ovoko.example.test/11582', 'status' => 'mapped', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'raw_payload' => ['ovoko_part_id' => '11582']]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => 'A-11582', 'external_listing_id' => 'A-11582', 'url' => 'https://allegro.example.test/A-11582', 'status' => 'ACTIVE']);

        $this->getJson('/admin/tools/ovoko/part-mapping-reset-candidates?json=1&include_readiness=1&only_gps_gmail=1&source_system=woo')
            ->assertOk()
            ->assertJsonPath('marker', 'ovoko_reset_candidates_missing_price_to_publish_only_v4')
            ->assertJsonPath('summary.safety_flags.read_only', true)
            ->assertJsonPath('summary.safety_flags.no_mutation', true)
            ->assertJsonPath('summary.safety_flags.no_ovoko_request', true)
            ->assertJsonPath('summary.safety_flags.no_publish', true)
            ->assertJsonPath('summary.total_candidates', 1)
            ->assertJsonPath('summary.candidates_with_gps_gmail_sku', 1)
            ->assertJsonPath('summary.candidates_missing_price', 1)
            ->assertJsonPath('candidates.0.part_id', $part->id)
            ->assertJsonPath('candidates.0.identity_looks_like_gps_gmail', true)
            ->assertJsonPath('candidates.0.has_active_ovoko_identity', true)
            ->assertJsonPath('candidates.0.has_active_ovoko_url', true)
            ->assertJsonPath('candidates.0.should_create_after_reset', true)
            ->assertJsonPath('candidates.0.raw_payload_ovoko_part_id', '11582')
            ->assertJsonPath('candidates.0.reset_recommended_now_strict', false)
            ->assertJsonPath('candidates.0.suggested_action', 'inspect_manually');

        $this->assertSame('11582', $ovoko->fresh()->external_offer_id);
        $this->assertSame('A-11582', MarketplaceListing::query()->where('marketplace', 'allegro')->value('external_offer_id'));
    }


    public function test_reset_candidates_not_ready_filters_summary_and_recommendation_are_read_only(): void
    {
        $this->actingAsAdminUser();

        $ready = Part::query()->create(['name' => 'Ready', 'sku' => 'GPS-GMAIL-READY', 'quantity' => 1, 'status' => 'ready', 'price' => 100, 'ovoko_price' => 120, 'weight_kg' => 1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'car_id' => 1, 'review_metadata' => ['marketplace_category_overrides' => ['ovoko' => ['external_category_id' => 'CAT']]]]);
        $notReadyImported = Part::query()->create(['name' => 'Imported missing price', 'sku' => 'GPS-GMAIL-IMPORT', 'quantity' => 1, 'status' => 'ready', 'price' => null, 'ovoko_price' => null]);
        $published = Part::query()->create(['name' => 'Published missing price', 'sku' => 'GPS-GMAIL-PUB', 'quantity' => 1, 'status' => 'ready', 'price' => null, 'ovoko_price' => null]);
        $nonGps = Part::query()->create(['name' => 'Not GPS', 'sku' => 'LOCAL-1', 'quantity' => 1, 'status' => 'ready', 'price' => null, 'ovoko_price' => null]);

        MarketplaceListing::query()->create(['part_id' => $ready->id, 'marketplace' => 'ovoko', 'external_offer_id' => 'R-1', 'sku' => 'GPS-GMAIL-READY', 'url' => 'https://ovoko.example.test/R-1', 'status' => 'imported']);
        MarketplaceListing::query()->create(['part_id' => $notReadyImported->id, 'marketplace' => 'ovoko', 'external_offer_id' => 'I-1', 'sku' => 'GPS-GMAIL-IMPORT', 'url' => 'https://ovoko.example.test/I-1', 'status' => 'imported']);
        MarketplaceListing::query()->create(['part_id' => $published->id, 'marketplace' => 'ovoko', 'external_offer_id' => 'P-1', 'sku' => 'GPS-GMAIL-PUB', 'url' => 'https://ovoko.example.test/P-1', 'status' => 'published']);
        MarketplaceListing::query()->create(['part_id' => $nonGps->id, 'marketplace' => 'ovoko', 'external_offer_id' => 'N-1', 'sku' => 'LOCAL-1', 'url' => 'https://ovoko.example.test/N-1', 'status' => 'imported']);

        $this->getJson('/admin/tools/ovoko/part-mapping-reset-candidates?json=1&include_readiness=1&only_not_ready=1&exclude_published=1&only_imported=1&limit=10')
            ->assertOk()
            ->assertJsonPath('marker', 'ovoko_reset_candidates_missing_price_to_publish_only_v4')
            ->assertJsonPath('summary.total_candidates', 2)
            ->assertJsonPath('summary.ready_candidates_count', 0)
            ->assertJsonPath('summary.not_ready_candidates_count', 2)
            ->assertJsonPath('summary.published_candidates_count', 0)
            ->assertJsonPath('summary.imported_not_ready_candidates_count', 2)
            ->assertJsonPath('candidates.0.status', 'imported')
            ->assertJsonPath('candidates.0.reset_recommended_now', false)
            ->assertJsonPath('candidates.1.status', 'imported')
            ->assertJsonPath('candidates.1.reset_recommended_now', true);

        $this->assertSame('I-1', MarketplaceListing::query()->where('part_id', $notReadyImported->id)->value('external_offer_id'));
        $this->assertSame('P-1', MarketplaceListing::query()->where('part_id', $published->id)->value('external_offer_id'));
    }


    public function test_strict_reset_candidates_skip_priced_live_parts_and_filter_to_publish_queue(): void
    {
        $this->actingAsAdminUser();

        $priced = Part::query()->forceCreate([
            'id' => 7795,
            'name' => 'Priced live part',
            'sku' => 'GPS-GMAIL-7795',
            'quantity' => 1,
            'status' => 'ready',
            'price' => 100,
            'ovoko_price' => 120,
            'is_visible_storefront' => true,
            'needs_listing' => false,
        ]);
        $candidate = Part::query()->create([
            'name' => 'Missing price queue part',
            'sku' => 'GPS-GMAIL-QUEUE',
            'quantity' => 1,
            'status' => 'draft',
            'price' => null,
            'ovoko_price' => null,
            'is_visible_storefront' => false,
            'needs_listing' => true,
            'source_system' => 'woo',
        ]);

        MarketplaceListing::query()->create(['part_id' => $priced->id, 'marketplace' => 'ovoko', 'external_offer_id' => '7795', 'external_listing_id' => '7795', 'sku' => 'GPS-GMAIL-7795', 'url' => 'https://ovoko.example.test/7795', 'price' => 120, 'status' => 'published', 'sync_status' => 'published']);
        MarketplaceListing::query()->create(['part_id' => $candidate->id, 'marketplace' => 'ovoko', 'external_offer_id' => 'Q-1', 'external_listing_id' => 'Q-1', 'sku' => 'GPS-GMAIL-QUEUE', 'url' => 'https://ovoko.example.test/Q-1', 'status' => 'imported', 'sync_status' => 'mapped']);

        $this->getJson('/admin/tools/ovoko/part-mapping-reset-candidates?json=1&only_gps_gmail=1&only_with_ovoko_url=1&limit=10')
            ->assertOk()
            ->assertJsonPath('marker', 'ovoko_reset_candidates_missing_price_to_publish_only_v4')
            ->assertJsonPath('summary.with_price_skipped_count', 1)
            ->assertJsonPath('summary.strict_reset_candidates_count', 1)
            ->assertJsonPath('candidates.0.part_id', $candidate->id)
            ->assertJsonPath('candidates.0.reset_recommended_now_strict', true)
            ->assertJsonPath('candidates.1.part_id', 7795)
            ->assertJsonPath('candidates.1.has_price', true)
            ->assertJsonPath('candidates.1.has_ovoko_price', true)
            ->assertJsonPath('candidates.1.reset_recommended_now_strict', false)
            ->assertJsonPath('candidates.1.suggested_action', 'inspect_manually')
            ->assertJsonPath('candidates.1.reset_risk_level', 'high')
            ->assertJsonPath('candidates.1.reset_risk_reason', 'product has price and may already be live/listed');

        $this->getJson('/admin/tools/ovoko/part-mapping-reset-candidates?json=1&only_gps_gmail=1&only_with_ovoko_url=1&only_missing_price=1&only_to_publish_queue=1&limit=10')
            ->assertOk()
            ->assertJsonPath('summary.total_candidates', 1)
            ->assertJsonPath('summary.strict_reset_candidates_count', 1)
            ->assertJsonPath('summary.to_publish_candidates_count', 1)
            ->assertJsonPath('candidates.0.part_id', $candidate->id);

        $this->assertSame('7795', MarketplaceListing::query()->where('part_id', 7795)->value('external_offer_id'));
    }

    public function test_reset_preview_shows_fields_to_clear_and_does_not_include_allegro_ebay_reset_fields(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Preview part', 'sku' => 'GPS-GMAIL-7729', 'quantity' => 1, 'status' => 'published', 'price' => 100]);
        $ovoko = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '11773', 'external_listing_id' => '11773', 'external_inventory_id' => 'INV-11773', 'sku' => 'GPS-GMAIL-7729', 'url' => 'https://ovoko.example.test/11773', 'status' => 'mapped', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'raw_payload' => ['metadata' => ['previous_external_offer_id' => '11582', 'previous_url' => 'https://ovoko.example.test/11582']]]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => 'A-1', 'external_listing_id' => 'A-1', 'url' => 'https://allegro.test/A-1', 'status' => 'ACTIVE']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ebay', 'external_offer_id' => 'E-1', 'external_listing_id' => 'E-1', 'url' => 'https://ebay.test/E-1', 'status' => 'active']);

        $response = $this->getJson('/admin/tools/ovoko/part-mapping-reset-preview?part_ids='.$part->id.'&json=1')
            ->assertOk()
            ->assertJsonPath('marker', 'ovoko_part_mapping_reset_candidates_audit_v1')
            ->assertJsonPath('items.0.current_active_ovoko_identity.external_offer_id', '11773')
            ->assertJsonPath('items.0.archived_identity.previous_external_offer_id', '11582')
            ->assertJsonPath('items.0.what_would_be_cleared_by_reset.marketplace_listings#'.$ovoko->id.'.external_offer_id', '11773')
            ->assertJsonPath('items.0.post_reset_expected_identity.external_id', 'gps-part-'.$part->id)
            ->assertJsonPath('items.0.post_reset_expected_identity.id_bridge', (string) $part->id)
            ->assertJsonPath('items.0.safety_flags.no_mutation', true);

        $content = $response->getContent();
        $this->assertStringNotContainsString('allegro', $content);
        $this->assertStringNotContainsString('ebay', $content);
        $this->assertSame('11773', $ovoko->fresh()->external_offer_id);
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
            ->assertJsonPath('technical_identity_fields.id_bridge', '7730')
            ->assertJsonPath('technical_identity_fields.id_bridge_is_numeric', true)
            ->assertJsonPath('technical_identity_fields.id_bridge_source', 'local_part_id_numeric_after_ovoko_mapping_reset')
            ->assertJsonPath('visible_part_code_fields.main_part_code', '9672656180')
            ->assertJsonPath('visible_part_code_fields.visible_code', '9672656180')
            ->assertJsonPath('technical_identity_leaks_to_visible_codes', false)
            ->assertJsonPath('technical_identity_leaks_to_title', false)
            ->assertJsonPath('payload_contains_gps_part_as_visible_code', false)
            ->assertJsonPath('payload_contains_gps_gmail_as_visible_code', false)
            ->assertJsonPath('payload_contains_previous_ovoko_id', false);

        $this->assertNull($listing->fresh()->external_offer_id);
    }


    public function test_publish_path_diagnose_filters_gmail_only_visible_codes_but_keeps_recreate_identity(): void
    {
        $this->actingAsAdminUser();
        $this->mockPublisher();

        $part = Part::query()->create(['id' => 7731, 'name' => 'Test part GPSPART7731 GPS-GMAIL-61052', 'sku' => 'GPS-GMAIL-61052', 'part_number' => 'GPS-GMAIL-61052', 'oem_number' => 'GPSGMAIL61052', 'manufacturer_code' => 'gps-part-7731', 'quantity' => 1, 'status' => 'ready', 'price' => 100, 'ovoko_price' => 120]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => null, 'external_listing_id' => null, 'external_inventory_id' => null, 'sku' => null, 'status' => 'unlinked', 'sync_status' => 'stale', 'match_status' => 'unmatched', 'raw_payload' => ['metadata' => ['ovoko_part_mapping_reset_for_recreate' => true]]]);

        $this->getJson('/admin/tools/ovoko/part-publish-path-diagnose?part_id=7731&json=1')
            ->assertOk()
            ->assertJsonPath('technical_identity_fields.external_id', 'gps-part-7731')
            ->assertJsonPath('technical_identity_fields.id_bridge', '7731')
            ->assertJsonPath('technical_identity_fields.id_bridge_is_numeric', true)
            ->assertJsonPath('technical_identity_fields.id_bridge_source', 'local_part_id_numeric_after_ovoko_mapping_reset')
            ->assertJsonPath('visible_part_code_fields.main_part_code', null)
            ->assertJsonPath('visible_part_code_fields.visible_code', null)
            ->assertJsonPath('visible_part_code_fields.part_code', null)
            ->assertJsonPath('visible_part_code_fields.manufacturer_code', null)
            ->assertJsonPath('visible_part_code_fields.oem_number', null)
            ->assertJsonPath('visible_part_code_fields.additional_codes', [])
            ->assertJsonPath('payload_contains_gps_gmail_as_visible_code', false)
            ->assertJsonPath('payload_contains_gps_part_as_visible_code', false)
            ->assertJsonPath('technical_identity_leaks_to_visible_codes', false)
            ->assertJsonPath('technical_identity_leaks_to_title', false)
            ->assertJsonPath('payload_contains_previous_ovoko_id', false)
            ->assertJsonPath('visible_code_repair_preview.suggested_title', 'Test part');
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
