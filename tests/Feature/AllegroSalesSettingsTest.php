<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Services\Marketplace\AllegroSalesSettingsResolver;
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

    public function test_missing_return_policy_zwrotgold_blocks_publish_readiness(): void
    {
        Http::fake($this->fakeAllegro(returnPolicies: []));
        $part = $this->part('KURIER DPD');

        $result = app(MarketplaceListingReadinessService::class)->checkPartReadiness($part, 'allegro_main');

        $this->assertContains('allegro_returnPolicy_missing:ZWROTGOLD', $result['blockers']);
        $this->assertSame('missing', $result['prepared_payload_preview_safe']['allegro_sales_settings']['returnPolicy']['status']);
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
    }

    private function part(?string $shippingRateName): Part
    {
        MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'token']]);
        $part = Part::query()->create(['name' => 'Błotnik Audi', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Opis', 'vehicle_snapshot' => ['make' => 'Audi'], 'is_visible_storefront' => true, 'allegro_shipping_rate_name' => $shippingRateName]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => 77, 'channel' => 'allegro_main', 'external_category_id' => '123']);
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
