<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\AllegroCompatibilityMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroCompatibilityMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaf_category_is_supported_when_supported_categories_returns_parent_and_manual_candidates_are_diagnosed(): void
    {
        MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'token']]);
        $local = PartCategory::query()->create(['name' => 'Silniki kompletne']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $local->id, 'channel' => 'allegro_main', 'external_category_id' => '312565']);
        MarketplaceCategory::query()->create(['channel' => 'allegro_main', 'external_category_id' => '620', 'name' => 'Części samochodowe', 'full_path' => 'Części samochodowe']);
        MarketplaceCategory::query()->create(['channel' => 'allegro_main', 'external_category_id' => '312565', 'parent_external_category_id' => '620', 'name' => 'Silniki kompletne', 'full_path' => 'Części samochodowe > Silniki kompletne']);
        $part = Part::query()->create(['name' => 'Silnik Tiguan', 'category_id' => $local->id, 'part_number' => 'GPS-7157', 'vehicle_snapshot' => ['make' => 'Volkswagen', 'model' => 'Tiguan', 'production_year' => 2018, 'engine_capacity_cm3' => 1968, 'fuel_type' => 'TDI', 'engine_code' => 'CUAA']]);

        Http::fake([
            'https://api.allegro.pl/sale/compatibility-list/supported-categories' => Http::response(['categories' => [['id' => '620', 'name' => 'Części samochodowe', 'inputType' => 'ID', 'itemsType' => 'CAR']]], 200),
            'https://api.allegro.pl/sale/products*' => Http::response(['products' => []], 200),
            'https://api.allegro.pl/sale/compatible-products*' => Http::response(['compatibleProducts' => [['id' => 'tecdoc-1', 'text' => 'VW Tiguan 2.0 TDI CUAA', 'type' => 'CAR']]], 200),
        ]);

        $result = app(AllegroCompatibilityMappingService::class)->dryRun($part, ['name' => 'preview']);

        $this->assertTrue($result['category_supports_compatibility']);
        $this->assertFalse($result['supported_category_exact_match']);
        $this->assertTrue($result['supported_category_parent_match']);
        $this->assertSame('620', $result['matched_supported_category_id']);
        $this->assertSame('Części samochodowe', $result['matched_supported_category_name']);
        $this->assertSame('ID', $result['matched_supported_category_input_type']);
        $this->assertSame('CAR', $result['matched_supported_category_items_type']);
        $this->assertSame('Części samochodowe > Silniki kompletne', $result['current_category_path']);
        $this->assertSame([['id' => '620', 'name' => 'Części samochodowe']], $result['current_category_ancestors']);
        $this->assertSame('Volkswagen Tiguan 2018 2.0 TDI CUAA', $result['compatible_products_phrase']);
        $this->assertSame(1, $result['compatible_products_candidates_count']);
        $this->assertTrue($result['would_attach_manual_id_compatibility']);
        $this->assertSame('no_allegro_catalog_product_candidate_found', $result['product_catalog_blocked_reason']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_starts_with($request->url(), 'https://api.allegro.pl/sale/compatible-products') && $request['type'] === 'CAR');
    }
}
