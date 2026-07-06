<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Services\Marketplace\OvokoListingUrlBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillOvokoListingUrlsCommandTest extends TestCase
{
    use RefreshDatabase;


    public function test_browser_backfill_does_not_report_create_when_existing_ovoko_listing_matches_same_part(): void
    {
        DB::table('parts')->insert(['id' => 7892, 'name' => 'PDC module', 'price' => 1, 'ovoko_price' => 12.34, 'quantity' => 1, 'status' => 'ready', 'legacy_payload' => json_encode(['ovoko_part_id' => '11700']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['id' => 501, 'marketplace' => 'ovoko', 'part_id' => 7892, 'external_offer_id' => '11700', 'url' => 'https://ovoko.pl/czesci-samochodowe/hgf11700', 'price' => 123.45, 'created_at' => now(), 'updated_at' => now()]);

        $result = app(OvokoListingUrlBackfillService::class)->runBrowserBackfill(
            apply: false,
            missingOnly: true,
            limit: 100,
            partId: 7892,
        );

        $this->assertSame(0, $result['summary']['would_create_listing']);
        $this->assertSame(1, $result['summary']['already_mapped']);
        $this->assertSame(1, $result['summary']['skipped_existing_complete']);
        $this->assertSame('already_mapped,skip_existing_complete', $result['results'][0]['action']);
    }

    public function test_browser_backfill_reports_existing_listing_update_instead_of_create_when_same_part_is_incomplete(): void
    {
        DB::table('parts')->insert(['id' => 7892, 'name' => 'PDC module', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'legacy_payload' => json_encode(['ovoko_part_id' => '11700']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['id' => 501, 'marketplace' => 'ovoko', 'part_id' => 7892, 'external_offer_id' => '11700', 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()]);

        $result = app(OvokoListingUrlBackfillService::class)->runBrowserBackfill(
            apply: false,
            missingOnly: true,
            limit: 100,
            partId: 7892,
        );

        $this->assertSame(0, $result['summary']['would_create_listing']);
        $this->assertSame(1, $result['summary']['would_update_existing_listing']);
        $this->assertSame('would_update_existing_listing', $result['results'][0]['action']);
    }

    public function test_dry_run_resolves_shop_url_from_read_only_ovoko_api_without_writing(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_base_url' => 'https://ovoko.example.test', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('parts')->insert(['id' => 7892, 'name' => 'PDC module', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'legacy_payload' => json_encode(['ovoko_part_id' => '11700']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['id' => 501, 'marketplace' => 'ovoko', 'part_id' => 7892, 'external_offer_id' => '11700', 'created_at' => now(), 'updated_at' => now()]);

        Http::fake([
            'https://ovoko.example.test/v2/get/parts/11700' => Http::response(['status_code' => 'R404', 'msg' => 'Not found'], 200),
            'https://ovoko.example.test/v2/get/part/11700' => Http::response(['status_code' => 'R200', 'data' => ['id' => '11700', 'shop_url' => 'https://ovoko.pl/czesci-samochodowe/example-slug']], 200),
            'https://ovoko.example.test/*' => Http::response(['status_code' => 'R404'], 200),
        ]);

        $this->artisan('marketplace:backfill-ovoko-listing-urls', ['--dry-run' => true, '--part-id' => 7892])
            ->expectsOutputToContain('would_update')
            ->expectsOutputToContain('ovoko_read_api')
            ->assertExitCode(0);

        $this->assertDatabaseHas('marketplace_listings', ['id' => 501, 'url' => null]);
    }

    public function test_dry_run_rejects_local_gpswiss_photo_url_and_does_not_write_it(): void
    {
        DB::table('parts')->insert(['id' => 7892, 'name' => 'PDC module', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert([
            'id' => 501,
            'marketplace' => 'ovoko',
            'part_id' => 7892,
            'external_offer_id' => '11700',
            'raw_payload' => json_encode(['shop_url' => 'https://gpswiss.pl/storage/parts/photos/imported/7892/example.jpg']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_base_url' => 'https://ovoko.example.test', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        Http::fake(['https://ovoko.example.test/*' => Http::response(['status_code' => 'R404', 'msg' => 'Not found'], 200)]);

        $this->artisan('marketplace:backfill-ovoko-listing-urls', ['--dry-run' => true, '--part-id' => 7892])
            ->expectsOutputToContain('would_update')
            ->expectsOutputToContain('generated_from_ovoko_part_id')
            ->expectsOutputToContain('image_url_not_listing_url')
            ->expectsOutputToContain('ovoko_read_api')
            ->assertExitCode(0);

        $this->assertDatabaseHas('marketplace_listings', ['id' => 501, 'url' => null]);
        $this->assertDatabaseMissing('marketplace_listings', ['id' => 501, 'url' => 'https://gpswiss.pl/storage/parts/photos/imported/7892/example.jpg']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v2/get/parts'));
    }

    public function test_dry_run_searches_list_payload_for_matching_ovoko_or_external_id(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_base_url' => 'https://ovoko.example.test', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('parts')->insert(['id' => 7892, 'external_id' => 'gps-part-7892', 'name' => 'PDC module', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['id' => 501, 'marketplace' => 'ovoko', 'part_id' => 7892, 'external_offer_id' => '11700', 'created_at' => now(), 'updated_at' => now()]);

        Http::fake([
            'https://ovoko.example.test/v2/get/part/11700' => Http::response(['status_code' => 'R404'], 200),
            'https://ovoko.example.test/get/part/11700' => Http::response(['status_code' => 'R404'], 200),
            'https://ovoko.example.test/v2/get/parts' => Http::response([
                'status_code' => 'R200',
                'pagination' => ['page' => 1, 'limit' => 100, 'total_count' => 2],
                'data' => [
                    ['id' => '3', 'external_id' => 'other', 'shop_url' => 'https://ovoko.pl/czesci-samochodowe/wrong-slug'],
                    ['id' => '999', 'external_id' => 'gps-part-7892', 'shop_url' => 'https://ovoko.pl/czesci-samochodowe/right-slug'],
                ],
            ], 200),
            'https://ovoko.example.test/*' => Http::response(['status_code' => 'R404'], 200),
        ]);

        $this->artisan('marketplace:backfill-ovoko-listing-urls', ['--dry-run' => true, '--part-id' => 7892])
            ->expectsOutputToContain('would_update')
            ->expectsOutputToContain('gps-part-7892')
            ->expectsOutputToContain('right-slug')
            ->assertExitCode(0);

        $this->assertDatabaseHas('marketplace_listings', ['id' => 501, 'url' => null]);
    }


    public function test_dry_run_generates_url_from_numeric_ovoko_part_id_without_writing(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_base_url' => 'https://ovoko.example.test', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('parts')->insert(['id' => 7892, 'name' => 'PDC module', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['id' => 501, 'marketplace' => 'ovoko', 'part_id' => 7892, 'external_offer_id' => '11701', 'created_at' => now(), 'updated_at' => now()]);

        Http::fake(['https://ovoko.example.test/*' => Http::response(['status_code' => 'R404', 'msg' => 'Not found'], 200)]);

        $this->artisan('marketplace:backfill-ovoko-listing-urls', ['--dry-run' => true, '--part-id' => 7892])
            ->expectsOutputToContain('https://ovoko.pl/czesci-samochodowe/hgf11701')
            ->expectsOutputToContain('generated_from_ovoko_part_id')
            ->expectsOutputToContain('would_update')
            ->assertExitCode(0);

        $this->assertDatabaseHas('marketplace_listings', ['id' => 501, 'url' => null]);
        $this->assertDatabaseMissing('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'ovoko_listing_url_generated', 'marketplace_listing_id' => 501]);
    }

    public function test_does_not_generate_url_when_ovoko_part_id_is_missing_or_not_numeric_and_does_not_use_local_part_id(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_base_url' => 'https://ovoko.example.test', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('parts')->insert([
            ['id' => 11701, 'name' => 'Part without Ovoko ID', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7893, 'name' => 'Part with bad Ovoko ID', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_listings')->insert([
            ['id' => 501, 'marketplace' => 'ovoko', 'part_id' => 11701, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 502, 'marketplace' => 'ovoko', 'part_id' => 7893, 'external_offer_id' => 'abc-11701', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Http::fake(['https://ovoko.example.test/*' => Http::response(['status_code' => 'R404', 'msg' => 'Not found'], 200)]);

        $this->artisan('marketplace:backfill-ovoko-listing-urls', ['--dry-run' => true, '--limit' => 2])
            ->expectsOutputToContain('missing_ovoko_id')
            ->expectsOutputToContain('missing_shop_url')
            ->doesntExpectOutputToContain('https://ovoko.pl/czesci-samochodowe/hgf11701')
            ->doesntExpectOutputToContain('https://ovoko.pl/czesci-samochodowe/hgfabc-11701')
            ->assertExitCode(0);

        $this->assertDatabaseHas('marketplace_listings', ['id' => 501, 'url' => null]);
        $this->assertDatabaseHas('marketplace_listings', ['id' => 502, 'url' => null]);
    }

    public function test_apply_logs_generated_ovoko_listing_url(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_base_url' => 'https://ovoko.example.test', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('parts')->insert(['id' => 7892, 'name' => 'PDC module', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['id' => 501, 'marketplace' => 'ovoko', 'part_id' => 7892, 'external_offer_id' => '11701', 'created_at' => now(), 'updated_at' => now()]);

        Http::fake(['https://ovoko.example.test/*' => Http::response(['status_code' => 'R404', 'msg' => 'Not found'], 200)]);

        $this->artisan('marketplace:backfill-ovoko-listing-urls', ['--apply' => true, '--part-id' => 7892])
            ->expectsOutputToContain('updated')
            ->expectsOutputToContain('generated_from_ovoko_part_id')
            ->assertExitCode(0);

        $this->assertDatabaseHas('marketplace_listings', ['id' => 501, 'url' => 'https://ovoko.pl/czesci-samochodowe/hgf11701']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'ovoko_listing_url_generated', 'marketplace_listing_id' => 501, 'part_id' => 7892, 'external_id' => '11701']);
    }

    public function test_apply_generates_url_from_external_listing_id_when_offer_id_is_empty(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_base_url' => 'https://ovoko.example.test', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('parts')->insert(['id' => 7897, 'name' => 'PDC module', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['id' => 503, 'marketplace' => 'ovoko', 'part_id' => 7897, 'external_listing_id' => '11703', 'created_at' => now(), 'updated_at' => now()]);

        Http::fake(['https://ovoko.example.test/*' => Http::response(['status_code' => 'R404', 'msg' => 'Not found'], 200)]);

        $this->artisan('marketplace:backfill-ovoko-listing-urls', ['--apply' => true, '--part-id' => 7897])
            ->expectsOutputToContain('updated')
            ->expectsOutputToContain('generated_from_ovoko_part_id')
            ->assertExitCode(0);

        $this->assertDatabaseHas('marketplace_listings', ['id' => 503, 'url' => 'https://ovoko.pl/czesci-samochodowe/hgf11703']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ovoko', 'action' => 'ovoko_listing_url_generated', 'marketplace_listing_id' => 503, 'part_id' => 7897, 'external_id' => '11703']);

        $payload = DB::table('marketplace_sync_logs')->where('marketplace_listing_id', 503)->where('action', 'ovoko_listing_url_generated')->value('payload');
        $decoded = json_decode((string) $payload, true);

        $this->assertSame('11703', $decoded['response']['ovoko_part_id'] ?? null);
        $this->assertSame('https://ovoko.pl/czesci-samochodowe/hgf11703', $decoded['response']['ovoko_listing_url'] ?? null);
        $this->assertSame('generated_from_ovoko_part_id', $decoded['response']['ovoko_listing_url_source'] ?? null);
    }

    public function test_apply_updates_from_csv_and_does_not_call_api(): void
    {
        DB::table('parts')->insert(['id' => 7892, 'external_id' => 'gps-part-7892', 'name' => 'PDC module', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['id' => 501, 'marketplace' => 'ovoko', 'part_id' => 7892, 'external_offer_id' => '11700', 'created_at' => now(), 'updated_at' => now()]);
        $csv = tempnam(sys_get_temp_dir(), 'ovoko_urls_');
        file_put_contents($csv, "local_part_id,ovoko_part_id,external_id,shop_url\n7892,11700,gps-part-7892,https://ovoko.pl/czesci-samochodowe/real-slug\n");
        Http::fake();

        $this->artisan('marketplace:backfill-ovoko-listing-urls', ['--apply' => true, '--csv' => $csv, '--part-id' => 7892])
            ->expectsOutputToContain('updated')
            ->expectsOutputToContain('csv')
            ->assertExitCode(0);

        $this->assertDatabaseHas('marketplace_listings', ['id' => 501, 'url' => 'https://ovoko.pl/czesci-samochodowe/real-slug']);
        Http::assertNothingSent();
        @unlink($csv);
    }
}
