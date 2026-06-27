<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Services\Marketplace\AllegroCategoryParametersService;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AllegroCategoryParametersTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_fetches_allegro_category_parameters_read_only_and_splits_product_and_offer_parameters(): void
    {
        Http::fake(['https://api.allegro.pl/sale/categories/123/parameters' => Http::response($this->parametersPayload(), 200)]);
        MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'token']]);
        $part = Part::query()->create(['name' => 'Amortyzator Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 2, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Audi', 'body_type' => 'sedan'], 'is_visible_storefront' => true]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => 77, 'channel' => 'allegro_main', 'external_category_id' => '123']);

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');

        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/sale/categories/123/parameters'));
        Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'PUT', 'PATCH'], true));
        $preview = $result['prepared_payload_preview_safe'];
        $this->assertSame('api', $preview['parameter_definitions_source']);
        $this->assertSame([['id' => '11323', 'valuesIds' => ['used']]], $preview['allegro_product_parameters']);
        $this->assertContains(['id' => 'quality', 'valuesIds' => ['oe']], $preview['allegro_offer_parameters']);
        $this->assertSame([], $preview['missing_required_parameters']);
        $this->assertFalse($preview['will_make_marketplace_request']);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_missing_required_parameter_blocks_readiness_without_guessing(): void
    {
        Http::fake(['https://api.allegro.pl/sale/categories/123/parameters' => Http::response($this->parametersPayload(requireSide: true), 200)]);
        MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'token']]);
        $part = Part::query()->create(['name' => 'Amortyzator', 'category_id' => 77, 'price' => 100, 'quantity' => 2, 'description' => 'Opis', 'vehicle_snapshot' => [], 'is_visible_storefront' => true]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => 77, 'channel' => 'allegro_main', 'external_category_id' => '123']);

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');

        $this->assertContains('allegro_required_category_parameters_missing', $result['blockers']);
        $this->assertSame('Strona zabudowy', $result['prepared_payload_preview_safe']['missing_required_parameters'][0]['name']);
        $this->assertSame('not_resolved', $result['prepared_payload_preview_safe']['missing_required_parameters'][0]['source']);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_cache_is_used_without_second_api_call(): void
    {
        MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'token']]);
        DB::table('allegro_category_parameters_cache')->insert(['allegro_category_id' => '123', 'raw_response' => json_encode($this->parametersPayload()), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Http::fake();
        $part = Part::query()->create(['name' => 'Amortyzator Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 2, 'description' => 'Opis', 'manufacturer_code' => 'Audi OE', 'vehicle_snapshot' => ['body_type' => 'sedan'], 'is_visible_storefront' => true]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => 77, 'channel' => 'allegro_main', 'external_category_id' => '123']);

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');

        Http::assertNothingSent();
        $this->assertSame('cache', $result['prepared_payload_preview_safe']['parameter_definitions_source']);
    }


    public function test_fix_migration_backfills_old_category_id_not_null_cache_schema(): void
    {
        Schema::dropIfExists('allegro_category_parameters_cache');
        Schema::create('allegro_category_parameters_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('category_id');
            $table->json('raw_response');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
        DB::table('allegro_category_parameters_cache')->insert(['category_id' => '261054', 'raw_response' => json_encode($this->parametersPayload()), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_06_27_000003_fix_allegro_category_parameters_cache_schema.php'))->up();

        $this->assertTrue(Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id'));
        $this->assertFalse(Schema::hasColumn('allegro_category_parameters_cache', 'category_id'));
        $this->assertDatabaseHas('allegro_category_parameters_cache', ['allegro_category_id' => '261054']);
    }

    public function test_fix_migration_removes_legacy_category_id_when_allegro_category_id_already_exists(): void
    {
        Schema::dropIfExists('allegro_category_parameters_cache');
        Schema::create('allegro_category_parameters_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('allegro_category_id')->nullable();
            $table->string('category_id');
            $table->json('raw_response');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
        DB::table('allegro_category_parameters_cache')->insert(['allegro_category_id' => '261054', 'category_id' => '261054', 'raw_response' => json_encode($this->parametersPayload()), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_06_27_000003_fix_allegro_category_parameters_cache_schema.php'))->up();

        $this->assertTrue(Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id'));
        $this->assertFalse(Schema::hasColumn('allegro_category_parameters_cache', 'category_id'));
        $this->assertDatabaseHas('allegro_category_parameters_cache', ['allegro_category_id' => '261054']);
    }

    public function test_cache_insert_uses_allegro_category_id_for_category_261054(): void
    {
        Http::fake(['https://api.allegro.pl/sale/categories/261054/parameters' => Http::response($this->parametersPayload(), 200)]);
        MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'token']]);

        $result = app(AllegroCategoryParametersService::class)->definitions('261054');

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('allegro_category_parameters_cache', ['allegro_category_id' => '261054']);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'PUT', 'PATCH'], true));
    }

    public function test_cache_schema_error_returns_safe_blocker_instead_of_query_exception(): void
    {
        Schema::dropIfExists('allegro_category_parameters_cache');
        Schema::create('allegro_category_parameters_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('allegro_category_id')->unique();
            $table->string('category_id');
            $table->json('raw_response');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
        Http::fake(['https://api.allegro.pl/sale/categories/261054/parameters' => Http::response($this->parametersPayload(), 200)]);
        MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'token']]);

        $result = app(AllegroCategoryParametersService::class)->definitions('261054');

        $this->assertFalse($result['ok']);
        $this->assertSame('allegro_category_parameters_cache_error', $result['blocker']);
        $this->assertArrayNotHasKey('token', $result);
    }


    public function test_part_manufacturer_prefers_part_brand_over_vehicle_make(): void
    {
        $part = Part::query()->create(['name' => 'Część', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Maserati'], 'is_visible_storefront' => true]);
        $part->setAttribute('brand', 'Bosch');

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '127415', 'name' => 'Producent części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
        ]]);

        $this->assertSame([['id' => '127415', 'values' => ['Bosch']]], $result['product_parameters']);
        $this->assertSame([], $result['missing_required_parameters']);
        $this->assertSame('part.brand', $result['parameter_source_diagnostics'][0]['source']);
    }

    public function test_part_manufacturer_falls_back_to_vehicle_snapshot_make_and_text_values(): void
    {
        $part = Part::query()->create(['name' => 'Część Maserati', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Maserati'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '127415', 'name' => 'Producent części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
        ]]);

        $this->assertSame([['id' => '127415', 'values' => ['Maserati']]], $result['product_parameters']);
        $this->assertSame('vehicle_snapshot.make', $result['parameter_source_diagnostics'][0]['source']);
        $this->assertSame('Maserati', $result['parameter_source_diagnostics'][0]['source_value']);
        $this->assertFalse($result['will_make_marketplace_request']);
    }

    public function test_part_manufacturer_dictionary_uses_matching_values_id(): void
    {
        $part = Part::query()->create(['name' => 'Część Maserati', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Maserati'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '127415', 'name' => 'Producent części', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'maserati-id', 'value' => 'Maserati']], 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([['id' => '127415', 'valuesIds' => ['maserati-id']]], $result['offer_parameters']);
        $this->assertSame([], $result['missing_required_parameters']);
    }

    public function test_part_catalog_number_uses_real_part_number_and_describes_product_section(): void
    {
        $part = Part::query()->create(['name' => 'Część Maserati', 'part_number' => '06H903017J', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Maserati'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '215858', 'name' => 'Numer katalogowy części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
        ]]);

        $this->assertSame([['id' => '215858', 'values' => ['06H903017J']]], $result['product_parameters']);
        $this->assertSame([], $result['offer_parameters']);
        $this->assertSame([], $result['missing_required_parameters']);
        $this->assertSame('part.part_number', $result['parameter_source_diagnostics'][0]['source']);
    }

    public function test_required_allegro_part_manufacturer_and_catalog_number_clear_missing_parameters_without_writes(): void
    {
        Http::fake();
        $part = Part::query()->create(['name' => 'Część Maserati', 'part_number' => '06H903017J', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Maserati'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '127415', 'name' => 'Producent części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
            ['id' => '215858', 'name' => 'Numer katalogowy części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
        ]]);

        $this->assertSame([], $result['missing_required_parameters']);
        $this->assertFalse($result['will_make_marketplace_request']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    private function parametersPayload(bool $requireSide = false): array
    {
        return ['parameters' => [
            ['id' => '11323', 'name' => 'Stan', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'used', 'value' => 'Używany']], 'options' => ['describesProduct' => true]],
            ['id' => 'quality', 'name' => 'Jakość części (zgodnie z GVO)', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'oe', 'value' => 'O - oryginał z logo producenta pojazdu (OE)']], 'options' => ['describesProduct' => false]],
            ['id' => 'side', 'name' => 'Strona zabudowy', 'type' => 'dictionary', 'required' => $requireSide, 'dictionary' => [['id' => 'front-back', 'value' => 'przód + tył']], 'options' => ['describesProduct' => false]],
        ]];
    }
}
