<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\Api\OvokoApiClient;
use App\Services\Marketplace\OvokoListingUrlBackfillService;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\OvokoPublicPhotoController;
use App\Models\PartImage;
use Tests\TestCase;

class OvokoPublishAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['GPS_EXTERNAL_API_WRITES_ENABLED', 'GPS_MARKETPLACE_PUBLISHING_ENABLED', 'GPS_OVOKO_PUBLISHING_ENABLED'] as $flag) putenv($flag);
        parent::tearDown();
    }

    public function test_ovoko_publish_posts_import_part_with_auth_and_saves_external_id_without_logging_secrets(): void
    {
        $part = $this->readyPart();
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 288651, 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $this->assertTrue($result['channels']['ovoko']['success']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '288651', 'external_listing_id' => '288651', 'sync_status' => 'published']);
        Http::assertSent(function ($request) {
            $body = $request->body();

            return $request->url() === 'https://ovoko.example.test/crm/importPart'
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded')
                && str_contains($body, 'username=ovoko-user')
                && str_contains($body, 'password=ovoko-pass')
                && str_contains($body, 'user_token=ovoko-token')
                && str_contains($body, 'category_id=252')
                && str_contains($body, 'car_id=777')
                && str_contains($body, 'quality=0')
                && ! str_contains($body, 'quality=1')
                && str_contains($body, 'status=1')
                && str_contains($body, 'price=120')
                && str_contains($body, 'manufacturer_code=3Q0919294F')
                && preg_match('/(?:^|&)optional_codes=3Q0919294F(?:&|$)/', $body) === 1
                && preg_match('/(?:^|&)optional_codes=OEM-OVOKO-1(?:&|$)/', $body) === 1
                && preg_match('/(?:^|&)optional_codes=MFR-OVOKO-1(?:&|$)/', $body) === 1
                && substr_count($body, 'photos%5B%5D=') === 1
                && ! str_contains($body, 'photos%5B%5D%5B0%5D=')
                && ! str_contains($body, '%2Fmarketplace%2Fovoko%2Fphotos%2F')
                && str_contains($body, 'photo=https%3A%2F%2Fgpswiss.pl%2Fstorage%2Fparts%2Fphotos%2Fcomplete.jpg')
                && str_contains($body, 'photos%5B%5D=https%3A%2F%2Fgpswiss.pl%2Fstorage%2Fparts%2Fphotos%2Fcomplete.jpg')
                && preg_match('/(?:^|&)photo=([^&]+)&photos%5B%5D=\1(?:&|$)/', $body) === 1;
        });
        $encodedLogs = json_encode(MarketplaceSyncLog::query()->pluck('payload')->all());
        $this->assertStringContainsString('ovoko_part_codes', $encodedLogs);
        $this->assertStringContainsString('ovoko_primary_part_code', $encodedLogs);
        $this->assertStringContainsString('ovoko_part_codes_field_name', $encodedLogs);
        $this->assertStringContainsString('ovoko_part_codes_encoding_shape', $encodedLogs);
        $this->assertStringContainsString('part.part_number first', $encodedLogs);
        $this->assertStringNotContainsString('ovoko-user', $encodedLogs);
        $this->assertStringNotContainsString('ovoko-pass', $encodedLogs);
        $this->assertStringNotContainsString('ovoko-token', $encodedLogs);
    }

    public function test_ovoko_condition_is_always_published_as_used_even_when_local_note_says_new(): void
    {
        $part = $this->readyPart(['condition_notes' => 'nowy']);
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 288652, 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $this->assertTrue($result['channels']['ovoko']['success']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ovoko.example.test/crm/importPart'
            && str_contains($request->body(), 'quality=0')
            && ! str_contains($request->body(), 'quality=1'));
        $payload = MarketplaceSyncLog::query()->where('marketplace', 'ovoko')->where('action', 'crm/importPart')->latest('id')->firstOrFail()->payload;
        $this->assertSame('nowy', data_get($payload, 'request.local_condition'));
        $this->assertSame(0, data_get($payload, 'request.ovoko_quality'));
        $this->assertSame('quality', data_get($payload, 'request.ovoko_condition_field_name'));
        $this->assertSame(0, data_get($payload, 'request.ovoko_condition_value'));
        $this->assertSame('used', data_get($payload, 'request.ovoko_condition_meaning'));
        $this->assertSame(1, data_get($payload, 'request.ovoko_new_quality_value'));
        $this->assertTrue(data_get($payload, 'request.condition_mapping_verified'));
        $this->assertTrue(data_get($payload, 'request.condition_mapped_as_used'));
        $this->assertSame(['quality' => 0, 'status' => 1], data_get($payload, 'request.raw_condition_payload_fields'));
    }


    public function test_ovoko_car_mapping_dry_run_searches_read_only_without_container_binding_error(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'read_only', 'api_credentials' => ['username' => 'ovoko-user', 'password' => 'ovoko-pass', 'user_token' => 'ovoko-token']]);
        $car = Car::query()->create(['make' => 'VOLKSWAGEN', 'model' => 'PASSAT B6', 'production_year' => 2009, 'fuel_type' => 'benzyna', 'vin' => 'WVWZZZ3CZ9E000001']);
        Http::fake(['https://ovoko.example.test/v2/get/cars' => Http::response(['status_code' => 'R200', 'data' => [['id' => 4960, 'make' => 'VOLKSWAGEN', 'model' => 'PASSAT B6', 'year' => 2009, 'vin' => 'WVWZZZ3CZ9E000001']]], 200)]);

        $response = $this->getJson('/admin/tools/ovoko/cars/'.$car->id.'/mapping-dry-run');

        $response->assertOk()
            ->assertJsonPath('can_search_ovoko_car', true)
            ->assertJsonPath('search_candidates.0.ovoko_car_id', 4960)
            ->assertJsonMissing(['exception' => 'Illuminate\Contracts\Container\BindingResolutionException']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ovoko.example.test/v2/get/cars' && $request->method() === 'POST');
    }


    public function test_ovoko_car_mapping_marks_unusable_unfiltered_first_page_as_search_unavailable(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'read_only', 'api_credentials' => ['username' => 'ovoko-user', 'password' => 'ovoko-pass', 'user_token' => 'ovoko-token']]);
        $car = Car::query()->create(['make' => 'VOLKSWAGEN', 'model' => 'PASSAT B6', 'production_year' => 2009, 'fuel_type' => 'benzyna']);
        Http::fake(['https://ovoko.example.test/v2/get/cars' => Http::response(['status_code' => 'R200', 'data' => array_map(fn (int $id): array => ['id' => $id], range(1, 100))], 200)]);

        $response = $this->getJson('/admin/tools/ovoko/cars/'.$car->id.'/mapping-dry-run');

        $response->assertOk()
            ->assertJsonPath('can_search_ovoko_car', false)
            ->assertJsonPath('search_supported', false)
            ->assertJsonPath('search_candidates', [])
            ->assertJsonPath('search_warning', 'ovoko_car_search_filter_ignored_or_unusable')
            ->assertJsonPath('existing_car_search_unavailable', true)
            ->assertJsonPath('apply_will_create_new_car', false)
            ->assertJsonPath('can_create_ovoko_car', false)
            ->assertJsonPath('blocked_reason', 'missing_ovoko_car_model_id')
            ->assertJsonPath('manual_input_required', true)
            ->assertJsonPath('required_fields_missing', ['car_model'])
            ->assertJsonPath('ovoko_required_car_model_id_present', false)
            ->assertJsonPath('would_create_payload.external_id', 'gps-car-'.$car->id)
            ->assertJsonMissingPath('would_create_payload.model')
            ->assertJsonPath('returned_candidates_count', 100)
            ->assertJsonPath('parsed_candidates_count', 100)
            ->assertJsonPath('usable_candidates_count', 0)
            ->assertJsonPath('parsed_search_candidates.0.usable', false)
            ->assertJsonPath('parsed_search_candidates.0.unusable_reason', 'missing_make_model_year_vin_external_id')
            ->assertJsonPath('search_request_payload.username', '***')
            ->assertJsonPath('search_request_payload.make', 'VOLKSWAGEN')
            ->assertJsonPath('search_response_sample_raw.0.id', 1);
    }

    public function test_ovoko_car_mapping_apply_does_not_import_car_without_required_car_model_id(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'read_only', 'api_credentials' => ['username' => 'ovoko-user', 'password' => 'ovoko-pass', 'user_token' => 'ovoko-token']]);
        $car = Car::query()->create(['make' => 'VOLKSWAGEN', 'model' => 'PASSAT B6', 'production_year' => 2009, 'fuel_type' => 'benzyna']);
        Http::fake(['https://ovoko.example.test/v2/get/cars' => Http::response(['status_code' => 'R200', 'data' => array_map(fn (int $id): array => ['id' => $id], range(1, 100))], 200)]);

        $response = $this->postJson('/admin/tools/ovoko/cars/'.$car->id.'/mapping-apply?confirm=ovoko-car-map');

        $response->assertStatus(422)
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('reason', 'ovoko_car_id_required_manual_input')
            ->assertJsonPath('can_create_ovoko_car', false)
            ->assertJsonPath('blocked_reason', 'missing_ovoko_car_model_id')
            ->assertJsonPath('manual_input_required', true)
            ->assertJsonPath('required_fields_missing', ['car_model']);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://ovoko.example.test/crm/importCar');
    }

    public function test_ovoko_car_mapping_apply_can_save_manual_car_model_id_without_creating_car(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'read_only', 'api_credentials' => ['username' => 'ovoko-user', 'password' => 'ovoko-pass', 'user_token' => 'ovoko-token']]);
        $car = Car::query()->create(['make' => 'VOLKSWAGEN', 'model' => 'PASSAT B6', 'production_year' => 2009, 'fuel_type' => 'benzyna']);
        Http::fake(['https://ovoko.example.test/v2/get/cars' => Http::response(['status_code' => 'R200', 'data' => []], 200)]);

        $response = $this->postJson('/admin/tools/ovoko/cars/'.$car->id.'/mapping-apply?confirm=ovoko-car-map', ['ovoko_car_model_id' => 'RRR-MODEL-123']);

        $response->assertOk()
            ->assertJsonPath('ovoko_car_model_id', 'RRR-MODEL-123')
            ->assertJsonPath('no_car_create', true)
            ->assertJsonPath('no_part_publish', true);
        $this->assertSame('RRR-MODEL-123', $car->refresh()->legacy_payload['ovoko_car_model_id']);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://ovoko.example.test/crm/importCar');
    }

    public function test_ovoko_publish_uses_local_car_id_fallback_when_car_has_no_stored_ovoko_id(): void
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ovoko', 'external_category_id' => '252']);
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'live', 'api_credentials' => ['username' => 'ovoko-user', 'password' => 'ovoko-pass', 'user_token' => 'ovoko-token'], 'api_settings' => ['default_part_status' => 1]]);
        $car = Car::query()->create(['make' => 'Audi', 'model' => 'RSQ3', 'production_year' => 2015, 'vin' => 'WAUZZZ8U0FA000001', 'engine_code' => 'CZGB']);
        $part = Part::query()->create(['sku' => 'GPS-OVOKO-NOCAR', 'name' => 'Part no car id', 'part_number' => 'LRE', 'price' => 100, 'ovoko_price' => 120, 'quantity' => 1, 'category_id' => $category->id, 'car_id' => $car->id]);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/complete.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 288656, 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $this->assertTrue($result['channels']['ovoko']['success']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ovoko.example.test/crm/importPart' && str_contains($request->body(), 'car_id='.$car->id));
        $payload = MarketplaceSyncLog::query()->where('marketplace', 'ovoko')->where('action', 'crm/importPart')->latest('id')->firstOrFail()->payload;
        $this->assertSame($car->id, data_get($payload, 'request.local_car_id'));
        $this->assertSame('Audi', data_get($payload, 'request.local_car_make'));
        $this->assertSame($car->id, data_get($payload, 'request.ovoko_car_id'));
        $this->assertTrue(data_get($payload, 'request.ovoko_car_id_present'));
        $this->assertSame('local_car_id_fallback', data_get($payload, 'request.ovoko_car_id_source'));
        $this->assertNull(data_get($payload, 'request.blocked_reason'));
    }

    public function test_ovoko_publish_sends_car_id_when_local_car_has_ovoko_id(): void
    {
        $part = $this->readyPart();
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 288653, 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ovoko.example.test/crm/importPart' && str_contains($request->body(), 'car_id=777'));
    }

    public function test_ovoko_code_mapping_uses_lre_as_manufacturer_and_optional_code(): void
    {
        $part = $this->readyPart(['part_number' => 'LRE', 'oem_number' => null, 'manufacturer_code' => null]);
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 288654, 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ovoko.example.test/crm/importPart'
            && str_contains($request->body(), 'manufacturer_code=LRE')
            && preg_match('/(?:^|&)optional_codes=LRE(?:&|$)/', $request->body()) === 1);
    }

    public function test_ovoko_code_mapping_extracts_final_code_instead_of_sending_full_title(): void
    {
        $title = 'AUDI RSQ3 8U BOCZEK TAPICERKA DRZWI PRAWY TYLNY 8U0867306';
        $part = $this->readyPart(['name' => $title, 'part_number' => $title, 'oem_number' => null, 'manufacturer_code' => null]);
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 288655, 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ovoko.example.test/crm/importPart'
            && str_contains($request->body(), 'manufacturer_code=8U0867306')
            && preg_match('/(?:^|&)optional_codes=8U0867306(?:&|$)/', $request->body()) === 1
            && ! str_contains(urldecode($request->body()), 'optional_codes='.$title));
    }


    public function test_ovoko_publish_maps_response_part_id_to_listing_external_ids_and_logs_missing_shop_url(): void
    {
        $part = $this->readyPart();
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 11701, 'shop_url' => null, 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $this->assertTrue($result['channels']['ovoko']['success']);
        $listing = MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', 'ovoko')->firstOrFail();
        $this->assertSame('11701', $listing->external_offer_id);
        $this->assertSame('11701', $listing->external_listing_id);
        $this->assertSame('published', $listing->status);
        $this->assertSame('https://ovoko.pl/czesci-samochodowe/hgf11701', $listing->url);
        $this->assertSame('11701', data_get($listing->raw_payload, 'response_summary.ovoko_part_id'));
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'crm/importPart', 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'external_id' => '11701']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'ovoko_listing_url_resolved', 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'external_id' => '11701']);
    }

    public function test_ovoko_publish_stores_shop_url_when_import_part_returns_it(): void
    {
        $part = $this->readyPart();
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 11701, 'shop_url' => 'https://ovoko.pl/czesci/11701', 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $listing = MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', 'ovoko')->firstOrFail();
        $this->assertSame('https://ovoko.pl/czesci-samochodowe/hgf11701', $listing->url);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'ovoko_listing_url_resolved', 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'external_id' => '11701']);
    }


    public function test_ovoko_part_exist_response_with_part_id_stores_generated_listing_url(): void
    {
        $part = $this->readyPart();
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 11703, 'msg' => 'PART exist', 'status_code' => 'R400'], 200)]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $this->assertTrue($result['channels']['ovoko']['success']);
        $listing = MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', 'ovoko')->firstOrFail();
        $this->assertSame('11703', $listing->external_offer_id);
        $this->assertSame('11703', $listing->external_listing_id);
        $this->assertSame('https://ovoko.pl/czesci-samochodowe/hgf11703', $listing->url);
    }

    public function test_ovoko_api_error_logs_error_and_does_not_crash(): void
    {
        $part = $this->readyPart();
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['msg' => 'Invalid data', 'status_code' => 'R400'], 200)]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $this->assertFalse($result['channels']['ovoko']['success']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'crm/importPart', 'status' => 'error', 'part_id' => $part->id]);
    }

    public function test_disabled_flags_do_not_send_request(): void
    {
        $part = $this->readyPart();
        Http::fake();

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $this->assertTrue($result['blocked']);
        Http::assertNothingSent();
    }

    public function test_duplicate_guard_blocks_second_publish(): void
    {
        $part = $this->readyPart();
        $this->enableFlags();
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => 'EXISTING', 'external_listing_id' => 'EXISTING', 'status' => 'published']);
        Http::fake();

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $this->assertSame('duplicate_guard_existing_listing', $result['channels']['ovoko']['errors'][0]);
        Http::assertNothingSent();
    }

    public function test_single_ovoko_publish_does_not_run_ebay_or_allegro(): void
    {
        $part = $this->readyPart();
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 123, 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        $result = app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $this->assertSame(['ovoko'], array_keys($result['channels']));
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'allegro']);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de']);
    }


    public function test_ovoko_public_photo_route_serves_selected_image_without_auth_or_redirect(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('parts/photos/imported/7892/example.jpg', 'fake-jpeg');
        $partImage = PartImage::query()->create(['path' => 'parts/photos/imported/7892/example.jpg', 'sort_order' => 1, 'is_primary' => true]);
        $signature = OvokoPublicPhotoController::signatureFor($partImage, 'parts/photos/imported/7892/example.jpg');

        $this->get(route('marketplace.ovoko.photos.show', ['partImage' => $partImage->id, 'signature' => $signature, 'filename' => 'example.jpg']))
            ->assertOk()
            ->assertHeader('Content-Length', '9')
            ->assertHeader('Cache-Control', 'public, max-age=86400');
    }

    public function test_no_order_stock_or_scheduler_code_was_added_for_ovoko_publish_scope(): void
    {
        $publishAdapter = file_get_contents(app_path('Services/Marketplace/Publishing/OvokoPublishAdapter.php'));
        $apiClient = file_get_contents(app_path('Services/Marketplace/Api/OvokoApiClient.php'));
        $this->assertStringContainsString('/crm/importPart', $apiClient);
        $this->assertStringNotContainsString('/v2/get/orders', $publishAdapter);
        $this->assertStringNotContainsString('importPostData', $publishAdapter);
        $this->assertStringNotContainsString('changePartStatus', $publishAdapter);
        $this->assertStringNotContainsString('schedule', strtolower($publishAdapter));
    }


    public function test_ovoko_read_lookup_does_not_accept_first_candidate_when_ovoko_id_differs(): void
    {
        $account = MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'read_only', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        Http::fake(['https://ovoko.example.test/*' => Http::response(['status_code' => 'R200', 'data' => [
            ['id' => 99999, 'external_id' => 'gps-part-7890', 'shop_url' => 'https://ovoko.pl/czesci/wrong'],
            ['id' => 11701, 'external_id' => 'gps-part-7890', 'shop_url' => 'https://ovoko.pl/czesci/right'],
        ]], 200)]);

        $result = (new OvokoApiClient('ovoko', $account))->fetchPartRawByLookup('11701', 'gps-part-7890');

        $this->assertTrue($result['api_ok']);
        $this->assertSame('11701', (string) $result['matched_candidate_id']);
        $this->assertSame(1, $result['matched_candidate_index']);
        $this->assertSame('https://ovoko.pl/czesci/right', $result['matched_candidate_shop_url']);
    }

    public function test_ovoko_read_lookup_rejects_external_id_match_with_different_ovoko_id(): void
    {
        $account = MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'read_only', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        Http::fake(['https://ovoko.example.test/*' => Http::response(['status_code' => 'R200', 'data' => [
            ['id' => 99999, 'external_id' => 'gps-part-7890', 'shop_url' => 'https://ovoko.pl/czesci/wrong'],
        ]], 200)]);

        $result = (new OvokoApiClient('ovoko', $account))->fetchPartRawByLookup('11701', 'gps-part-7890');

        $this->assertFalse($result['api_ok']);
        $this->assertSame('detail_id_mismatch', $result['error']);
        $this->assertNull($result['matched_candidate_id']);
    }


    public function test_ovoko_read_lookup_uses_bracket_array_query_params_for_ids(): void
    {
        $account = MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'read_only', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        Http::fake(['https://ovoko.example.test/*' => Http::response(['status_code' => 'R200', 'data' => []], 200)]);

        (new OvokoApiClient('ovoko', $account))->fetchPartRawByLookup('11701', 'gps-part-7890');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ids%5B%5D=11701'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'part_ids%5B%5D=11701'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ids=11701') || str_contains($request->url(), 'part_ids=11701'));
    }

    public function test_ovoko_read_lookup_accepts_requested_id_with_url_field(): void
    {
        $account = MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'read_only', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        Http::fake(['https://ovoko.example.test/*' => Http::response(['status_code' => 'R200', 'data' => [
            ['id' => 11701, 'external_id' => 'gps-part-7890', 'url' => 'https://ovoko.pl/czesci/right'],
        ]], 200)]);

        $result = (new OvokoApiClient('ovoko', $account))->fetchPartRawByLookup('11701', 'gps-part-7890');

        $this->assertTrue($result['api_ok']);
        $this->assertSame('11701', (string) $result['matched_candidate_id']);
        $this->assertSame('https://ovoko.pl/czesci/right', $result['matched_candidate_shop_url']);
    }


    public function test_ovoko_url_backfill_rejects_gpswiss_storage_photo_url(): void
    {
        $service = (new \ReflectionClass(OvokoListingUrlBackfillService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(OvokoListingUrlBackfillService::class, 'validateShopUrl');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'https://gpswiss.pl/storage/parts/photos/imported/7890/photo.jpg');

        $this->assertFalse($result['valid']);
        $this->assertSame('image_url_not_listing_url', $result['reason']);
    }

    private function enableFlags(): void
    {
        foreach (['GPS_EXTERNAL_API_WRITES_ENABLED', 'GPS_MARKETPLACE_PUBLISHING_ENABLED', 'GPS_OVOKO_PUBLISHING_ENABLED'] as $flag) putenv($flag.'=true');
    }

    private function readyPart(array $overrides = []): Part
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ovoko', 'external_category_id' => '252']);
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'live', 'api_credentials' => ['username' => 'ovoko-user', 'password' => 'ovoko-pass', 'user_token' => 'ovoko-token'], 'api_settings' => ['default_part_status' => 1]]);
        $car = Car::query()->create(['source_system' => 'ovoko', 'external_id' => 777, 'make' => 'BMW', 'model' => '3']);
        $part = Part::query()->create(array_merge(['sku' => 'GPS-OVOKO-1', 'name' => 'Kompletna część Ovoko', 'part_number' => '3Q0919294F', 'oem_number' => 'OEM-OVOKO-1', 'manufacturer_code' => 'MFR-OVOKO-1', 'description' => 'Pełny opis części.', 'condition_notes' => 'używany', 'price' => 100, 'ovoko_price' => 120, 'quantity' => 1, 'category_id' => $category->id, 'car_id' => $car->id], $overrides));
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/complete.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        return $part;
    }
}
