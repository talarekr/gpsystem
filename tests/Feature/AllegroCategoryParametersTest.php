<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    private function parametersPayload(bool $requireSide = false): array
    {
        return ['parameters' => [
            ['id' => '11323', 'name' => 'Stan', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'used', 'value' => 'Używany']], 'options' => ['describesProduct' => true]],
            ['id' => 'quality', 'name' => 'Jakość części (zgodnie z GVO)', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'oe', 'value' => 'O - oryginał z logo producenta pojazdu (OE)']], 'options' => ['describesProduct' => false]],
            ['id' => 'side', 'name' => 'Strona zabudowy', 'type' => 'dictionary', 'required' => $requireSide, 'dictionary' => [['id' => 'front-back', 'value' => 'przód + tył']], 'options' => ['describesProduct' => false]],
        ]];
    }
}
