<?php

namespace Tests\Feature;

use App\Models\JarekGearbox;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceSyncLog;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JarekGearboxEbayCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_uses_only_local_images_and_blocks_allegro_images(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);

        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/123/01.jpg', 'jpg');

        JarekGearbox::query()->create([
            'allegro_offer_id' => '123',
            'allegro_offer_url' => 'https://allegro.pl/oferta/123',
            'title' => 'Skrzynia DSG 0D9300041',
            'description' => 'Opis',
            'price' => 1000,
            'currency' => 'PLN',
            'quantity' => 1,
            'main_image_url' => 'https://a.allegroimg.com/original/photo-123.jpg',
            'images' => ['https://a.allegroimg.com/original/photo-123.jpg'],
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
            'category_path' => ['Motoryzacja', 'Części', 'Skrzynie biegów'],
        ]);
        JarekGearbox::query()->create([
            'allegro_offer_id' => '124',
            'title' => 'Skrzynia z Allegro zdjęciem',
            'price' => 1000,
            'currency' => 'PLN',
            'quantity' => 1,
            'main_image_url' => 'https://a.allegroimg.com/original/photo.jpg',
            'images' => ['https://a.allegroimg.com/original/photo.jpg'],
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-csv-preview?limit=10');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('parts_changed', false)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('exportable_count', 1)
            ->assertJsonPath('blocked_count', 1)
            ->assertJsonPath('warnings_by_reason.missing_local_images', 1)
            ->assertJsonPath('local_image_url_source_fields', ['storage/app/public/jarek-gearboxes'])
            ->assertJsonPath('localized_images_source', 'storage/app/public/jarek-gearboxes')
            ->assertJsonPath('csv_uses_only_our_server_images', true)
            ->assertJsonPath('sample_rows.0.Main image URL', 'https://gpswiss.pl/storage/jarek-gearboxes/123/01.jpg')
            ->assertJsonPath('sample_rows.0.diagnostics.images.localized_images_count', 1)
            ->assertJsonPath('sample_rows.0.diagnostics.images.csv_images_source', 'localized');
    }

    public function test_export_requires_confirm_writes_small_csv_and_logs_without_marketplace_or_parts_write(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);

        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/123/01.jpg', 'jpg');

        JarekGearbox::query()->create([
            'allegro_offer_id' => '123',
            'title' => 'Skrzynia DSG 0D9300041',
            'description' => 'Opis',
            'price' => 1000,
            'currency' => 'PLN',
            'quantity' => 1,
            'main_image_url' => 'https://a.allegroimg.com/original/photo-123.jpg',
            'images' => ['https://a.allegroimg.com/original/photo-123.jpg'],
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
        ]);

        $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-csv-export?limit=10')
            ->assertStatus(422)
            ->assertJsonPath('marketplace_write', false);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-csv-export?confirm=jarek-ebay-csv&limit=10');

        $response->assertOk()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('parts_changed', false)
            ->assertJsonPath('exported_count', 1);

        Storage::disk('local')->assertExists($response->json('csv_path'));
        $this->assertStringContainsString('JAREK-123', Storage::disk('local')->get($response->json('csv_path')));
        $this->assertSame(1, MarketplaceSyncLog::query()->where('action', 'jarek_gearboxes_ebay_csv_export')->count());
    }

    public function test_preview_normalizes_nested_csv_fields_without_server_error(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://gpswiss.pl']);

        $this->createCategoryMappings('620', '100684');
        Storage::disk('public')->put('jarek-gearboxes/125/01.jpg', 'jpg');
        Storage::disk('public')->put('jarek-gearboxes/125/02.jpg', 'jpg');

        JarekGearbox::query()->create([
            'allegro_offer_id' => '125',
            'title' => 'Skrzynia DSG 0D9300042',
            'description' => 'Opis',
            'price' => 1000,
            'currency' => 'PLN',
            'quantity' => 1,
            'main_image_url' => null,
            'images' => [
                ['url' => 'https://gpswiss.pl/storage/jarek/125/main.jpg'],
                ['image' => ['url' => 'https://gpswiss.pl/storage/jarek/125/2.jpg']],
                ['unexpected' => ['nested' => 'not-url']],
            ],
            'category_id' => '620',
            'category_name' => 'Skrzynie biegów',
            'category_path' => [
                ['id' => '3', 'name' => 'Motoryzacja'],
                ['id' => '620', 'name' => 'Części samochodowe'],
                ['id' => '621', 'name' => 'Układ napędowy'],
                ['id' => '622', 'name' => 'Skrzynie biegów'],
                ['id' => '623', 'name' => 'Kompletne skrzynie'],
            ],
            'parameters' => [['name' => 'Numer części', 'values' => ['0D9300042']]],
            'category_payload' => ['id' => '620', 'name' => 'Skrzynie biegów'],
            'raw_payload' => ['nested' => ['data' => true]],
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-csv-preview?limit=10');

        $response->assertOk()
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('parts_changed', false)
            ->assertJsonPath('sample_rows.0.Allegro category path', 'Motoryzacja > Części samochodowe > Układ napędowy > Skrzynie biegów > Kompletne skrzynie')
            ->assertJsonPath('sample_rows.0.Main image URL', 'https://gpswiss.pl/storage/jarek-gearboxes/125/01.jpg')
            ->assertJsonPath('sample_rows.0.Additional image URLs', 'https://gpswiss.pl/storage/jarek-gearboxes/125/02.jpg')
            ->assertJsonPath('warnings_by_reason.csv_field_normalized', 1)
            ->assertJsonPath('blocked_count', 0)
            ->assertJsonPath('sample_rows.0.Suggested eBay category', '100684')
            ->assertJsonPath('sample_rows.0.diagnostics.category.mapping_source', 'marketplace_category_mappings');
    }

    private function createCategoryMappings(string $allegroCategoryId, string $ebayCategoryId): void
    {
        $category = PartCategory::query()->create(['name' => 'Skrzynie biegów', 'category_path' => 'Motoryzacja > Części > Skrzynie biegów']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => $allegroCategoryId, 'external_category_name' => 'Skrzynie biegów']);
        MarketplaceCategoryMapping::query()->create(['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => $ebayCategoryId, 'external_category_name' => 'Getriebe']);
    }
}
