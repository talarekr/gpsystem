<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_q_search_matches_vehicle_make_case_insensitively(): void
    {
        $audi = Car::query()->create(['make' => 'Audi', 'model' => 'A6', 'model_variant' => 'C7', 'engine_code' => 'CDUC']);
        $bmw = Car::query()->create(['make' => 'BMW', 'model' => '3']);

        Part::query()->create(['name' => 'Pompa paliwa quattro', 'car_id' => $audi->id, 'quantity' => 1, 'status' => 'published']);
        Part::query()->create(['name' => 'Lampa BMW', 'car_id' => $bmw->id, 'quantity' => 1, 'status' => 'published']);

        $response = $this->get('/czesci?q=AUDi');

        $response->assertOk();
        $response->assertSee('Pompa paliwa quattro');
        $response->assertDontSee('Lampa BMW');
    }

    public function test_catalog_q_search_matches_vehicle_make_and_model_tokens(): void
    {
        $a4 = Car::query()->create(['make' => 'Audi', 'model' => 'A4']);
        $a6 = Car::query()->create(['make' => 'Audi', 'model' => 'A6']);
        $bmw = Car::query()->create(['make' => 'BMW', 'model' => '4']);

        Part::query()->create(['name' => 'Lampa przednia', 'car_id' => $a4->id, 'quantity' => 1, 'status' => 'published']);
        Part::query()->create(['name' => 'Lampa tylna', 'car_id' => $a6->id, 'quantity' => 1, 'status' => 'published']);
        Part::query()->create(['name' => 'Lampa BMW', 'car_id' => $bmw->id, 'quantity' => 1, 'status' => 'published']);

        $response = $this->get('/czesci?q=audi+a4');

        $response->assertOk();
        $response->assertSee('Lampa przednia');
        $response->assertDontSee('Lampa tylna');
        $response->assertDontSee('Lampa BMW');
    }

    public function test_catalog_q_search_matches_part_number_fields(): void
    {
        Part::query()->create(['name' => 'Pasujący moduł', 'part_number' => '6864719', 'quantity' => 1, 'status' => 'published']);
        Part::query()->create(['name' => 'Inny moduł', 'part_number' => 'ABC999', 'quantity' => 1, 'status' => 'published']);

        $response = $this->get('/czesci?q=6864719');

        $response->assertOk();
        $response->assertSee('Pasujący moduł');
        $response->assertDontSee('Inny moduł');
    }

    public function test_catalog_without_q_keeps_standard_listing(): void
    {
        Part::query()->create(['name' => 'Pierwsza widoczna część', 'quantity' => 1, 'status' => 'published']);
        Part::query()->create(['name' => 'Druga widoczna część', 'quantity' => 1, 'status' => 'published']);

        $response = $this->get('/czesci');

        $response->assertOk();
        $response->assertSee('Pierwsza widoczna część');
        $response->assertSee('Druga widoczna część');
    }

    public function test_catalog_q_search_matches_legacy_payload_and_vehicle_snapshot(): void
    {
        Part::query()->create([
            'name' => 'Sterownik silnika',
            'quantity' => 1,
            'status' => 'published',
            'vehicle_snapshot' => ['make' => 'Mercedes', 'model' => 'C Klasa', 'engine_code' => 'OM651'],
            'legacy_payload' => ['attributes' => ['vehicle_model' => 'Audi A4', 'oem_number' => '8K0907063']],
        ]);

        Part::query()->create(['name' => 'Zacisk hamulcowy', 'quantity' => 1, 'status' => 'published']);

        $response = $this->get('/czesci?q=audi');

        $response->assertOk();
        $response->assertSee('Sterownik silnika');
        $response->assertDontSee('Zacisk hamulcowy');
    }

    public function test_catalog_part_number_search_uses_fast_indexable_number_columns(): void
    {
        Part::query()->create([
            'name' => 'Moduł świateł',
            'sku' => 'M156E-YY',
            'quantity' => 1,
            'status' => 'published',
            'legacy_payload' => ['woo_product' => ['sku' => 'XX-M156E-YY'], 'meta' => ['reference_number' => 'REF-123']],
        ]);

        Part::query()->create(['name' => 'Osłona silnika', 'part_number' => 'ABC999', 'quantity' => 1, 'status' => 'published']);
        Part::query()->create([
            'name' => 'Szeroki opis',
            'quantity' => 1,
            'status' => 'published',
            'legacy_payload' => ['woo_product' => ['sku' => 'XX-M156E-YY']],
        ]);

        $response = $this->get('/czesci?part_number=m156e');

        $response->assertOk();
        $response->assertSee('Moduł świateł');
        $response->assertDontSee('Osłona silnika');
        $response->assertDontSee('Szeroki opis');
    }

    public function test_legacy_shop_redirect_preserves_query_string(): void
    {
        $this->get('/sklep?q=audi&part_number=M156E')
            ->assertRedirect('/czesci?q=audi&part_number=M156E');
    }

    public function test_header_search_redirects_to_catalog_with_query(): void
    {
        $this->get('/szukaj?q=audi')
            ->assertRedirect('/czesci?q=audi');
    }
}
