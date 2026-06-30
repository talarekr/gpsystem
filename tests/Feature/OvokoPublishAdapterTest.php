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
                && str_contains($body, 'quality=1')
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
        $this->assertNull($listing->url);
        $this->assertSame('11701', data_get($listing->raw_payload, 'response_summary.ovoko_part_id'));
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'crm/importPart', 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'external_id' => '11701']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'missing_shop_url', 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'external_id' => '11701']);
    }

    public function test_ovoko_publish_stores_shop_url_when_import_part_returns_it(): void
    {
        $part = $this->readyPart();
        $this->enableFlags();
        Http::fake(['https://ovoko.example.test/crm/importPart' => Http::response(['part_id' => 11701, 'shop_url' => 'https://ovoko.pl/czesci/11701', 'msg' => 'OK', 'status_code' => 'R200'], 200)]);

        app(PublishPartToMarketplacesService::class)->confirm($part, ['ovoko'], dryRun: false, confirm: true);

        $listing = MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', 'ovoko')->firstOrFail();
        $this->assertSame('https://ovoko.pl/czesci/11701', $listing->url);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'ovoko_listing_url_resolved', 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'external_id' => '11701']);
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

    private function readyPart(): Part
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ovoko', 'external_category_id' => '252']);
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'live', 'api_credentials' => ['username' => 'ovoko-user', 'password' => 'ovoko-pass', 'user_token' => 'ovoko-token'], 'api_settings' => ['default_part_status' => 1]]);
        $car = Car::query()->create(['source_system' => 'ovoko', 'external_id' => 777, 'make' => 'BMW', 'model' => '3']);
        $part = Part::query()->create(['sku' => 'GPS-OVOKO-1', 'name' => 'Kompletna część Ovoko', 'part_number' => '3Q0919294F', 'oem_number' => 'OEM-OVOKO-1', 'manufacturer_code' => 'MFR-OVOKO-1', 'description' => 'Pełny opis części.', 'condition_notes' => 'używany', 'price' => 100, 'ovoko_price' => 120, 'quantity' => 1, 'category_id' => $category->id, 'car_id' => $car->id]);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/complete.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        return $part;
    }
}
