<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroDescriptionBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_generates_one_section_with_text_and_image(): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->readyPart();

        $response = $this->getJson('/tools/dry-run-marketplace-listing-payload?token=gps_images_import_2026&channel=allegro_main&part_id='.$part->id);

        $response->assertOk();
        $description = $response->json('payload.description');
        $this->assertCount(1, $description['sections']);
        $this->assertCount(2, $description['sections'][0]['items']);
        $this->assertSame('TEXT', $description['sections'][0]['items'][0]['type']);
        $this->assertSame('IMAGE', $description['sections'][0]['items'][1]['type']);
    }

    public function test_car_parameters_are_ul_li_and_values_are_bold(): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->readyPart();

        $content = $this->getJson('/tools/dry-run-marketplace-listing-payload?token=gps_images_import_2026&channel=allegro_main&part_id='.$part->id)
            ->json('payload.description.sections.0.items.0.content');

        $this->assertStringContainsString('<ul><li>Marka: <b>AUDI</b></li><li>Model: <b>A4</b></li><li>Rok: <b>2018</b></li><li>Oznaczenie silnika: <b>CJSA</b></li><li>Moc silnika: <b>110</b></li></ul>', $content);
        $this->assertStringContainsString('<p><b>CZĘŚĆ SPRAWNA. STAN WIDOCZNY NA ZDJĘCIACH</b></p>', $content);
    }

    /** @dataProvider blockerCases */
    public function test_allegro_description_readiness_blockers(string $mutation, string $expectedBlocker): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->readyPart();

        match ($mutation) {
            'description' => $part->forceFill(['description' => null])->save(),
            'car' => $part->forceFill(['car_id' => null])->save(),
            'engine_code' => $part->car->forceFill(['engine_code' => null])->save(),
            'image' => $part->images()->delete(),
        };
        $part->refresh();

        $response = $this->getJson('/tools/dry-run-marketplace-listing-payload?token=gps_images_import_2026&channel=allegro_main&part_id='.$part->id);

        $response->assertOk();
        $this->assertContains($expectedBlocker, $response->json('blockers'));
    }

    public static function blockerCases(): array
    {
        return [
            ['description', 'missing_part_description'],
            ['car', 'missing_donor_vehicle'],
            ['engine_code', 'missing_donor_vehicle_field:Oznaczenie silnika'],
            ['image', 'missing_main_image'],
        ];
    }

    public function test_main_image_is_image_item_and_present_in_offer_images(): void
    {
        Http::fake($this->fakeAllegro());
        $part = $this->readyPart();

        $payload = $this->getJson('/tools/dry-run-marketplace-listing-payload?token=gps_images_import_2026&channel=allegro_main&part_id='.$part->id)->json('payload');
        $imageUrl = data_get($payload, 'description.sections.0.items.1.url');

        $this->assertSame('https://gpswiss.pl/storage/parts/main.jpg', $imageUrl);
        $this->assertContains($imageUrl, $payload['images']);
    }

    private function readyPart(): Part
    {
        MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'token']]);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => 77, 'channel' => 'allegro_main', 'external_category_id' => '123']);
        $car = Car::query()->create(['make' => 'AUDI', 'model' => 'A4', 'production_year' => 2018, 'engine_code' => 'CJSA', 'engine_power_kw' => 110]);
        $part = Part::query()->create(['name' => 'Sterownik silnika', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Sterownik silnika 1.8 TFSI', 'car_id' => $car->id, 'is_visible_storefront' => true, 'allegro_shipping_rate_name' => 'KURIER DPD']);
        PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/main.jpg', 'is_primary' => true, 'sort_order' => 1]);

        return $part;
    }

    private function fakeAllegro(): array
    {
        return [
            'https://api.allegro.pl/sale/categories/123/parameters' => Http::response(['parameters' => []], 200),
            'https://api.allegro.pl/sale/shipping-rates' => Http::response(['shippingRates' => [['id' => 'ship-dpd', 'name' => 'KURIER DPD', 'status' => 'ACTIVE']]], 200),
            'https://api.allegro.pl/after-sales-service-conditions/return-policies' => Http::response(['returnPolicies' => [['id' => 'ret-1', 'name' => 'ZWROTGOLD', 'status' => 'ACTIVE']]], 200),
            'https://api.allegro.pl/after-sales-service-conditions/implied-warranties' => Http::response(['impliedWarranties' => [['id' => 'imp-1', 'name' => 'GWARANCJA GOLD', 'status' => 'ACTIVE']]], 200),
            'https://api.allegro.pl/after-sales-service-conditions/warranties' => Http::response(['warranties' => [['id' => 'war-1', 'name' => 'GWARANTGOLD', 'status' => 'ACTIVE']]], 200),
        ];
    }
}
