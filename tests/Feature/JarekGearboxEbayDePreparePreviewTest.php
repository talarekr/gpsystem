<?php

namespace Tests\Feature;

use App\Models\JarekGearbox;
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

    private function mockTranslations(): void
    {
        $this->mock(GoogleTranslateService::class, function ($mock): void {
            $mock->shouldReceive('translate')->twice()->andReturnUsing(fn (string $text): array => [
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
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => $ebayCategoryId, 'external_category_name' => 'Getriebe']);
    }
}
