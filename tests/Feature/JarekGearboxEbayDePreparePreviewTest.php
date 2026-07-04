<?php

namespace Tests\Feature;

use App\Models\JarekGearbox;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\PartCategory;
use App\Services\Marketplace\GoogleTranslateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JarekGearboxEbayDePreparePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_ebay_de_prepare_preview_converts_pln_source_price_to_eur_using_existing_nbp_rate(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        $this->mockTranslations();
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18727785496/01.jpg', 'jpg');

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18727785496',
            'title' => 'Skrzynia DSG 0D9300041',
            'description' => 'Opis skrzyni',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'images' => ['https://a.allegroimg.com/original/photo.jpg'],
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-prepare-preview?sku=JAREK-18727785496');

        $response->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('parts_changed', false)
            ->assertJsonPath('source_table', 'jarek_gearboxes')
            ->assertJsonPath('source_price_pln', 2450)
            ->assertJsonPath('nbp_exchange_rate', 4.30)
            ->assertJsonPath('target_currency', 'EUR')
            ->assertJsonPath('price_eur', 569.77)
            ->assertJsonPath('price', 569.77)
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('payload_preview.source_price_pln', 2450)
            ->assertJsonPath('payload_preview.nbp_exchange_rate', 4.30)
            ->assertJsonPath('payload_preview.target_currency', 'EUR')
            ->assertJsonPath('payload_preview.price_eur', 569.77)
            ->assertJsonPath('payload_preview.price', 569.77)
            ->assertJsonPath('payload_preview.currency', 'EUR');
    }

    public function test_ebay_de_prepare_preview_blocks_when_nbp_rate_is_missing(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::forget('nbp_table_a_eur_rate');
        Http::fake(['api.nbp.pl/*' => Http::response([], 500)]);
        $this->mockTranslations();
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18727785496/01.jpg', 'jpg');

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18727785496',
            'title' => 'Skrzynia DSG 0D9300041',
            'description' => 'Opis skrzyni',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-prepare-preview?sku=JAREK-18727785496');

        $response->assertOk()
            ->assertJsonPath('ready', false)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('parts_changed', false)
            ->assertJsonPath('source_price_pln', 2450)
            ->assertJsonPath('nbp_exchange_rate', null)
            ->assertJsonPath('target_currency', 'EUR')
            ->assertJsonPath('price_eur', null)
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('payload_preview.currency', 'EUR')
            ->assertJsonPath('payload_preview.price', null)
            ->assertJsonPath('payload_preview.price_eur', null)
            ->assertJsonFragment(['missing_nbp_exchange_rate']);
    }


    public function test_ebay_de_prepare_preview_selects_audi_from_title_and_never_falls_back_to_gpswiss_brand(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        $this->mockTranslations();
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18727785496/01.jpg', 'jpg');

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18727785496',
            'title' => 'Reduktor skrzyni 0CN409053AF VW Skoda Audi 2.0TDI',
            'description' => 'Opis skrzyni',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
            'parameters' => [['name' => 'Numer części', 'values' => ['0CN409053AF']]],
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-prepare-preview?sku=JAREK-18727785496');

        $response->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('parts_changed', false)
            ->assertJsonPath('source_table', 'jarek_gearboxes')
            ->assertJsonPath('source_brand_candidates', ['Volkswagen', 'Skoda', 'Audi'])
            ->assertJsonPath('selected_brand', 'Audi')
            ->assertJsonPath('brand_selection_reason', 'preferred_detected_brand')
            ->assertJsonPath('brand_source', 'title')
            ->assertJsonPath('item_specifics.Brand', 'Audi')
            ->assertJsonPath('item_specifics.Hersteller', 'Audi')
            ->assertJsonPath('payload_preview.source_brand_candidates', ['Volkswagen', 'Skoda', 'Audi'])
            ->assertJsonPath('payload_preview.selected_brand', 'Audi')
            ->assertJsonPath('payload_preview.item_specifics.Brand', 'Audi')
            ->assertJsonMissing(['Brand' => 'GPSwiss'])
            ->assertJsonMissing(['Manufacturer' => 'GPSwiss'])
            ->assertJsonMissing(['Hersteller' => 'GPSwiss']);
    }


    public function test_ebay_de_prepare_preview_adds_gearbox_core_return_notice_from_title(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        $this->mockTranslations();
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18717293813/01.jpg', 'jpg');

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18717293813',
            'title' => 'Skrzynia biegów RGA Regnerowana VW Caddy 1.2TSI',
            'description' => 'Opis skrzyni',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-prepare-preview?sku=JAREK-18717293813');

        $response->assertOk()
            ->assertJsonPath('core_return_required', true)
            ->assertJsonPath('core_return_type', 'gearbox')
            ->assertJsonPath('core_return_notice_added', true)
            ->assertJsonPath('core_return_notice_pl', 'Stara skrzynia biegów podlega zwrotowi')
            ->assertJsonPath('core_return_notice_de', 'Das Altgetriebe muss zurückgegeben werden.')
            ->assertJsonPath('core_return_notice_added_after_translation', true)
            ->assertJsonPath('core_return_notice_location', 'payload_template_footer')
            ->assertJsonPath('source_description_pl_cleaned', 'Opis skrzyni')
            ->assertJsonPath('payload_preview.core_return_required', true)
            ->assertJsonPath('payload_preview.core_return_type', 'gearbox')
            ->assertJsonPath('payload_preview.core_return_notice_added_after_translation', true)
            ->assertJsonFragment(['gearbox_core_return_notice_added']);

        $this->assertSame(0, substr_count($response->json('translated_description_de'), 'Das Altgetriebe muss zurückgegeben werden.'));
        $this->assertStringNotContainsString('Die alte Hinterachse muss zurückgegeben werden.', $response->json('translated_description_de'));
        $this->assertStringNotContainsString('Das alte Getriebe kann zurückgegeben werden.', $response->json('translated_description_de'));
        $this->assertSame(1, substr_count($response->json('rendered_description_de_template'), 'Das Altgetriebe muss zurückgegeben werden.'));
        $this->assertSame(1, substr_count($response->json('payload_preview.description'), 'Das Altgetriebe muss zurückgegeben werden.'));
        $this->assertStringNotContainsString('Das alte Getriebe kann zurückgegeben werden.', $response->json('rendered_description_de_template'));
        $this->assertStringEndsWith('Das Altgetriebe muss zurückgegeben werden.', $response->json('rendered_description_de_template'));
    }

    public function test_ebay_de_prepare_preview_removes_soft_google_core_return_text_before_appending_controlled_notice(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18717293813/01.jpg', 'jpg');

        $this->mock(GoogleTranslateService::class, function ($mock): void {
            $mock->shouldReceive('translate')->zeroOrMoreTimes()->andReturnUsing(fn (string $text): array => [
                'translated_text' => str_contains($text, 'Opis skrzyni') ? 'Getriebebeschreibung. Das alte Getriebe kann zurückgegeben werden.' : $text,
                'warnings' => [],
                'blockers' => [],
            ]);
        });

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18717293813',
            'title' => 'Skrzynia biegów RGA Regnerowana VW Caddy 1.2TSI',
            'description' => 'Opis skrzyni. Stara skrzynia biegów podlega zwrotowi',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-prepare-preview?sku=JAREK-18717293813');

        $response->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('source_description_pl_cleaned', 'Opis skrzyni.')
            ->assertJsonPath('core_return_notice_added_after_translation', true)
            ->assertJsonPath('core_return_notice_location', 'payload_template_footer');

        $this->assertStringNotContainsString('Stara skrzynia biegów podlega zwrotowi', $response->json('source_description_pl_cleaned'));
        $this->assertStringNotContainsString('Das alte Getriebe kann zurückgegeben werden.', $response->json('translated_description_de'));
        $this->assertStringNotContainsString('Die alte Hinterachse muss zurückgegeben werden.', $response->json('translated_description_de'));
        $this->assertSame(0, substr_count($response->json('translated_description_de'), 'Das Altgetriebe muss zurückgegeben werden.'));
        $this->assertStringNotContainsString('Das alte Getriebe kann zurückgegeben werden.', $response->json('rendered_description_de_template'));
        $this->assertSame(1, substr_count($response->json('rendered_description_de_template'), 'Das Altgetriebe muss zurückgegeben werden.'));
        $this->assertSame(1, substr_count($response->json('payload_preview.description'), 'Das Altgetriebe muss zurückgegeben werden.'));
        $this->assertStringEndsWith('Das Altgetriebe muss zurückgegeben werden.', $response->json('rendered_description_de_template'));
    }

    public function test_ebay_de_prepare_preview_adds_rear_axle_core_return_notice_from_title(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        $this->mockTranslations();
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18720000000/01.jpg', 'jpg');

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18720000000',
            'title' => 'Tylny Most VW Crafter A9063502500 51/10 Bliźniak',
            'description' => 'Opis mostu',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-prepare-preview?sku=JAREK-18720000000');

        $response->assertOk()
            ->assertJsonPath('core_return_required', true)
            ->assertJsonPath('core_return_type', 'rear_axle')
            ->assertJsonPath('core_return_notice_added', true)
            ->assertJsonPath('core_return_notice_de', 'Die alte Hinterachse muss zurückgegeben werden.')
            ->assertJsonPath('core_return_notice_location', 'payload_template_footer')
            ->assertJsonFragment(['rear_axle_core_return_notice_added']);

        $this->assertStringNotContainsString('Die alte Hinterachse muss zurückgegeben werden.', $response->json('translated_description_de'));
        $this->assertSame(1, substr_count($response->json('rendered_description_de_template'), 'Die alte Hinterachse muss zurückgegeben werden.'));
        $this->assertStringEndsWith('Die alte Hinterachse muss zurückgegeben werden.', $response->json('rendered_description_de_template'));
    }

    public function test_ebay_de_prepare_preview_does_not_add_core_return_notice_for_reductor_title(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        $this->mockTranslations();
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18727785496/01.jpg', 'jpg');

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18727785496',
            'title' => 'Reduktor skrzyni 0CN409053AF VW Skoda Audi 2.0TDI',
            'description' => 'Opis reduktora',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-prepare-preview?sku=JAREK-18727785496');

        $response->assertOk()
            ->assertJsonPath('core_return_required', false)
            ->assertJsonPath('core_return_type', null)
            ->assertJsonPath('core_return_notice_added', false)
            ->assertJsonPath('core_return_notice_pl', null)
            ->assertJsonPath('core_return_notice_de', null)
            ->assertJsonMissing(['gearbox_core_return_notice_added'])
            ->assertJsonMissing(['rear_axle_core_return_notice_added']);

        $this->assertStringNotContainsString('Das Altgetriebe muss zurückgegeben werden.', $response->json('rendered_description_de_template'));
        $this->assertStringNotContainsString('Die alte Hinterachse muss zurückgegeben werden.', $response->json('rendered_description_de_template'));
    }


    public function test_ebay_de_publish_preview_returns_full_dry_run_api_plan_from_existing_settings(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        $this->mockTranslations();
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18727785496/01.jpg', 'jpg');

        MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de',
            'code' => 'ebay_de',
            'name' => 'eBay DE',
            'status' => 'active',
            'api_enabled' => true,
            'api_mode' => 'dry_run',
            'api_settings' => [
                'marketplace_id' => 'EBAY_DE',
                'merchant_location_key' => 'gpswiss-de-location',
                'payment_policy_id' => 'payment-de',
                'return_policy_id' => 'return-de',
                'format' => 'FIXED_PRICE',
                'listing_duration' => 'GTC',
            ],
        ]);

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18727785496',
            'title' => 'Skrzynia DSG 0D9300041 Audi Regnerowana',
            'description' => 'Opis skrzyni',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
            'parameters' => [['id' => '11323', 'name' => 'Stan', 'valuesLabels' => ['Używany']], ['name' => 'Numer części', 'values' => ['0D9300041']]],
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-publish-preview?sku=JAREK-18727785496');

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('ready', true)
            ->assertJsonPath('blockers', [])
            ->assertJsonPath('apply_requires_confirm', 'jarek-ebay-de-publish-one')
            ->assertJsonPath('idempotency.apply_requires_confirm', 'jarek-ebay-de-publish-one')
            ->assertJsonPath('ebay_api_plan.merchant_location_key', 'gpswiss-de-location')
            ->assertJsonPath('ebay_api_plan.marketplace_id', 'EBAY_DE')
            ->assertJsonPath('ebay_api_plan.fulfillment_policy_id', 'fulfillment-de-50')
            ->assertJsonPath('ebay_api_plan.payment_policy_id', 'payment-de')
            ->assertJsonPath('ebay_api_plan.return_policy_id', 'return-de')
            ->assertJsonPath('ebay_api_plan.format', 'FIXED_PRICE')
            ->assertJsonPath('ebay_api_plan.listing_duration', 'GTC')
            ->assertJsonPath('ebay_api_plan.category_id', '100684')
            ->assertJsonPath('ebay_api_plan.sku', 'JAREK-18727785496')
            ->assertJsonPath('ebay_api_plan.quantity', 1)
            ->assertJsonPath('ebay_api_plan.price', 569.77)
            ->assertJsonPath('ebay_api_plan.currency', 'EUR')
            ->assertJsonPath('ebay_api_plan.inventory_item_request.availability.shipToLocationAvailability.quantity', 1)
            ->assertJsonPath('ebay_api_plan.inventory_item_request.condition', 'SELLER_REFURBISHED')
            ->assertJsonPath('ebay_api_plan.source_condition_name', 'Stan')
            ->assertJsonPath('ebay_api_plan.source_condition_value', 'Używany')
            ->assertJsonPath('ebay_api_plan.source_condition_parameter_id', '11323')
            ->assertJsonPath('ebay_api_plan.condition_source', 'parameters')
            ->assertJsonPath('ebay_api_plan.condition_mapping_reason', 'localized_condition_map')
            ->assertJsonPath('ebay_api_plan.mapped_ebay_condition', 'SELLER_REFURBISHED')
            ->assertJsonPath('ebay_api_plan.condition_mapped_value', 'SELLER_REFURBISHED')
            ->assertJsonPath('ebay_api_plan.condition_diagnostics.condition_mapping_valid', true)
            ->assertJsonPath('ebay_api_plan.inventory_item_request.product.description', 'Opis skrzyni')
            ->assertJsonPath('ebay_api_plan.inventory_description_source', 'translated_description_de')
            ->assertJsonPath('ebay_api_plan.offer_request.marketplaceId', 'EBAY_DE')
            ->assertJsonPath('ebay_api_plan.offer_request.listingPolicies.fulfillmentPolicyId', 'fulfillment-de-50')
            ->assertJsonPath('ebay_api_plan.publish_offer_request.method', 'POST');

        $listingDescription = $response->json('ebay_api_plan.offer_request.listingDescription');
        $this->assertStringContainsString('Beschreibung', $listingDescription);
        $this->assertStringNotContainsString('Spezifikationen', $listingDescription);
        $this->assertStringNotContainsString('Teilenummer', $listingDescription);
        $this->assertStringNotContainsString('Zustand / Qualität', $listingDescription);
        $this->assertStringContainsString('Das Altgetriebe muss zurückgegeben werden.', $listingDescription);
        $this->assertStringNotContainsString('BeschreibungWir bieten', $response->json('ebay_api_plan.inventory_item_request.product.description'));
    }


    public function test_ebay_de_revise_preview_builds_json_payload_without_response_exception_blocker(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        Http::fake([
            'https://gpswiss.pl/storage/jarek-gearboxes/18727785496/01.jpg' => Http::response("\xFF\xD8\xFFjpg", 200, ['Content-Type' => 'image/jpeg']),
            'https://www.gpswiss.pl/storage/jarek-gearboxes/18727785496/01.jpg' => Http::response("\xFF\xD8\xFFjpg", 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->mockTranslations();
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18727785496/01.jpg', "\xFF\xD8\xFFjpg");

        MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de',
            'code' => 'ebay_de',
            'name' => 'eBay DE',
            'status' => 'active',
            'api_enabled' => false,
            'api_mode' => 'dry_run',
            'api_settings' => [
                'marketplace_id' => 'EBAY_DE',
                'merchant_location_key' => 'gpswiss-de-location',
                'payment_policy_id' => 'payment-de',
                'return_policy_id' => 'return-de',
                'format' => 'FIXED_PRICE',
                'listing_duration' => 'GTC',
            ],
        ]);

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18727785496',
            'title' => 'Skrzynia DSG 0D9300041 Audi',
            'description' => 'Opis skrzyni',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
            'parameters' => [['id' => '11323', 'name' => 'Stan', 'valuesLabels' => ['Używany']], ['name' => 'Numer części', 'values' => ['0D9300041']]],
            'ebay_listing_id' => '1234567890',
            'ebay_offer_id' => '9876543210',
            'ebay_inventory_sku' => 'JAREK-18727785496',
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-revise-preview?sku=JAREK-18727785496');

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonMissing(['revise_preview_response_exception'])
            ->assertJsonMissing(['error_message' => 'data_set(): Argument #1 ($target) could not be passed by reference'])
            ->assertJsonPath('public_image_urls.0', 'https://gpswiss.pl/storage/jarek-gearboxes/18727785496/01.jpg')
            ->assertJsonPath('revised_inventory_item_request.product.imageUrls.0', 'https://gpswiss.pl/storage/jarek-gearboxes/18727785496/01.jpg')
            ->assertJsonPath('revised_offer_request.offerId', '9876543210');
    }



    public function test_ebay_de_bulk_prepare_preview_returns_dry_run_partial_results_with_summary(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);
        $this->mockTranslations();
        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/18727785496/01.jpg', 'jpg');

        MarketplaceAccount::query()->create([
            'code' => 'ebay_de',
            'name' => 'eBay DE',
            'marketplace' => 'ebay',
            'api_enabled' => false,
            'api_settings' => [
                'marketplace_id' => 'EBAY_DE',
                'merchant_location_key' => 'gpswiss-de',
                'payment_policy_id' => 'payment-de',
                'return_policy_id' => 'return-de',
                'format' => 'FIXED_PRICE',
                'listing_duration' => 'GTC',
            ],
        ]);

        JarekGearbox::query()->create([
            'allegro_offer_id' => '18727785496',
            'title' => 'Skrzynia DSG 0D9300041 Audi',
            'description' => 'Opis skrzyni',
            'price' => 2450,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
            'parameters' => [['id' => '11323', 'name' => 'Stan', 'valuesLabels' => ['Używany']], ['name' => 'Numer części', 'values' => ['0D9300041']]],
            'ebay_listing_id' => '1234567890',
            'ebay_offer_id' => '9876543210',
            'ebay_inventory_sku' => 'JAREK-18727785496',
        ]);
        JarekGearbox::query()->create([
            'allegro_offer_id' => '18720000000',
            'title' => 'Skrzynia manualna VW',
            'description' => 'Opis skrzyni',
            'price' => 1000,
            'currency' => 'PLN',
            'quantity' => 1,
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
            'parameters' => [['id' => '11323', 'name' => 'Stan', 'valuesLabels' => ['Używany']]],
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-de-bulk-prepare-preview?limit=20');

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.missing_images_count', 1)
            ->assertJsonPath('summary.missing_offer_id_count', 1)
            ->assertJsonPath('offers.0.sku', 'JAREK-18727785496')
            ->assertJsonPath('offers.0.offer_id', '9876543210')
            ->assertJsonPath('offers.0.listing_id', '1234567890')
            ->assertJsonPath('offers.0.public_image_urls.0', 'https://gpswiss.pl/storage/jarek-gearboxes/18727785496/01.jpg')
            ->assertJsonPath('offers.0.revised_offer_request.offerId', '9876543210')
            ->assertJsonPath('offers.1.sku', 'JAREK-18720000000')
            ->assertJsonFragment(['no_ebay_api_write']);
    }

    public function test_ebay_de_revise_apply_is_locked_to_single_sku_and_exact_confirm(): void
    {
        $this->withoutMiddleware()
            ->getJson('/admin/tools/jarek-gearboxes/ebay-de-revise-apply?confirm=jarek-ebay-de-revise-one&sku=JAREK-OTHER')
            ->assertForbidden()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('blockers.0', 'sku_not_allowed');

        $this->withoutMiddleware()
            ->getJson('/admin/tools/jarek-gearboxes/ebay-de-revise-apply?confirm=wrong&sku=JAREK-18727785496')
            ->assertForbidden()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('blockers.0', 'missing_or_invalid_confirm_token');

        $this->withoutMiddleware()
            ->getJson('/admin/tools/jarek-gearboxes/ebay-de-revise-apply?sku=JAREK-18727785496')
            ->assertForbidden()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('blockers.0', 'missing_or_invalid_confirm_token');
    }


    public function test_ebay_de_publish_apply_is_locked_to_single_sku_and_exact_confirm(): void
    {
        $this->withoutMiddleware()
            ->getJson('/admin/tools/jarek-gearboxes/ebay-de-publish-apply?confirm=jarek-ebay-de-publish-one&sku=JAREK-OTHER')
            ->assertForbidden()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('blockers.0', 'sku_not_allowed');

        $this->withoutMiddleware()
            ->getJson('/admin/tools/jarek-gearboxes/ebay-de-publish-apply?confirm=wrong&sku=JAREK-18727785496')
            ->assertForbidden()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('blockers.0', 'invalid_confirm');
    }

    private function mockTranslations(): void
    {
        $this->mock(GoogleTranslateService::class, function ($mock): void {
            $mock->shouldReceive('translate')->zeroOrMoreTimes()->andReturnUsing(fn (string $text): array => [
                'translated_text' => $text,
                'warnings' => [],
                'blockers' => [],
            ]);
        });
    }

    private function createCategoryMappings(string $allegroCategoryId, string $ebayCategoryId): void
    {
        $category = PartCategory::query()->create(['name' => 'Skrzynie biegów', 'category_path' => 'Motoryzacja > Części > Skrzynie biegów']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => $allegroCategoryId, 'external_category_name' => 'Skrzynie biegów']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => $ebayCategoryId, 'external_category_name' => 'Getriebe', 'shipping_group' => 'de_50_eur', 'fulfillment_policy_id' => 'fulfillment-de-50']);
    }
}
