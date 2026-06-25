<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource;
use App\Filament\Resources\PartResource\Pages\EditPart;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\PartCategorySuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PartCategoryTitleSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_window_lifter_title_auto_selects_category_from_similar_parts_without_phrase_dictionary(): void
    {
        $lifter = $this->category(9, 'Mechanizm / podnośnik szyby');
        $this->part('VW Passat elektryczny podnosnik szyby drzwi lewy', $lifter->id);
        $this->part('Skoda Octavia mechanizm podnosnik szyby przednich drzwi', $lifter->id);
        $this->part('BMW podnosnik szyby elektryczny drzwi prawy', $lifter->id);
        $this->part('Audi A4 B7 lusterko elektryczne drzwi 8E0837462C', $this->category(8, 'Lusterka')->id);

        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('Audi A4 S4 B7 8E 8H Elektryczny podnośnik szyby drzwi 8E0837462C');

        $this->assertTrue($result['auto_select']);
        $this->assertSame($lifter->id, $result['selected_category_id']);
        $this->assertContains('audi', $result['diagnostics']['noise_tokens_removed']);
        $this->assertContains('b7', $result['diagnostics']['noise_tokens_removed']);
        $this->assertStringContainsString('podnosnik szyby', implode(' | ', $result['diagnostics']['candidate_terms']));
        $this->assertNotEmpty($result['diagnostics']['matched_parts']);
        $this->assertNotEmpty($result['diagnostics']['matched_categories']);
        $this->assertIsInt($result['diagnostics']['confidence']);
    }

    public function test_dpf_title_auto_selects_dpf_category_from_similar_parts(): void
    {
        $dpf = $this->category(10, 'DPF / katalizator / filtr cząstek stałych');
        $this->part('BMW DPF filtr cząstek stałych sprawny', $dpf->id);
        $this->part('AUDI kompletny DPF katalizator filtr czastek stalych', $dpf->id);
        $this->part('VW Tiguan DPF filtr czastek stalych 03N131656G', $dpf->id);

        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('VOLKSWAGEN Tiguan 2018 2.0 KOMPLETNY DPF SPRAWNY 03N131656G');

        $this->assertTrue($result['auto_select']);
        $this->assertSame($dpf->id, $result['selected_category_id']);
    }

    public function test_egr_cooler_title_suggests_egr_cooler_category_from_similar_parts(): void
    {
        $egr = $this->category(20, 'Chłodnica spalin EGR');
        $this->part('AUDI A4 chłodnica spalin EGR 04L131512A', $egr->id);
        $this->part('VW chłodnica EGR zawór egr komplet', $egr->id);

        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('AUDI A4 S4 B8 8K 2014 1.8 CHŁODNICA ZAWÓR EGR NOWA 04L131512A');

        $this->assertSame($egr->id, $result['suggestions'][0]['category_id']);
        $this->assertSame('Chłodnica spalin EGR', $result['suggestions'][0]['category_name']);
    }

    public function test_oem_model_and_brand_match_without_part_name_does_not_auto_select_wrong_category(): void
    {
        $mirror = $this->category(21, 'Lusterka');
        $this->part('Audi A4 B7 lusterko zewnętrzne lewe 8E0837462C', $mirror->id);
        $this->part('Audi A4 B7 lusterko prawe kompletne', $mirror->id);

        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('Audi A4 S4 B7 8E 8H Elektryczny podnośnik szyby drzwi 8E0837462C');

        $this->assertFalse($result['auto_select']);
        $this->assertNull($result['selected_category_id']);
    }

    public function test_uncertain_match_returns_three_suggestions_without_auto_select(): void
    {
        foreach ([31 => 'Chłodnica wody', 32 => 'Zawór EGR', 33 => 'Przewód EGR'] as $id => $name) {
            $category = $this->category($id, $name);
            $this->part($name.' AUDI A4 EGR chlodnica zawor', $category->id);
        }

        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('AUDI A4 CHŁODNICA ZAWÓR EGR', limit: 3);

        $this->assertFalse($result['auto_select']);
        $this->assertCount(3, $result['suggestions']);
    }

    public function test_category_picker_renders_suggestion_counter_data_and_proposed_section(): void
    {
        $category = $this->category(40, 'Chłodnica spalin EGR');
        $html = view('filament.forms.category-picker', [
            'categories' => PartResource::categoryPickerCategories(),
            'suggestions' => [[
                'category_id' => $category->id,
                'category_name' => $category->name,
                'category_path' => $category->name,
                'score' => 14,
                'matched_terms' => ['chlodnica egr'],
                'matched_parts_count' => 3,
            ]],
        ])->render();

        $this->assertStringContainsString('Proponowane', $html);
        $this->assertStringContainsString('selectSuggestion', $html);
    }

    public function test_clicking_suggestion_sets_category_and_reads_marketplace_mappings(): void
    {
        $part = Part::query()->create(['name' => 'Robocza część']);
        $category = $this->category(50, 'Alternator');
        DB::table('marketplace_category_mappings')->insert([
            ['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => 'ALG-1', 'created_at' => now(), 'updated_at' => now()],
            ['local_category_id' => $category->id, 'channel' => 'ovoko', 'external_category_id' => 'OV-1', 'created_at' => now(), 'updated_at' => now()],
            ['local_category_id' => $category->id, 'channel' => 'ebay_de', 'external_category_id' => 'EB-1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->call('selectSuggestedPartCategory', $category->id)
            ->assertSet('data.category_id', $category->id)
            ->assertSet('data.marketplace_category_mappings_state.allegro.external_category_id', 'ALG-1')
            ->assertSet('data.marketplace_category_mappings_state.ovoko.external_category_id', 'OV-1')
            ->assertSet('data.marketplace_category_mappings_state.ebay.external_category_id', 'EB-1');
    }

    public function test_fallback_without_similar_parts_does_not_select_or_error(): void
    {
        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('UNIKALNA CZĘŚĆ BEZ DOPASOWAŃ');

        $this->assertFalse($result['auto_select']);
        $this->assertNull($result['selected_category_id']);
        $this->assertSame([], $result['suggestions']);
    }

    public function test_suggestion_lookup_does_not_change_mappings_offers_products_prices_stock_or_images(): void
    {
        $category = $this->category(60, 'Alternator');
        $part = $this->part('Audi alternator ładowania silnika', $category->id);
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => $category->id, 'channel' => 'allegro_main', 'external_category_id' => 'ALG-OLD', 'created_at' => now(), 'updated_at' => now()]);
        $beforeParts = DB::table('parts')->get()->map(fn ($row) => (array) $row)->all();
        $beforeMappings = DB::table('marketplace_category_mappings')->get()->map(fn ($row) => (array) $row)->all();

        app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('BMW alternator ładowania silnika');

        $this->assertSame($beforeParts, DB::table('parts')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($beforeMappings, DB::table('marketplace_category_mappings')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseHas('parts', ['id' => $part->id, 'price' => '1.00', 'quantity' => 1]);
    }


    public function test_radiator_hose_compound_title_suggests_category_from_two_token_phrases(): void
    {
        $hose = $this->category(70, 'Przewód / Wąż chłodnicy');
        $this->part('Wąż chłodnicy', $hose->id);
        $this->part('Przewód chłodnicy', $hose->id);

        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('wąż przewód chłodnicy');

        $this->assertSame($hose->id, $result['suggestions'][0]['category_id']);
        $this->assertContains('waz przewod chlodnicy', $result['diagnostics']['search_phrases']);
        $this->assertContains('waz chlodnicy', $result['diagnostics']['search_phrases']);
        $this->assertContains('przewod chlodnicy', $result['diagnostics']['search_phrases']);
    }

    public function test_full_vehicle_oem_hose_title_filters_noise_and_suggests_radiator_hose(): void
    {
        $hose = $this->category(71, 'Przewód / Wąż chłodnicy');
        $this->part('Wąż chłodnicy', $hose->id);
        $this->part('Przewód chłodnicy', $hose->id);

        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('AUDI A4 B6 2.0alt WĄŻ PRZEWÓD CHŁODNICY 8E0121049');

        $this->assertSame($hose->id, $result['suggestions'][0]['category_id']);
        $this->assertContains('waz przewod chlodnicy', $result['diagnostics']['search_phrases']);
        $this->assertContains('waz chlodnicy', $result['diagnostics']['search_phrases']);
        $this->assertContains('przewod chlodnicy', $result['diagnostics']['search_phrases']);
        $this->assertContains('8e0121049', $result['diagnostics']['noise_tokens_removed']);
    }

    public function test_debug_part_category_suggestion_endpoint_is_read_only_and_returns_diagnostics(): void
    {
        $hose = $this->category(72, 'Przewód / Wąż chłodnicy');
        $this->part('Wąż chłodnicy', $hose->id);
        $this->part('Przewód chłodnicy', $hose->id);
        $beforeParts = DB::table('parts')->get()->map(fn ($row) => (array) $row)->all();
        $beforeMappings = DB::table('marketplace_category_mappings')->get()->map(fn ($row) => (array) $row)->all();

        $response = $this->getJson('/tools/debug-part-category-suggestion?token=gps_images_import_2026&title='.urlencode('wąż przewód chłodnicy').'&include_rejected=1&limit=50');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('parts_changed', false)
            ->assertJsonPath('mappings_changed', false)
            ->assertJsonFragment(['waz chlodnicy'])
            ->assertJsonFragment(['why_included_or_rejected' => 'Uwzględniono: dopasowana mocna fraza rzeczowa waz chlodnicy.']);
        $this->assertNotEmpty($response->json('matched_parts') ?: $response->json('rejected_parts'));
        $this->assertSame($beforeParts, DB::table('parts')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($beforeMappings, DB::table('marketplace_category_mappings')->get()->map(fn ($row) => (array) $row)->all());
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
