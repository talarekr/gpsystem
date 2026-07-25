<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryMappingExportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_every_shop_category_and_reports_mapping_gaps(): void
    {
        $this->withoutMiddleware();
        Storage::fake('public');

        DB::table('part_categories')->insert([
            ['id' => 1, 'name' => 'Silniki', 'category_path' => 'Części > Silniki', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => '=Niebezpieczna', 'category_path' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_category_mappings')->insert([
            $this->mapping(1, 'allegro_main', 'A1', 'Stara nazwa'),
            $this->mapping(1, 'ovoko', 'O1', 'Ovoko name'),
            $this->mapping(1, 'ebay_de', 'E1', 'eBay name'),
        ]);
        DB::table('marketplace_categories')->insert([
            'channel' => 'allegro_main', 'external_category_id' => 'A1', 'name' => 'Silniki Allegro',
            'full_path' => 'Motoryzacja > Silniki', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->getJson('/admin/tools/category-mapping-export')->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('total_shop_categories', 2)
            ->assertJsonPath('complete_mapping_count', 1)
            ->assertJsonPath('missing_allegro_count', 1)
            ->assertJsonPath('missing_ovoko_count', 1)
            ->assertJsonPath('missing_ebay_count', 1)
            ->assertJsonPath('missing_all_count', 1);

        $path = $response->json('file_relative_path');
        Storage::disk('public')->assertExists($path);
        $csv = Storage::disk('public')->get($path);
        $this->assertStringContainsString('allegro_channel', $csv);
        $this->assertStringContainsString('Silniki Allegro', $csv);
        $this->assertStringContainsString("'=Niebezpieczna", $csv);
        $this->assertStringContainsString('missing_all', $csv);
    }

    private function mapping(int $categoryId, string $channel, string $externalId, string $name): array
    {
        return [
            'local_category_id' => $categoryId, 'channel' => $channel, 'external_category_id' => $externalId,
            'external_category_name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
