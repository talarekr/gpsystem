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


    public function test_part_manufacturer_prefers_vehicle_make_over_part_brand_for_oe_business_rule(): void
    {
        $part = Part::query()->create(['name' => 'Część', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Maserati'], 'is_visible_storefront' => true]);
        $part->setAttribute('brand', 'Bosch');

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '127415', 'name' => 'Producent części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
        ]]);

        $this->assertSame([['id' => '127415', 'values' => ['Maserati OE']]], $result['product_parameters']);
        $this->assertSame([], $result['missing_required_parameters']);
        $this->assertSame('vehicle_snapshot.make', $result['parameter_source_diagnostics'][0]['source']);
        $this->assertSame('vehicle_snapshot.make', $result['parameter_source_diagnostics'][0]['source_field']);
    }

    public function test_part_manufacturer_falls_back_to_vehicle_snapshot_make_and_text_values(): void
    {
        $part = Part::query()->create(['name' => 'Część Maserati', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Maserati'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '127415', 'name' => 'Producent części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
        ]]);

        $this->assertSame([['id' => '127415', 'values' => ['Maserati OE']]], $result['product_parameters']);
        $this->assertSame('vehicle_snapshot.make', $result['parameter_source_diagnostics'][0]['source']);
        $this->assertSame('Maserati', $result['parameter_source_diagnostics'][0]['source_value']);
        $this->assertFalse($result['will_make_marketplace_request']);
    }

    public function test_part_manufacturer_dictionary_uses_matching_values_id(): void
    {
        $part = Part::query()->create(['name' => 'Część Maserati', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Maserati'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '127415', 'name' => 'Producent części', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'maserati-id', 'value' => 'Maserati OE']], 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([['id' => '127415', 'valuesIds' => ['maserati-id']]], $result['offer_parameters']);
        $this->assertSame([], $result['missing_required_parameters']);
    }

    public function test_part_manufacturer_dictionary_does_not_fuzzy_match_audi_to_audio_alubutyl(): void
    {
        $part = Part::query()->create(['name' => 'Część Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Audi'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '127415', 'name' => 'Producent części', 'type' => 'dictionary', 'required' => true, 'dictionary' => [
                ['id' => 'audio-alubutyl-id', 'value' => 'Audio Alubutyl'],
                ['id' => 'oem-id', 'value' => 'OEM'],
            ], 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([], $result['offer_parameters']);
        $this->assertSame('Producent części', $result['missing_required_parameters'][0]['name']);
        $this->assertSame('Audi', $result['missing_required_parameters'][0]['raw_local_value']);
        $this->assertSame('Audi OE', $result['missing_required_parameters'][0]['normalized_value']);
        $this->assertSame('no_allowed_value_match', $result['missing_required_parameters'][0]['reason']);
        $this->assertSame('missing', $result['missing_required_parameters'][0]['status']);
        $this->assertSame('required_parameter_not_mapped', $result['missing_required_parameters'][0]['blocker']);
        $this->assertSame(['audio-alubutyl-id' => 'Audio Alubutyl', 'oem-id' => 'OEM'], $result['missing_required_parameters'][0]['allowed_values']);
        $this->assertArrayNotHasKey('mapped_value_id', $result['missing_required_parameters'][0]);
    }


    public function test_all_parts_get_gvo_quality_oe_business_rule(): void
    {
        $part = Part::query()->create(['name' => 'Część Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Audi'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => 'quality', 'name' => 'Jakość części (zgodnie z GVO)', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'oe', 'value' => 'O - oryginał z logo producenta pojazdu (OE)']], 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([['id' => 'quality', 'valuesIds' => ['oe']]], $result['offer_parameters']);
        $this->assertSame('O - oryginał z logo producenta pojazdu (OE)', $result['parameter_source_diagnostics'][0]['resolved_value']);
    }

    public function test_allegro_fixed_business_rules_send_condition_invoice_and_version_to_defined_locations(): void
    {
        $part = Part::query()->create(['name' => 'Część Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Audi'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '11323', 'name' => 'Stan', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => '11323_2', 'value' => 'Używany']], 'options' => ['describesProduct' => false]],
            ['id' => 'invoice_param_from_definition', 'name' => 'Faktura', 'type' => 'dictionary', 'required' => false, 'dictionary' => [['id' => 'invoice_vat_from_definition', 'value' => 'Wystawiam fakturę VAT']], 'options' => ['describesProduct' => false]],
            ['id' => '130533', 'name' => 'Wersja', 'type' => 'dictionary', 'required' => false, 'dictionary' => [['id' => '130533_1', 'value' => 'Europejska']], 'options' => ['describesProduct' => true]],
        ]]);

        $this->assertSame([
            ['id' => '11323', 'valuesIds' => ['11323_2']],
            ['id' => 'invoice_param_from_definition', 'valuesIds' => ['invoice_vat_from_definition']],
        ], $result['offer_parameters']);
        $this->assertSame([['id' => '130533', 'valuesIds' => ['130533_1']]], $result['product_parameters']);

        $diagnostics = collect($result['parameter_source_diagnostics'])->keyBy('name');
        $this->assertSame('fixed_business_rule', $diagnostics['Stan']['source']);
        $this->assertSame('Używany', $diagnostics['Stan']['raw_value']);
        $this->assertSame('Używany', $diagnostics['Stan']['normalized_value']);
        $this->assertSame('Używany', $diagnostics['Stan']['resolved_value']);
        $this->assertSame(['11323_2'], $diagnostics['Stan']['valuesIds']);
        $this->assertSame('fixed', $diagnostics['Stan']['status']);
        $this->assertSame('parameters', $diagnostics['Stan']['parameter_location']);

        $this->assertSame('fixed_business_rule', $diagnostics['Faktura']['source']);
        $this->assertSame('Wystawiam fakturę VAT', $diagnostics['Faktura']['raw_value']);
        $this->assertSame('Wystawiam fakturę VAT', $diagnostics['Faktura']['normalized_value']);
        $this->assertSame('Wystawiam fakturę VAT', $diagnostics['Faktura']['resolved_value']);
        $this->assertSame(['invoice_vat_from_definition'], $diagnostics['Faktura']['valuesIds']);
        $this->assertSame('fixed', $diagnostics['Faktura']['status']);
        $this->assertSame('parameters', $diagnostics['Faktura']['parameter_location']);

        $this->assertSame('fixed_business_rule', $diagnostics['Wersja']['source']);
        $this->assertSame('Europejska', $diagnostics['Wersja']['raw_value']);
        $this->assertSame('Europejska', $diagnostics['Wersja']['normalized_value']);
        $this->assertSame('Europejska', $diagnostics['Wersja']['resolved_value']);
        $this->assertSame(['130533_1'], $diagnostics['Wersja']['valuesIds']);
        $this->assertSame('fixed', $diagnostics['Wersja']['status']);
        $this->assertSame('productSet[0].product.parameters', $diagnostics['Wersja']['parameter_location']);
    }


    public function test_allegro_invoice_uses_payments_invoice_when_category_has_no_invoice_parameter(): void
    {
        $part = Part::query()->create(['name' => 'Część Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Audi'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '11323', 'name' => 'Stan', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => '11323_2', 'value' => 'Używany']], 'options' => ['describesProduct' => false]],
            ['id' => '130533', 'name' => 'Wersja', 'type' => 'dictionary', 'required' => false, 'dictionary' => [['id' => '130533_1', 'value' => 'Europejska']], 'options' => ['describesProduct' => true]],
        ]]);

        $this->assertSame(['invoice' => 'VAT'], $result['payments']);
        $this->assertSame([['id' => '11323', 'valuesIds' => ['11323_2']]], $result['offer_parameters']);
        $this->assertSame([['id' => '130533', 'valuesIds' => ['130533_1']]], $result['product_parameters']);

        $diagnostics = collect($result['parameter_source_diagnostics'])->keyBy('name');
        $this->assertSame('payments.invoice', $diagnostics['Faktura']['parameter_location']);
        $this->assertSame(['invoice' => 'VAT'], $diagnostics['Faktura']['payments']);
        $this->assertSame('Wystawiam fakturę VAT', $diagnostics['Faktura']['resolved_value']);
        $this->assertSame('fixed', $diagnostics['Faktura']['status']);
    }

    public function test_part_manufacturer_maps_audi_bmw_and_mercedes_oe_exact_or_alias_labels(): void
    {
        foreach ([['Audi', 'Audi OE', 'audi-id'], ['BMW', 'BMW OE', 'bmw-id'], ['VW', 'Volkswagen OE', 'vw-id'], ['Mercedes', 'Mercedes-Benz OE', 'mercedes-id']] as [$make, $label, $id]) {
            $part = Part::query()->create(['name' => 'Część '.$make, 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => $make], 'is_visible_storefront' => true]);

            $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
                ['id' => '127415', 'name' => 'Producent części', 'type' => 'dictionary', 'required' => true, 'dictionary' => [
                    ['id' => 'audio-alubutyl-id', 'value' => 'Audio Alubutyl'],
                    ['id' => $id, 'value' => $label],
                ], 'options' => ['describesProduct' => false]],
            ]]);

            $this->assertSame([['id' => '127415', 'valuesIds' => [$id]]], $result['offer_parameters']);
            $this->assertSame($make, $result['parameter_source_diagnostics'][0]['raw_local_value']);
            $this->assertSame($label, $result['parameter_source_diagnostics'][0]['mapped_label']);
            $this->assertSame($id, $result['parameter_source_diagnostics'][0]['mapped_value_id']);
            $this->assertSame('mapped', $result['parameter_source_diagnostics'][0]['status']);
        }
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

    public function test_product_only_parameters_stay_only_in_product_parameters_not_payload_parameters(): void
    {
        $part = Part::query()->create(['name' => 'Skrzynia Audi', 'part_number' => '06H903017J', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Audi', 'gearbox_type' => 'Automatyczna'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '11323', 'name' => 'Stan', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'used', 'value' => 'Używany']], 'options' => ['describesProduct' => true]],
            ['id' => '129917', 'name' => 'Rodzaj skrzyni', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => '129917_2', 'value' => 'Automatyczna']], 'options' => ['describesProduct' => true]],
            ['id' => '127415', 'name' => 'Producent części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
            ['id' => '215858', 'name' => 'Numer katalogowy części', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true]],
        ]]);

        $this->assertSame([], $result['missing_required_parameters']);
        $this->assertSame(['11323'], array_column($result['payload_parameters'], 'id'));
        $this->assertSame(['11323', '129917', '127415', '215858'], array_column($result['product_parameters'], 'id'));
        $this->assertContains('129917', array_column($result['product_parameters'], 'id'));
        $this->assertNotContains('129917', array_column($result['payload_parameters'], 'id'));
        $this->assertContains('127415', array_column($result['product_parameters'], 'id'));
        $this->assertNotContains('127415', array_column($result['payload_parameters'], 'id'));
        $this->assertContains('215858', array_column($result['product_parameters'], 'id'));
        $this->assertNotContains('215858', array_column($result['payload_parameters'], 'id'));
    }


    public function test_allegro_car_type_maps_body_type_to_vehicle_type_values_id(): void
    {
        $part = Part::query()->create(['name' => 'Część SUV', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['body_type' => 'SUV'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '129591', 'name' => 'Typ samochodu', 'type' => 'dictionary', 'required' => true, 'dictionary' => $this->carTypeDictionary(), 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([['id' => '129591', 'valuesIds' => ['129591_64']]], $result['offer_parameters']);
        $this->assertSame([], $result['missing_required_parameters']);
        $this->assertSame('SUV', $result['parameter_source_diagnostics'][0]['source_value']);
        $this->assertSame('129591_64', $result['parameter_source_diagnostics'][0]['mapped_value_id']);
        $this->assertSame('4x4/SUV', $result['parameter_source_diagnostics'][0]['mapped_label']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'allegro', 'action' => 'map_parameter:Typ samochodu', 'status' => 'success']);
    }

    public function test_allegro_car_type_leaves_unmapped_without_undefined_fallback(): void
    {
        $part = Part::query()->create(['name' => 'Część nietypowa', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['body_type' => 'quad'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '129591', 'name' => 'Typ samochodu', 'type' => 'dictionary', 'required' => true, 'dictionary' => $this->carTypeDictionary(), 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([], $result['offer_parameters']);
        $this->assertSame('Typ samochodu', $result['missing_required_parameters'][0]['name']);
        $this->assertSame('quad', $result['missing_required_parameters'][0]['source_value']);
        $this->assertSame('no_car_type_mapping', $result['missing_required_parameters'][0]['reason']);
        $this->assertArrayNotHasKey('mapped_value_id', $result['missing_required_parameters'][0]);
    }

    public function test_allegro_car_type_logs_once_per_build(): void
    {
        $part = Part::query()->create(['name' => 'Część sedan', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['body_type' => 'sedan'], 'is_visible_storefront' => true]);

        app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '129591', 'name' => 'Typ samochodu', 'type' => 'dictionary', 'required' => false, 'dictionary' => $this->carTypeDictionary(), 'options' => ['describesProduct' => false]],
            ['id' => '129591', 'name' => 'Typ samochodu', 'type' => 'dictionary', 'required' => false, 'dictionary' => $this->carTypeDictionary(), 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame(1, DB::table('marketplace_sync_logs')->where('marketplace', 'allegro')->where('action', 'map_parameter:Typ samochodu')->count());
    }


    public function test_allegro_gearbox_type_maps_automatic_to_dictionary_value_id(): void
    {
        $part = Part::query()->create(['name' => 'Skrzynia Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['gearbox_type' => 'Automatyczny'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '225693', 'name' => 'Rodzaj skrzyni', 'type' => 'dictionary', 'required' => true, 'dictionary' => $this->gearboxDictionary(), 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([['id' => '225693', 'valuesIds' => ['225693_2']]], $result['offer_parameters']);
        $this->assertSame([], $result['missing_required_parameters']);
        $this->assertSame('part.vehicle_snapshot.gearbox_type', $result['parameter_source_diagnostics'][0]['source']);
        $this->assertSame('car.gearbox_type', $result['parameter_source_diagnostics'][0]['source_field']);
        $this->assertSame('Automatyczny', $result['parameter_source_diagnostics'][0]['raw_local_value']);
        $this->assertSame('automatyczny', $result['parameter_source_diagnostics'][0]['normalized_value']);
        $this->assertSame('225693_2', $result['parameter_source_diagnostics'][0]['mapped_value_id']);
        $this->assertSame('mapped', $result['parameter_source_diagnostics'][0]['status']);
    }

    public function test_allegro_gearbox_type_missing_blocks_required_parameter(): void
    {
        $part = Part::query()->create(['name' => 'Skrzynia Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => [], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '225693', 'name' => 'Rodzaj skrzyni', 'type' => 'dictionary', 'required' => true, 'dictionary' => $this->gearboxDictionary(), 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([], $result['offer_parameters']);
        $this->assertSame('Rodzaj skrzyni', $result['missing_required_parameters'][0]['name']);
        $this->assertSame('car.gearbox_type', $result['missing_required_parameters'][0]['source_field']);
        $this->assertSame('missing_source_value', $result['missing_required_parameters'][0]['reason']);
        $this->assertSame('missing', $result['missing_required_parameters'][0]['status']);
    }

    public function test_allegro_gearbox_type_invalid_dictionary_value_is_not_sent(): void
    {
        $part = Part::query()->create(['name' => 'Skrzynia Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['gearbox_type' => 'Kosmiczna'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => '225693', 'name' => 'Rodzaj skrzyni', 'type' => 'dictionary', 'required' => true, 'dictionary' => $this->gearboxDictionary(), 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([], $result['offer_parameters']);
        $this->assertSame('Kosmiczna', $result['missing_required_parameters'][0]['raw_local_value']);
        $this->assertSame('no_allowed_value_match', $result['missing_required_parameters'][0]['reason']);
        $this->assertSame('missing', $result['missing_required_parameters'][0]['status']);
        $this->assertSame('required_parameter_not_mapped', $result['missing_required_parameters'][0]['blocker']);
    }

    public function test_allegro_vehicle_fuel_body_and_drivetrain_map_from_car_snapshot(): void
    {
        $part = Part::query()->create(['name' => 'Część Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['fuel_type' => 'Benzyna', 'body_type' => 'Hatchback', 'drivetrain' => 'AWD'], 'is_visible_storefront' => true]);

        $result = app(\App\Services\Marketplace\AllegroOfferParametersBuilder::class)->build($part, null, ['ok' => true, 'source' => 'cache', 'parameters' => [
            ['id' => 'fuel', 'name' => 'Rodzaj paliwa', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'petrol', 'value' => 'Benzyna']], 'options' => ['describesProduct' => false]],
            ['id' => 'body', 'name' => 'Typ nadwozia', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'hatchback', 'value' => 'Hatchback']], 'options' => ['describesProduct' => false]],
            ['id' => 'drive', 'name' => 'Napęd', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => '4x4', 'value' => '4x4']], 'options' => ['describesProduct' => false]],
        ]]);

        $this->assertSame([
            ['id' => 'fuel', 'valuesIds' => ['petrol']],
            ['id' => 'body', 'valuesIds' => ['hatchback']],
            ['id' => 'drive', 'valuesIds' => ['4x4']],
        ], $result['offer_parameters']);
        $this->assertSame([], $result['missing_required_parameters']);
    }

    private function carTypeDictionary(): array
    {
        return [
            ['id' => '129591_64', 'value' => '4x4/SUV'],
            ['id' => '129591_8', 'value' => 'Autobusy'],
            ['id' => '129591_16', 'value' => 'Niezdefiniowany'],
            ['id' => '129591_4', 'value' => 'Samochody ciężarowe'],
            ['id' => '129591_2', 'value' => 'Samochody dostawcze'],
            ['id' => '129591_32', 'value' => 'Samochody kempingowe'],
            ['id' => '129591_1', 'value' => 'Samochody osobowe'],
        ];
    }

    private function gearboxDictionary(): array
    {
        return [
            ['id' => '225693_1', 'value' => 'Manualna'],
            ['id' => '225693_2', 'value' => 'Automatyczna'],
            ['id' => '225693_3', 'value' => 'CVT'],
            ['id' => '225693_4', 'value' => 'DSG'],
        ];
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
