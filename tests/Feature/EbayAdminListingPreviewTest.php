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
}
