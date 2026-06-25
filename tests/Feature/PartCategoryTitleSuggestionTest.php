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

    public function test_dpf_title_auto_selects_dpf_category_from_similar_parts(): void
    {
        $dpf = $this->category(10, 'DPF / katalizator / filtr cząstek stałych');
        $this->part('BMW DPF filtr cząstek stałych sprawny', $dpf->id);
        $this->part('AUDI kompletny DPF katalizator filtr czastek stalych', $dpf->id);
        $this->part('VW Tiguan DPF 03N131656G', $dpf->id);

        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('VOLKSWAGEN Tiguan 2018 2.0 KOMPLETNY DPF SPRAWNY 03N131656G');

        $this->assertTrue($result['auto_select']);
        $this->assertSame($dpf->id, $result['selected_category_id']);
    }

    public function test_egr_cooler_title_suggests_egr_cooler_category(): void
    {
        $egr = $this->category(20, 'Chłodnica spalin EGR');
        $this->part('AUDI A4 chłodnica spalin EGR 04L131512A', $egr->id);
        $this->part('VW chłodnica EGR zawór egr komplet', $egr->id);

        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle('AUDI A4 S4 B8 8K 2014 1.8 CHŁODNICA ZAWÓR EGR NOWA 04L131512A');

        $this->assertSame($egr->id, $result['suggestions'][0]['category_id']);
        $this->assertSame('Chłodnica spalin EGR', $result['suggestions'][0]['category_name']);
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

    private function category(int $id, string $name): PartCategory
    {
        return PartCategory::query()->create(['id' => $id, 'name' => $name, 'category_path' => $name]);
    }

    private function part(string $name, int $categoryId): Part
    {
        return Part::query()->create(['name' => $name, 'category_id' => $categoryId, 'price' => 1, 'quantity' => 1]);
    }
}
