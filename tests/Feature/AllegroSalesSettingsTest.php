<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartImage;
use App\Services\Marketplace\AllegroSalesSettingsResolver;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\MarketplacePublishGate;
use App\Services\Marketplace\Publishing\AllegroPublishAdapter;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroSalesSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_selected_allegro_courier_blocks_readiness(): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->part(null);

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');

        $this->assertContains('missing_allegro_shipping_rate', $result['blockers']);
        $this->assertSame('missing', $result['prepared_payload_preview_safe']['allegro_sales_settings']['shippingRates']['status']);
    }

    public function test_selected_kurier_dpd_is_used_by_shipping_rates_resolver(): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->part('KURIER DPD');

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');

        $settings = $result['prepared_payload_preview_safe']['allegro_sales_settings'];
        $this->assertSame('KURIER DPD', $settings['selected_allegro_shipping_rate_name']);
        $this->assertSame('ship-dpd', $settings['shippingRates']['id']);
        $this->assertSame('mapped', $settings['shippingRates']['status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sale/shipping-rates'));
    }

    public function test_inactive_gabaryty_is_not_available_in_select(): void
    {
        $this->assertArrayNotHasKey('GABARYTY CZ SK HU', AllegroSalesSettingsResolver::SHIPPING_RATE_OPTIONS);
        $resource = file_get_contents(app_path('Filament/Resources/PartResource.php'));
        $this->assertStringContainsString("Section::make('Kurier Allegro')", $resource);
        $this->assertStringContainsString("->hiddenLabel()", $resource);
        $this->assertStringContainsString("->options(AllegroSalesSettingsResolver::SHIPPING_RATE_OPTIONS)", $resource);
        $this->assertStringContainsString("->native(true)", $resource);
        $this->assertStringNotContainsString("->label('Kurier Allegro')", $resource);
    }

    public function test_manual_return_policy_mapping_keeps_readiness_unblocked_when_api_does_not_return_it(): void
    {
        Http::fake($this->fakeAllegro(returnPolicies: []));
        $part = $this->part('KURIER DPD');

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');

        $this->assertNotContains('allegro_returnPolicy_missing:ZWROTGOLD', $result['blockers']);
        $this->assertSame('mapped', $result['prepared_payload_preview_safe']['allegro_sales_settings']['returnPolicy']['status']);
        $this->assertTrue($result['prepared_payload_preview_safe']['allegro_sales_settings']['returnPolicy']['found']);
        $this->assertSame('91968c35-8bc3-4d74-baba-3609e4013f63', $result['prepared_payload_preview_safe']['allegro_sales_settings']['returnPolicy']['id']);
    }

    public function test_manual_sales_settings_mapping_is_used_when_allegro_read_returns_forbidden(): void
    {
        Http::fake([
            'https://api.allegro.pl/sale/categories/123/parameters' => Http::response(['parameters' => []], 200),
            'https://api.allegro.pl/sale/shipping-rates' => Http::response(['errors' => []], 403),
            'https://api.allegro.pl/after-sales-service-conditions/return-policies' => Http::response(['errors' => []], 403),
            'https://api.allegro.pl/after-sales-service-conditions/implied-warranties' => Http::response(['errors' => []], 403),
            'https://api.allegro.pl/after-sales-service-conditions/warranties' => Http::response(['errors' => []], 403),
        ]);
        $part = $this->part('KURIER DPD NIESTANDARDOWY');

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');
        $settings = $result['prepared_payload_preview_safe']['allegro_sales_settings'];

        $this->assertNotContains('allegro_shipping_rate_missing_or_inactive', $result['blockers']);
        $this->assertSame('KURIER DPD NIESTANDARDOWY', $settings['selected_allegro_shipping_rate_name']);
        $this->assertSame('KURIER DPD NIESTANDARDOWY', $settings['shippingRates']['searched_name']);
        $this->assertSame('82c9b952-37e0-4378-8911-cd8a5e7d7816', $settings['shippingRates']['resolved_id']);
        $this->assertSame('mapped', $settings['shippingRates']['status']);
        $this->assertTrue($settings['shippingRates']['found']);
        $this->assertSame('read_failed', $settings['shippingRates']['read_status']);
        $this->assertSame(403, $settings['shippingRates']['http_status']);
        $this->assertSame('91968c35-8bc3-4d74-baba-3609e4013f63', $settings['returnPolicy']['id']);
        $this->assertSame('1d19a257-7203-4227-88a8-f79f28531eea', $settings['impliedWarranty']['id']);
        $this->assertSame('6174a76b-b25c-4994-909c-fb7a161deea8', $settings['warranty']['id']);
    }

    public function test_resolved_after_sales_ids_are_in_dry_run_payload(): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->part('KURIER DPD');

        $response = $this->getJson('/tools/dry-run-marketplace-listing-payload?token=gps_images_import_2026&channel=allegro_main&part_id='.$part->id);

        $response->assertOk();
        $payload = $response->json('payload') ?: [];
        $this->assertSame('ship-dpd', data_get($payload, 'delivery.shippingRates.id'));
        $this->assertSame('ret-1', data_get($payload, 'afterSalesServices.returnPolicy.id'));
        $this->assertSame('imp-1', data_get($payload, 'afterSalesServices.impliedWarranty.id'));
        $this->assertSame('war-1', data_get($payload, 'afterSalesServices.warranty.id'));
        $this->assertSame('Błotnik Audi', data_get($payload, 'productSet.0.product.name'));
        $this->assertSame([$payload['image_urls'][0]], data_get($payload, 'productSet.0.product.images'));
        $this->assertSame(1, $response->json('payload_summary.productSet_0_product_images_count'));
        $this->assertTrue($response->json('payload_summary.productSet_0_product_main_image_present'));
    }


    public function test_allegro_live_payload_accepts_string_image_urls_and_preserves_sales_settings_and_description(): void
    {
        Http::fake(array_merge($this->fakeAllegro(), [
            'https://api.allegro.pl/sale/product-offers' => Http::response(['id' => 'offer-123'], 201),
        ]));
        $part = $this->part('KURIER DPD');
        $payload = [
            'sku' => 'GPS-7890',
            'title' => 'Błotnik Audi',
            'category_id' => '123',
            'price_pln' => 100,
            'quantity' => 1,
            'image_urls' => ['https://gpswiss.pl/storage/parts/photos/imported/7890/kuyJdjAM4xzYvW7Hoy0YQ7WlCKE8nRfkSUskHyT0.jpg', 'https://gpswiss.example.test/parts/7890/two.jpg'],
            'description' => ['sections' => [['items' => [['type' => 'TEXT', 'content' => '<p>Opis ręczny nie powinien wejść</p>']]]]],
            'allegro_parameters' => [
                'payload_parameters' => [['id' => '11323', 'valuesIds' => ['used']]],
                'product_parameters' => [['id' => '129917', 'valuesIds' => ['129917_2']]],
            ],
        ];

        $adapter = new class(
            app(MarketplaceListingReadinessService::class),
            app(MarketplacePublishGate::class),
            app(ApiIntegrationLogger::class),
            app(AllegroSalesSettingsResolver::class),
        ) extends AllegroPublishAdapter {
            public function callPerformLivePublish(Part $part, array $readiness, array $payload, MarketplaceAccount $account): array
            {
                return $this->performLivePublish($part, $readiness, $payload, $account);
            }
        };

        $result = $adapter->callPerformLivePublish($part, ['marketplace_price' => 100], $payload, MarketplaceAccount::query()->where('code', 'allegro_main')->firstOrFail());

        $this->assertTrue($result['ok']);
        $this->assertSame('offer-123', $result['offer_id']);
        $this->assertSame('offer-123', $result['external_offer_id']);
        $this->assertSame('https://allegro.pl/oferta/offer-123', $result['url']);
        $this->assertSame('strings', $result['request_summary']['images_shape']);
        $this->assertSame('string', $result['request_summary']['first_image_type']);
        $this->assertSame(['11323'], $result['request_summary']['payload_parameters_ids']);
        $this->assertSame(['129917'], $result['request_summary']['productSet_0_product_parameters_ids']);
        $this->assertSame(1, $result['request_summary']['productSet_0_product_images_count']);
        $this->assertTrue($result['request_summary']['productSet_0_product_main_image_present']);
        $this->assertSame(1, $result['request_summary']['description_sections_count']);
        $this->assertTrue($result['request_summary']['description_has_non_empty_content']);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.allegro.pl/sale/product-offers') {
                return false;
            }

            $data = $request->data();

            return $data['images'] === ['https://gpswiss.pl/storage/parts/photos/imported/7890/kuyJdjAM4xzYvW7Hoy0YQ7WlCKE8nRfkSUskHyT0.jpg', 'https://gpswiss.example.test/parts/7890/two.jpg']
                && data_get($data, 'delivery.shippingRates.id') === 'ship-dpd'
                && data_get($data, 'afterSalesServices.returnPolicy.id') === 'ret-1'
                && data_get($data, 'afterSalesServices.impliedWarranty.id') === 'imp-1'
                && data_get($data, 'afterSalesServices.warranty.id') === 'war-1'
                && str_contains((string) data_get($data, 'description.sections.0.items.0.content'), 'Witam oferta dotyczy')
                && str_contains((string) data_get($data, 'description.sections.0.items.0.content'), 'CZĘŚĆ SPRAWNA. STAN WIDOCZNY NA ZDJĘCIACH')
                && str_contains((string) data_get($data, 'description.sections.0.items.0.content'), 'Marka:')
                && str_contains((string) data_get($data, 'description.sections.0.items.0.content'), 'Model:')
                && data_get($data, 'productSet.0.product.name') === 'Błotnik Audi'
                && data_get($data, 'productSet.0.product.images') === ['https://gpswiss.pl/storage/parts/photos/imported/7890/kuyJdjAM4xzYvW7Hoy0YQ7WlCKE8nRfkSUskHyT0.jpg']
                && array_column($data['parameters'], 'id') === ['11323']
                && array_column(data_get($data, 'productSet.0.product.parameters'), 'id') === ['129917'];
        });
    }


    public function test_live_publish_payload_removes_product_scoped_duplicates_from_offer_parameters(): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->part('KURIER DPD');
        $payload = [
            'title' => 'Część SUV',
            'category_id' => '256035',
            'price_pln' => 100,
            'quantity' => 1,
            'image_urls' => ['https://gpswiss.pl/storage/parts/photos/imported/7890/kuyJdjAM4xzYvW7Hoy0YQ7WlCKE8nRfkSUskHyT0.jpg'],
            'allegro_parameters' => [
                'payload_parameters' => [
                    ['id' => '11323', 'valuesIds' => ['11323_2']],
                    ['id' => '129591', 'valuesIds' => ['129591_64']],
                ],
                'product_parameters' => [
                    ['id' => '129591', 'valuesIds' => ['129591_64']],
                ],
            ],
        ];

        $adapter = new class(app(MarketplaceListingReadinessService::class), app(MarketplacePublishGate::class), app(ApiIntegrationLogger::class), app(AllegroSalesSettingsResolver::class)) extends AllegroPublishAdapter {
            public function callPerformLivePublish(Part $part, array $readiness, array $payload, MarketplaceAccount $account): array
            {
                return $this->performLivePublish($part, $readiness, $payload, $account);
            }
        };

        $result = $adapter->callPerformLivePublish($part, ['marketplace_price' => 100], $payload, MarketplaceAccount::query()->where('code', 'allegro_main')->firstOrFail());

        $this->assertSame(['11323'], $result['request_summary']['offer_parameter_ids']);
        $this->assertSame(['129591'], $result['request_summary']['product_parameter_ids']);
        $this->assertSame([], $result['request_summary']['duplicated_parameter_ids']);
        $this->assertSame(['129591'], $result['request_summary']['removed_from_offer_parameters_due_to_product_scope']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.allegro.pl/sale/product-offers'
            && array_column($request->data()['parameters'], 'id') === ['11323']
            && array_column(data_get($request->data(), 'productSet.0.product.parameters'), 'id') === ['129591']);
    }

    public function test_allegro_readiness_payload_includes_builder_description_for_live_publish(): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->part('KURIER DPD');
        $part->forceFill(['vehicle_snapshot' => [
            'make' => 'Audi',
            'model' => 'A4',
            'production_year' => '2018',
            'engine_code' => 'CAGA',
        ]])->save();

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');

        $this->assertSame([], $result['prepared_payload_preview_safe']['allegro_description_blockers']);
        $this->assertSame('TEXT', data_get($result, 'prepared_payload_preview_safe.description.sections.0.items.0.type'));
        $this->assertStringContainsString('Opis', data_get($result, 'prepared_payload_preview_safe.description.sections.0.items.0.content'));
        $this->assertSame('IMAGE', data_get($result, 'prepared_payload_preview_safe.description.sections.0.items.1.type'));
    }

    public function test_allegro_product_name_uses_part_title_not_main_part_code(): void
    {
        Http::fake($this->fakeAllegro());
        $title = 'AUDI RSQ3 8U BOCZEK TAPICERKA DRZWI PRAWY TYLNY 8U0867306';
        $mainPartCode = '8U0867306';
        $part = $this->part('KURIER DPD');
        $part->forceFill(['name' => $title, 'part_number' => $mainPartCode])->save();

        $response = $this->getJson('/tools/dry-run-marketplace-listing-payload?token=gps_images_import_2026&channel=allegro_main&part_id='.$part->id);

        $response->assertOk();
        $payload = $response->json('payload') ?: [];
        $this->assertSame($title, data_get($payload, 'productSet.0.product.name'));
        $this->assertNotSame($mainPartCode, data_get($payload, 'productSet.0.product.name'));
        $this->assertGreaterThanOrEqual(12, mb_strlen((string) data_get($payload, 'productSet.0.product.name')));
        $this->assertSame($title, data_get($payload, 'product_name'));
        $this->assertSame('part_title', data_get($payload, 'product_name_source'));
        $this->assertSame(mb_strlen($title), data_get($payload, 'product_name_length'));
        $this->assertSame($title, data_get($payload, 'part_title'));
        $this->assertSame($mainPartCode, data_get($payload, 'main_part_code'));
        $this->assertFalse(data_get($payload, 'product_name_fallback_used'));
    }

    public function test_allegro_readiness_blocks_short_final_product_name_fallback(): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->part('KURIER DPD');
        $part->forceFill(['name' => '', 'part_number' => '8U0867306', 'vehicle_snapshot' => null])->save();

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');

        $this->assertContains('allegro_product_name_too_short', $result['blockers']);
        $this->assertSame('8U0867306', data_get($result, 'prepared_payload_preview_safe.product_name'));
        $this->assertSame(9, data_get($result, 'prepared_payload_preview_safe.product_name_length'));
    }

    public function test_allegro_live_payload_rebuilds_description_when_payload_contains_empty_placeholder(): void
    {
        Http::fake(array_merge($this->fakeAllegro(), [
            'https://api.allegro.pl/sale/product-offers' => Http::response(['id' => 'offer-123'], 201),
        ]));
        $part = $this->part('KURIER DPD');
        $part->forceFill(['vehicle_snapshot' => [
            'make' => 'Audi',
            'model' => 'A4',
            'production_year' => '2018',
            'engine_code' => 'CAGA',
        ]])->save();
        $payload = [
            'sku' => 'GPS-7890',
            'title' => 'Błotnik Audi',
            'category_id' => '123',
            'price_pln' => 100,
            'quantity' => 1,
            'image_urls' => ['https://gpswiss.pl/storage/parts/photos/imported/7890/kuyJdjAM4xzYvW7Hoy0YQ7WlCKE8nRfkSUskHyT0.jpg'],
            'description' => ['sections' => [['items' => [['type' => 'TEXT', 'content' => '<p></p>']]]]],
            'allegro_parameters' => ['payload_parameters' => [], 'product_parameters' => []],
        ];

        $adapter = new class(app(MarketplaceListingReadinessService::class), app(MarketplacePublishGate::class), app(ApiIntegrationLogger::class), app(AllegroSalesSettingsResolver::class)) extends AllegroPublishAdapter {
            public function callPerformLivePublish(Part $part, array $readiness, array $payload, MarketplaceAccount $account): array { return $this->performLivePublish($part, $readiness, $payload, $account); }
        };

        $result = $adapter->callPerformLivePublish($part, ['marketplace_price' => 100], $payload, MarketplaceAccount::query()->where('code', 'allegro_main')->firstOrFail());

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['request_summary']['description_has_non_empty_content']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.allegro.pl/sale/product-offers'
            && str_contains((string) data_get($request->data(), 'description.sections.0.items.0.content'), 'Opis')
            && data_get($request->data(), 'description.sections.0.items.1.type') === 'IMAGE');
    }


    public function test_allegro_live_payload_uses_part_description_before_payload_description(): void
    {
        Http::fake(array_merge($this->fakeAllegro(), [
            'https://api.allegro.pl/sale/product-offers' => Http::response(['id' => 'offer-123'], 201),
        ]));
        $part = $this->part('KURIER DPD');
        $part->forceFill(['description' => '<p>Lokalny opis części 7897<br>Numer: ABC</p>'])->save();
        $payload = [
            'sku' => 'GPS-7897',
            'title' => 'Mechanizm wycieraczek Audi',
            'category_id' => '123',
            'price_pln' => 100,
            'quantity' => 1,
            'image_urls' => ['https://gpswiss.pl/storage/parts/photos/imported/7890/kuyJdjAM4xzYvW7Hoy0YQ7WlCKE8nRfkSUskHyT0.jpg'],
            'description' => ['sections' => [['items' => [['type' => 'TEXT', 'content' => '<h2>Cechy produktu</h2><p>O produkcie marketingowy opis</p>']]]]],
            'allegro_parameters' => ['payload_parameters' => [], 'product_parameters' => []],
        ];

        $adapter = new class(app(MarketplaceListingReadinessService::class), app(MarketplacePublishGate::class), app(ApiIntegrationLogger::class), app(AllegroSalesSettingsResolver::class)) extends AllegroPublishAdapter {
            public function callPerformLivePublish(Part $part, array $readiness, array $payload, MarketplaceAccount $account): array { return $this->performLivePublish($part, $readiness, $payload, $account); }
        };

        $result = $adapter->callPerformLivePublish($part, ['marketplace_price' => 100], $payload, MarketplaceAccount::query()->where('code', 'allegro_main')->firstOrFail());

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['request_summary']['description_present']);
        $this->assertSame('allegro_gp_swiss_template', $result['request_summary']['description_source']);
        $this->assertSame('text_image_50_50', $result['request_summary']['description_template']);
        $this->assertGreaterThan(0, $result['request_summary']['description_sanitized_length']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.allegro.pl/sale/product-offers'
            && str_contains((string) data_get($request->data(), 'description.sections.0.items.0.content'), 'Lokalny opis części 7897 Numer: ABC')
            && ! str_contains((string) data_get($request->data(), 'description.sections.0.items.0.content'), 'Cechy produktu')
            && ! str_contains((string) data_get($request->data(), 'description.sections.0.items.0.content'), 'marketingowy opis'));
    }

    public function test_allegro_live_publish_blocks_before_api_when_template_markers_are_missing(): void
    {
        Http::fake(array_merge($this->fakeAllegro(), [
            'https://api.allegro.pl/sale/product-offers' => Http::response(['id' => 'offer-should-not-be-created'], 201),
        ]));
        $part = $this->part('KURIER DPD');
        $part->images()->delete();
        $payload = [
            'sku' => 'GPS-BLOCK',
            'title' => 'Mechanizm wycieraczek Audi',
            'category_id' => '123',
            'price_pln' => 100,
            'quantity' => 1,
            'image_urls' => ['https://gpswiss.example.test/not-builder.jpg'],
            'description' => ['sections' => [['items' => [['type' => 'TEXT', 'content' => '<p>Stary opis</p>']]]]],
            'allegro_parameters' => ['payload_parameters' => [], 'product_parameters' => []],
        ];

        $adapter = new class(app(MarketplaceListingReadinessService::class), app(MarketplacePublishGate::class), app(ApiIntegrationLogger::class), app(AllegroSalesSettingsResolver::class)) extends AllegroPublishAdapter {
            public function callPerformLivePublish(Part $part, array $readiness, array $payload, MarketplaceAccount $account): array { return $this->performLivePublish($part, $readiness, $payload, $account); }
        };

        $result = $adapter->callPerformLivePublish($part, ['marketplace_price' => 100], $payload, MarketplaceAccount::query()->where('code', 'allegro_main')->firstOrFail());

        $this->assertFalse($result['ok']);
        $this->assertSame('allegro_description_template_not_applied', $result['ui_error']);
        $this->assertFalse($result['write']);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.allegro.pl/sale/product-offers');
    }

    public function test_short_part_description_still_uses_gp_swiss_template(): void
    {
        Http::fake(array_merge($this->fakeAllegro(), [
            'https://api.allegro.pl/sale/product-offers' => Http::response(['id' => 'offer-123'], 201),
        ]));
        $part = $this->part('KURIER DPD');
        $part->forceFill(['description' => 'KLAPA W KOLOR'])->save();
        $payload = [
            'sku' => 'GPS-SHORT',
            'title' => 'Klapa tylna Audi',
            'category_id' => '123',
            'price_pln' => 100,
            'quantity' => 1,
            'image_urls' => ['https://gpswiss.pl/storage/parts/photos/imported/7890/kuyJdjAM4xzYvW7Hoy0YQ7WlCKE8nRfkSUskHyT0.jpg'],
            'allegro_parameters' => ['payload_parameters' => [], 'product_parameters' => []],
        ];

        $adapter = new class(app(MarketplaceListingReadinessService::class), app(MarketplacePublishGate::class), app(ApiIntegrationLogger::class), app(AllegroSalesSettingsResolver::class)) extends AllegroPublishAdapter {
            public function callPerformLivePublish(Part $part, array $readiness, array $payload, MarketplaceAccount $account): array { return $this->performLivePublish($part, $readiness, $payload, $account); }
        };

        $result = $adapter->callPerformLivePublish($part, ['marketplace_price' => 100], $payload, MarketplaceAccount::query()->where('code', 'allegro_main')->firstOrFail());

        $this->assertTrue($result['ok']);
        $this->assertSame('allegro_gp_swiss_template', $result['request_summary']['description_source']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.allegro.pl/sale/product-offers'
            && str_contains((string) data_get($request->data(), 'description.sections.0.items.0.content'), '<p><b>KLAPA W KOLOR</b></p>')
            && str_contains((string) data_get($request->data(), 'description.sections.0.items.0.content'), 'Witam oferta dotyczy'));
    }

    private function part(?string $shippingRateName): Part
    {
        config(['app.url' => 'https://gpswiss.pl']);
        MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'token']]);
        $part = Part::query()->create(['name' => 'Błotnik Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Audi', 'model' => 'A4'], 'is_visible_storefront' => true, 'allegro_shipping_rate_name' => $shippingRateName]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => 77, 'channel' => 'allegro_main', 'external_category_id' => '123']);
        PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/imported/7890/kuyJdjAM4xzYvW7Hoy0YQ7WlCKE8nRfkSUskHyT0.jpg', 'is_primary' => true, 'sort_order' => 1]);
        return $part;
    }

    private function fakeAllegro(array $returnPolicies = [['id' => 'ret-1', 'name' => 'ZWROTGOLD', 'status' => 'ACTIVE']]): array
    {
        return [
            'https://api.allegro.pl/sale/categories/123/parameters' => Http::response(['parameters' => []], 200),
            'https://api.allegro.pl/sale/shipping-rates' => Http::response(['shippingRates' => [
                ['id' => 'ship-dpd', 'name' => 'KURIER DPD', 'status' => 'ACTIVE'],
                ['id' => 'inactive', 'name' => 'GABARYTY CZ SK HU', 'status' => 'INACTIVE'],
            ]], 200),
            'https://api.allegro.pl/after-sales-service-conditions/return-policies' => Http::response(['returnPolicies' => $returnPolicies], 200),
            'https://api.allegro.pl/after-sales-service-conditions/implied-warranties' => Http::response(['impliedWarranties' => [['id' => 'imp-1', 'name' => 'GWARANCJA GOLD', 'status' => 'ACTIVE']]], 200),
            'https://api.allegro.pl/after-sales-service-conditions/warranties' => Http::response(['warranties' => [['id' => 'war-1', 'name' => 'GWARANTGOLD', 'status' => 'ACTIVE']]], 200),
        ];
    }
}
