<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            $data = $request->data();
            return $request->url() === 'https://ovoko.example.test/crm/importPart'
                && $data['username'] === 'ovoko-user'
                && $data['password'] === 'ovoko-pass'
                && $data['user_token'] === 'ovoko-token'
                && $data['category_id'] === '252'
                && $data['car_id'] === 777
                && $data['quality'] === 1
                && $data['status'] === 1
                && $data['price'] == 120
                && $data['photos[]'] === ['https://gps.test/storage/parts/photos/complete.jpg'];
        });
        $encodedLogs = json_encode(MarketplaceSyncLog::query()->pluck('payload')->all());
        $this->assertStringNotContainsString('ovoko-user', $encodedLogs);
        $this->assertStringNotContainsString('ovoko-pass', $encodedLogs);
        $this->assertStringNotContainsString('ovoko-token', $encodedLogs);
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

    private function enableFlags(): void
    {
        foreach (['GPS_EXTERNAL_API_WRITES_ENABLED', 'GPS_MARKETPLACE_PUBLISHING_ENABLED', 'GPS_OVOKO_PUBLISHING_ENABLED'] as $flag) putenv($flag.'=true');
    }

    private function readyPart(): Part
    {
        $category = PartCategory::query()->create(['name' => 'Alternatory']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ovoko', 'external_category_id' => '252']);
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'code' => 'ovoko_main', 'name' => 'Ovoko main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'live', 'api_credentials' => ['username' => 'ovoko-user', 'password' => 'ovoko-pass', 'user_token' => 'ovoko-token'], 'api_settings' => ['default_car_id' => 777, 'default_quality' => 1, 'default_part_status' => 1]]);
        $part = Part::query()->create(['sku' => 'GPS-OVOKO-1', 'name' => 'Kompletna część Ovoko', 'description' => 'Pełny opis części.', 'price' => 100, 'ovoko_price' => 120, 'quantity' => 1, 'category_id' => $category->id, 'vehicle_snapshot' => ['make' => 'BMW', 'model' => '3']]);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/complete.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        return $part;
    }
}
