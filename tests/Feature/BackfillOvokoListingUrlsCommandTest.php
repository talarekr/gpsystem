<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillOvokoListingUrlsCommandTest extends TestCase
{
    use RefreshDatabase;

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
