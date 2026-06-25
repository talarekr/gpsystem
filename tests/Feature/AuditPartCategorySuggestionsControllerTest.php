<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditPartCategorySuggestionsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_correct_wrong_top3_filters_and_read_only(): void
    {
        $hose = $this->category(86, 'Przewód / Wąż chłodnicy');
        $ac = $this->category(87, 'Przewód klimatyzacji');
        $egr = $this->category(88, 'Chłodnica EGR');

        $this->part('Wąż chłodnicy', $hose->id);
        $this->part('Przewód chłodnicy', $hose->id);
        $this->part('Wąż przewód klimatyzacji', $ac->id);
        $this->part('Przewód klimatyzacji', $ac->id);
        $this->part('Chłodnica zawór EGR', $egr->id);
        $this->part('Zawór EGR chłodnica', $egr->id);
        $this->part('Wąż przewód klimatyzacji błędnie w chłodnicy', $hose->id);
        $this->part('Chłodnica EGR przewód chłodnicy', $egr->id);

        $beforeParts = DB::table('parts')->get()->map(fn ($row) => (array) $row)->all();

        $response = $this->getJson('/tools/audit-part-category-suggestions?token=gps_images_import_2026&scope=with_category&limit=50&sample_limit=50&include_debug=1');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('parts_changed', false)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('mappings_changed', false)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false);
        $this->assertGreaterThan(0, $response->json('top1_correct_count'));
        $this->assertGreaterThan(0, $response->json('top1_wrong_count'));
        $this->assertGreaterThanOrEqual(1, $response->json('top3_contains_expected_count'));
        $this->assertNotEmpty($response->json('per_category'));
        $this->assertNotEmpty($response->json('confused_pairs'));
        $this->assertSame($beforeParts, DB::table('parts')->get()->map(fn ($row) => (array) $row)->all());

        $mismatches = $this->getJson('/tools/audit-part-category-suggestions?token=gps_images_import_2026&scope=with_category&limit=50&sample_limit=50&only_mismatches=1');
        $mismatches->assertOk();
        $this->assertNotContains('correct', collect($mismatches->json('items'))->pluck('status')->all());

        $categoryOnly = $this->getJson('/tools/audit-part-category-suggestions?token=gps_images_import_2026&scope=with_category&category_id='.$hose->id.'&limit=50');
        $categoryOnly->assertOk();
        $this->assertEqualsCanonicalizing([$hose->id], collect($categoryOnly->json('items'))->pluck('expected_category_id')->unique()->values()->all());
    }

    public function test_debug_endpoint_regression_after_audit_changes(): void
    {
        $hose = $this->category(90, 'Przewód / Wąż chłodnicy');
        $this->part('AUDI A4 B6 B7 WĄŻ PRZEWÓD CHŁODNICY 8E0121049', $hose->id);
        $this->part('SEAT EXEO PRZEWÓD WĄŻ CHŁODNICY WODY 8E0121049', $hose->id);

        $this->getJson('/tools/debug-part-category-suggestion?token=gps_images_import_2026&title='.urlencode('wąż przewód chłodnicy').'&include_rejected=1&limit=50')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('suggestions.0.category_id', $hose->id)
            ->assertJsonMissing(['noise_tokens_removed' => ['chlodnicy']]);
    }

    private function category(int $id, string $name): PartCategory
    {
        return PartCategory::query()->create(['id' => $id, 'name' => $name, 'category_path' => $name]);
    }

    private function part(string $name, int $categoryId): Part
    {
        return Part::query()->create(['name' => $name, 'category_id' => $categoryId, 'price' => 1, 'quantity' => 1]);
    }
}
