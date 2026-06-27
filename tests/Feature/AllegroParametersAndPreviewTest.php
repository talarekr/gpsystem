<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\AllegroOfferParametersBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroParametersAndPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetches_category_parameters_read_only_and_caches_definitions(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'status' => 'enabled', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.test', 'api_credentials' => ['access_token' => 'token']]);
        Http::fake(['https://api.allegro.test/sale/categories/123/parameters' => Http::response(['parameters' => [$this->dict('11323', 'Stan', true, false, ['u' => 'Używany'])]], 200)]);

        $category = PartCategory::query()->create(['name' => 'Lampy']);
        $part = Part::query()->create(['name' => 'Lampa', 'category_id' => $category->id, 'price' => 100, 'quantity' => 1, 'description' => 'Opis']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => '123']);

        $first = app(AllegroOfferParametersBuilder::class)->build($part);
        $second = app(AllegroOfferParametersBuilder::class)->build($part);

        $this->assertSame('api', $first['parameter_definitions_source']);
        $this->assertSame('cache', $second['parameter_definitions_source']);
        $this->assertSame([['id' => '11323', 'name' => 'Stan', 'value_source' => 'fixed_business_rule', 'valuesIds' => ['u'], 'resolved_label' => 'Używany']], $first['allegro_offer_parameters']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/sale/categories/123/parameters'));
    }

    public function test_missing_credentials_and_api_error_are_blockers(): void
    {
        $category = PartCategory::query()->create(['name' => 'Lampy']);
        $part = Part::query()->create(['name' => 'Lampa', 'category_id' => $category->id]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => '123']);
        $this->assertContains('allegro_credentials_missing', app(AllegroOfferParametersBuilder::class)->build($part)['blockers']);

        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'status' => 'enabled', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.test', 'api_credentials' => ['access_token' => 'token']]);
        Http::fake(['https://api.allegro.test/*' => Http::response([], 500)]);
        $this->assertContains('allegro_category_parameters_unavailable', app(AllegroOfferParametersBuilder::class)->build($part)['blockers']);
    }

    public function test_builder_splits_product_offer_parameters_maps_dictionary_and_does_not_guess(): void
    {
        DB::table('allegro_category_parameters_cache')->insert(['allegro_category_id' => '123', 'raw_response' => json_encode(['parameters' => [
            $this->dict('stan', 'Stan', true, false, ['used' => 'Używany']),
            $this->dict('gvo', 'Jakość części (zgodnie z GVO)', true, true, ['oe' => 'O - oryginał z logo producenta pojazdu (OE)']),
            $this->dict('side', 'Strona zabudowy', true, false, ['front-left' => 'Przód strona lewa']),
            $this->dict('kind', 'Rodzaj', true, false, ['x' => 'X']),
            $this->dict('invoice', 'Faktura', false, false, ['vat' => 'VAT']),
        ]]), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $category = PartCategory::query()->create(['name' => 'Lampy']);
        $part = Part::query()->create(['name' => 'Lampa', 'category_id' => $category->id, 'legacy_payload' => ['part_position' => 'przód strona lewa']]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => '123']);

        $result = app(AllegroOfferParametersBuilder::class)->build($part);
        $this->assertSame(['oe'], $result['allegro_product_parameters'][0]['valuesIds']);
        $this->assertEqualsCanonicalizing(['used', 'front-left'], array_merge(...array_map(fn ($p) => $p['valuesIds'] ?? [], $result['allegro_offer_parameters'])));
        $this->assertContains('Rodzaj', array_column($result['missing_required_parameters'], 'name'));
        $this->assertContains('Faktura', array_column($result['unmapped_parameters'], 'name'));
    }

    public function test_preview_renders_without_write_or_listing_creation(): void
    {
        DB::table('allegro_category_parameters_cache')->insert(['allegro_category_id' => '123', 'raw_response' => json_encode(['parameters' => []]), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $category = PartCategory::query()->create(['name' => 'Lampy']);
        $part = Part::query()->create(['name' => 'Lampa', 'category_id' => $category->id, 'price' => 100, 'quantity' => 1, 'description' => 'Opis']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => '123']);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/a.jpg', 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/tools/allegro-listing-preview?token=gps_images_import_2026&part_id='.$part->id)
            ->assertOk()->assertSee('To jest tylko podgląd')->assertSee('Parametry Allegro')->assertSee('will_make_marketplace_request=false');
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id]);
    }

    private function dict(string $id, string $name, bool $required, bool $product, array $values): array
    {
        return ['id' => $id, 'name' => $name, 'required' => $required, 'options' => ['describesProduct' => $product], 'dictionary' => ['values' => collect($values)->map(fn ($label, $id) => ['id' => $id, 'value' => $label])->values()->all()]];
    }
}
