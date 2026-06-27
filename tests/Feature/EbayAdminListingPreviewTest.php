<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EbayAdminListingPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_ebay_admin_preview_renders_read_only_view_with_html_policies_and_no_marketplace_write(): void
    {
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);

        $category = PartCategory::query()->create(['name' => 'Alternator']);
        $part = Part::query()->create([
            'name' => 'Alternator testowy eBay',
            'description' => 'Opis części do szablonu eBay.',
            'category_id' => $category->id,
            'price' => 100,
            'ebay_price' => 2.5,
            'quantity' => 1,
            'condition_notes' => 'Używany, sprawdzony.',
        ]);

        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/ebay-preview.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        MarketplaceCategoryMapping::query()->create([
            'local_category_id' => $category->id,
            'local_category_name' => 'Alternator',
            'channel' => 'ebay_de',
            'external_category_id' => '123456',
            'shipping_group' => 'de_30_eur',
            'fulfillment_policy_id' => '259264150013',
        ]);

        MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de',
            'name' => 'eBay DE',
            'code' => 'ebay_de',
            'status' => 'active',
            'api_enabled' => true,
            'api_settings' => [
                'payment_policy_id' => '259264220013',
                'return_policy_id' => '259264151013',
            ],
        ]);

        $response = $this->get('/tools/ebay-listing-preview?token=gps_images_import_2026&part_id='.$part->id.'&channel=ebay_de')
            ->assertOk();

        $response->assertSee('To jest tylko podgląd. Nie wystawia oferty i nie wykonuje żadnego zapisu do marketplace.', false);
        $response->assertSee('will_make_marketplace_request=false', false);
        $response->assertSee('Alternator testowy eBay');
        $response->assertSee('ebay_de');
        $response->assertSee('Cena źródłowa PLN');
        $response->assertSee('0.58');
        $response->assertSee('shipping_policy_resolution');
        $response->assertSee('business_policies');
        $response->assertSee('259264150013');
        $response->assertSee('259264220013');
        $response->assertSee('259264151013');
        $response->assertSee('description_rendered_html', false);
        $response->assertSee('Schneller weltweiter Versand');
        $response->assertSee('/ebay-template/assets/icon-shipping.png', false);

        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id]);
    }

    public function test_ebay_preview_puts_translated_vehicle_data_in_specification_table_without_separate_vehicle_block(): void
    {
        Cache::put('nbp_table_a_eur_rate', ['rate' => 4.30, 'effective_date' => '2026-06-27', 'table_no' => '123/A/NBP/2026']);

        $part = Part::query()->create([
            'name' => 'Część Maserati',
            'description' => 'Opis',
            'part_number' => '06H903017J',
            'oem_number' => '06H903017J',
            'price' => 100,
            'quantity' => 1,
            'condition_notes' => 'Używany',
            'vehicle_snapshot' => [
                'make' => 'Maserati', 'model' => 'Levante', 'model_variant' => 'Levante', 'production_year' => 2016,
                'body_type' => 'SUV', 'fuel_type' => 'Benzyna', 'engine_capacity_cm3' => 2979, 'engine_code' => 'M156E',
                'color' => 'Szary', 'drivetrain' => 'AWD', 'steering_side' => 'Lewa strona', 'gearbox_type' => 'Automatyczny',
                'mileage_km' => 70000, 'gearbox_code' => '', 'purchase_price' => '99999.00', 'includes_vat' => true,
                'status' => 'kupiony', 'purchase_date' => '2026-01-01', 'dismantled_at' => '2026-02-01',
            ],
        ]);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/photos/vehicle-preview.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        $de = $this->get('/tools/ebay-listing-preview?token=gps_images_import_2026&part_id='.$part->id.'&channel=ebay_de')->assertOk();
        $de->assertDontSee('Fahrzeugdaten');
        $de->assertDontSee('Maserati Levante Levante (2016)');
        $de->assertDontSee('2016 · SUV · Benzin · 2979 cm³ · Grau · AWD · Linkslenker · Automatik');
        $de->assertSee('Spezifikationen');
        foreach (['Teilenummer', '06H903017J', 'Hersteller / Marke', 'Maserati', 'Fahrzeugmodell', 'Levante', 'Modellvariante', 'Baujahr', '2016', 'Kraftstoffart', 'Benzin', 'Hubraum', '2979 cm³', 'Farbe', 'Grau', 'Lenkradseite', 'Linkslenker', 'Getriebeart', 'Automatik', 'Antrieb', 'AWD', 'Karosserietyp', 'SUV', 'Motorcode', 'M156E', 'Kilometerstand', '70000 km', 'Zustand / Qualität', 'Gebraucht'] as $expected) {
            $de->assertSee($expected, false);
        }
        foreach (['Getriebecode', '99999.00', 'includes_vat', 'kupiony', '2026-01-01', '2026-02-01'] as $forbidden) {
            $de->assertDontSee($forbidden, false);
        }
        $de->assertSee('vehicle_source');
        $de->assertSee('vehicle_snapshot');
        $de->assertSee('specification_rows_count');
        $de->assertSee('Tłumaczenia nieprzygotowane — użyj przycisku Przygotuj.', false);
        $de->assertSee('will_make_marketplace_request=false', false);
        $de->assertSee('Artikelmerkmale / Item specifics');
        foreach (['item_specifics', 'Artikelzustand', 'Gebraucht', 'Manufacturer Part Number', 'MPN', '06H903017J', 'Hersteller', 'Maserati', 'Modell', 'Kraftstoffart', 'Benzin', 'Hubraum', '2979 cm³', 'Motorcode', 'M156E', 'Antrieb', 'AWD', 'Getriebeart', 'Automatik', 'Lenkradposition', 'Linkslenker', 'Farbe', 'Grau', 'Laufleistung', '70000 km', 'OE/OEM Referenznummer', 'item_specifics_present', 'item_specifics_count'] as $expected) {
            $de->assertSee($expected, false);
        }

        $fr = $this->get('/tools/ebay-listing-preview?token=gps_images_import_2026&part_id='.$part->id.'&channel=ebay_fr')->assertOk();
        $fr->assertDontSee('Données du véhicule');
        $fr->assertSee('Informations détaillées');
        foreach (['item_specifics', 'État de l’objet', 'Occasion', 'Numéro de pièce', '06H903017J', 'Marque', 'Maserati', 'Modèle du véhicule', 'Levante', 'Année de production', '2016', 'Essence', '2979 cm³', 'Gris', 'Volant à gauche', 'Automatique', 'AWD', 'SUV', 'M156E', '70000 km', 'Occasion'] as $expected) {
            $fr->assertSee($expected, false);
        }

        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->id]);
    }

}
