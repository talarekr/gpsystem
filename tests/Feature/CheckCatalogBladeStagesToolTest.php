<?php

namespace Tests\Feature;

use Tests\TestCase;

class CheckCatalogBladeStagesToolTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];

        parent::tearDown();
    }

    public function test_ping_stage_returns_minimal_json(): void
    {
        $this->get('/tools/check-catalog-blade-stages?token=gps_images_import_2026&stage=ping')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'stage' => 'ping',
            ]);
    }

    public function test_f6c_stage_returns_diagnostic_index_without_rendering(): void
    {
        $this->get('/tools/check-catalog-blade-stages?token=gps_images_import_2026&stage=F6C')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stage', 'F6C')
            ->assertJsonPath('available_substages', ['F6C1', 'F6C2', 'F6C3', 'F6C4', 'F6C5']);
    }

    public function test_f6c2_uses_get_and_removes_sort_and_page(): void
    {
        $_GET = [
            'token' => 'gps_images_import_2026',
            'stage' => 'F6C2',
            'q' => 'pompa',
            'sort' => 'price_asc',
            'page' => '2',
        ];

        $this->get('/tools/check-catalog-blade-stages?token=gps_images_import_2026&stage=F6C2&q=pompa&sort=price_asc&page=2')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stage', 'F6C2')
            ->assertJsonPath('sortable_query.q', 'pompa')
            ->assertJsonMissingPath('sortable_query.sort')
            ->assertJsonMissingPath('sortable_query.page');
    }

    public function test_f6c4_and_f6c5_render_minimal_inline_blade(): void
    {
        $this->get('/tools/check-catalog-blade-stages?token=gps_images_import_2026&stage=F6C4')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('html_preview', '<span>1 wyników</span>');

        $_GET = [
            'token' => 'gps_images_import_2026',
            'stage' => 'F6C5',
            'sort' => 'name',
        ];

        $this->get('/tools/check-catalog-blade-stages?token=gps_images_import_2026&stage=F6C5&sort=name')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stage', 'F6C5')
            ->assertJsonFragment(['html_preview' => '<select name="sort"><option value="">Sortuj domyślnie</option><option value="price_asc" >Cena rosnąco</option><option value="price_desc" >Cena malejąco</option><option value="name" selected>Nazwa</option></select>']);
    }
}
