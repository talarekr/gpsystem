<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\JarekFrProduct;
use App\Models\JarekGearbox;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use App\Services\Storefront\FrenchPlnPricing;
use App\Services\Storefront\FrenchPriceAudit;
use App\Services\Storefront\FrenchPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FrenchEbayDePriceAuditTest extends TestCase
{
    use RefreshDatabase;

    private PartCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['frontend-maintenance.enabled' => false]);
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A', 'rate' => 4, 'effective_date' => today()->toDateString()], 3600);
        $this->category = PartCategory::create(['name' => 'Moteurs', 'slug' => 'de-price-category', 'full_slug_path' => 'de-price-category', 'is_visible' => true]);
        MarketplaceCategoryMapping::create(['local_category_id' => $this->category->id, 'channel' => 'ebay_fr', 'shipping_group' => 'fr_55_eur', 'fulfillment_policy_id' => '260547694013']);
    }

    public function test_source_pln_margin_wins_over_ebay_on_views_sort_filters_and_pl_is_unchanged(): void
    {
        $part = $this->part('05L131705P', 'PLN', 2200, 'vw-t-roc-2-0tdi-katalizator-dpf-filtr-czastek-stalych-05l131705p-05l131601f');
        $part->update(['part_number' => '05L131705P']);
        $this->listing($part, 900, ['marketplace' => 'ebay_fr']);
        $de = $this->listing($part, 2200);
        $before = $part->fresh()->getRawOriginal();
        foreach (['/czesci', '/czesci?sort=price_asc&price_min=687&price_max=688', '/produkt/'.$part->slug, '/kategoria-produktu/'.$this->category->slug] as $path) {
            $this->get('https://gpswiss.fr'.$path)->assertOk()->assertSee('687,50 EUR')->assertDontSee('2 200,00 EUR')->assertDontSee('PLN');
        }
        $this->get('https://gpswiss.fr/czesci?price_min=2000')->assertOk()->assertDontSee('05L131705P');
        $price = app(FrenchPriceResolver::class)->resolve($part);
        $this->assertSame(68750, $price['cents']);
        $this->assertSame(2750.0, $price['price_pln_after_margin']);
        $this->get('https://gpswiss.pl/produkt/'.$part->slug)->assertOk()->assertSee('2 200,00 PLN');
        $this->assertSame($before, $part->fresh()->getRawOriginal());
        $this->assertSame('2200.00', $de->fresh()->price);
        Http::assertNothingSent();
    }

    public function test_conflicting_marketplace_prices_cannot_block_or_override_valid_source_pln(): void
    {
        $part = $this->part('Conflict', 'PLN', 400);
        $this->listing($part, 200);
        $this->listing($part, 300);
        $this->assertSame(12500, app(FrenchPriceResolver::class)->resolve($part)['cents']);
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee('125,00 EUR');
        $row = app(FrenchPriceAudit::class)->rows()->current();
        $this->assertContains('ambiguous_local_ebay_de_prices', $row['warnings']);
        $this->assertSame(125, $row['final_fr_price_eur']);
        Http::assertNothingSent();
    }

    public function test_non_pln_source_never_uses_marketplace_eur_or_guesses_currency(): void
    {
        $part = $this->part('EUR source', 'EUR', 100);
        $this->listing($part, 200);
        $price = app(FrenchPriceResolver::class)->resolve($part);
        $this->assertNull($price['cents']);
        $this->assertSame('non_pln_price', $price['reason']);
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee('Prix indisponible');
        $this->get('https://gpswiss.pl/produkt/'.$part->slug)->assertOk()->assertSee('100,00 EUR');
        Http::assertNothingSent();
    }

    public function test_report_compares_all_visible_parts_and_both_reported_slugs_with_no_writes_or_api(): void
    {
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A', 'rate' => 4, 'effective_date' => today()->toDateString()], 3600);
        foreach (FrenchPriceAudit::EXAMPLES as $slug => $cents) {
            $part = $this->part($slug, 'PLN', 4000, $slug);
            $this->listing($part, $cents / 100, ['raw_payload' => ['pricingSummary' => ['price' => ['value' => (string) ($cents / 100), 'currency' => 'EUR']], 'unrelated' => 'do-not-print']]);
        }
        $third = $this->part('Missing', 'EUR', 100);
        $this->listing($third, 300, ['marketplace' => 'ebay_fr']);
        $this->part('Hidden', 'EUR', 100)->update(['status' => 'draft']);
        $before = DB::table('parts')->orderBy('id')->get()->toJson();
        $listingBefore = DB::table('marketplace_listings')->orderBy('id')->get()->toJson();
        $report = app(FrenchPriceAudit::class)->report();
        $this->assertSame(4, $report['summary']['total_checked']);
        $this->assertCount(3, $report['examples']);
        $this->assertSame(1250, $report['examples'][0]['final_fr_price_eur']);
        $this->assertSame(1250, $report['examples'][1]['final_fr_price_eur']);
        $this->assertNotNull($report['examples'][0]['part_id']);
        $this->assertStringNotContainsString('do-not-print', json_encode($report));
        $this->assertSame($before, DB::table('parts')->orderBy('id')->get()->toJson());
        $this->assertSame($listingBefore, DB::table('marketplace_listings')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_report_missing_cache_never_fetches_nbp_and_does_not_fabricate_product_ids(): void
    {
        Cache::forget('nbp_table_a_rate:EUR:latest');
        $part = $this->part('Needs cache', 'PLN', 100);
        $this->listing($part, 2200);
        $report = app(FrenchPriceAudit::class)->report();
        $this->assertFalse($report['nbp_cache']['ok']);
        $this->assertSame(1, $report['summary']['no_visible_fr_price_count']);
        $this->assertSame('part_not_found', $report['examples'][0]['reason_code']);
        $this->assertNull($report['examples'][0]['part_id']);
        Http::assertNothingSent();
    }

    public function test_stripe_uses_the_same_marked_up_pln_price_with_fr_shipping_and_no_source_price_or_stock_write(): void
    {
        config(['stripe-fr.enabled' => true, 'stripe-fr.mode' => 'live', 'stripe-fr.secret_key' => 'sk_live_fixture', 'stripe-fr.publishable_key' => 'pk_live_fixture', 'stripe-fr.webhook_secret' => 'whsec_fixture']);
        $part = $this->part('Stripe PLN', 'PLN', 2200);
        $this->listing($part, 1476.35);
        $this->withSession(['storefront' => ['fr' => ['address' => ['customer_type' => 'private', 'billing_email' => 'buyer@example.test', 'billing_first_name' => 'Jean', 'billing_last_name' => 'Test', 'billing_phone' => '+33123456789',
            'billing_address_line1' => '7 rue Exemple', 'billing_postal_code' => '75008', 'billing_city' => 'Paris', 'billing_country' => 'FR',
            'shipping_same_as_billing' => true, 'shipping_country' => 'FR']]]]);
        Http::fake(['api.stripe.com/*' => fn ($r) => Http::response(['id' => 'cs_live_de_fixture', 'object' => 'checkout.session', 'mode' => 'payment', 'livemode' => true,
            'currency' => 'eur', 'amount_total' => 74250, 'url' => 'https://checkout.stripe.com/c/pay/de-fixture', 'client_reference_id' => $r['client_reference_id'], 'metadata' => $r['metadata']])]);
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee('687,50 EUR');
        $this->post('https://gpswiss.fr/koszyk/dodaj/'.$part->id, ['quantity' => 1])->assertRedirect();
        $key = $this->get('https://gpswiss.fr/commande')->assertOk()->viewData('draft')['checkout_key'];
        $this->post('https://gpswiss.fr/commande', ['checkout_key' => $key, 'terms' => 1])->assertSessionHasNoErrors()->assertRedirect('https://checkout.stripe.com/c/pay/de-fixture');
        Http::assertSent(fn ($r) => $r['line_items'][0]['price_data']['unit_amount'] === 68750 && $r['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] === 5500);
        $this->assertSame('742.50', Order::sole()->total);
        $this->assertSame('2200.00', $part->fresh()->price);
        $this->assertSame(1, $part->fresh()->quantity);
        Http::assertSentCount(1);
    }

    public function test_audit_contains_jarek_effective_margin_and_warns_about_pln_labelled_eur_without_writes(): void
    {
        $part = $this->part('05L131705P', 'PLN', 2200);
        $this->listing($part, 2200);
        $source = JarekGearbox::create(['allegro_offer_id' => 'jarek-audit', 'title' => 'Box', 'price' => 3000, 'currency' => 'PLN', 'quantity' => 0]);
        JarekFrProduct::create(['jarek_gearbox_id' => $source->id, 'is_active' => true]);
        $before = $source->fresh()->getRawOriginal();
        $rows = iterator_to_array(app(FrenchPriceAudit::class)->rows(), false);
        $this->assertContains('possible_pln_amount_labelled_eur', $rows[0]['warnings']);
        $this->assertSame(687.5, $rows[0]['final_fr_price_eur']);
        $this->assertSame(2200, $rows[0]['ebay_de_price']);
        $this->assertSame('jarek', $rows[1]['source_type']);
        $this->assertSame(25, $rows[1]['applied_margin']);
        $this->assertSame([25], $rows[1]['applied_margin_steps']);
        $this->assertSame(3750.0, $rows[1]['price_pln_after_margin']);
        $this->assertSame(937.5, $rows[1]['final_fr_price_eur']);
        $this->assertNotEquals(1125, $rows[1]['final_fr_price_eur']);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_read_only_report_endpoints_are_authenticated_and_forbidden_on_fr(): void
    {
        $url = 'https://gpswiss.pl/admin/tools/storefront/fr-translations/price-audit';
        $this->get($url)->assertRedirect();
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $user = User::create(['name' => 'Owner', 'email' => 'de-owner@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        $this->part('CSV fixture', 'EUR', 100);
        $this->get($url.'?format=json')->assertOk()->assertJsonPath('summary.total_checked', 1);
        $csv = $this->get($url.'?format=csv')->assertOk();
        $this->assertStringContainsString('final_fr_price_eur', $csv->streamedContent());
        $this->get(str_replace('gpswiss.pl', 'gpswiss.fr', $url))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_sql_and_runtime_share_rounding_currency_and_range_guards_for_both_margins(): void
    {
        $rate = ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A', 'rate' => 4, 'effective_date' => today()->toDateString()];
        foreach ([FrenchPlnPricing::PART_MULTIPLIER, FrenchPlnPricing::JAREK_MULTIPLIER] as $multiplier) {
            foreach ([0, 0.01, 1.05, 2200, 3000, 999999999] as $amount) {
                foreach (['PLN', 'EUR'] as $currency) {
                    $expected = FrenchPlnPricing::convert($amount, $currency, $multiplier, $rate);
                    $sql = FrenchPlnPricing::sql('amount', 'currency', $multiplier, 4);
                    $row = DB::selectOne('SELECT '.$sql.' AS cents FROM (SELECT ? AS amount, ? AS currency) source', [$amount, $currency]);
                    $this->assertSame($expected['cents'], $row->cents === null ? null : (int) $row->cents);
                }
            }
        }
        foreach ([today()->subDays(8)->toDateString(), today()->addDay()->toDateString(), '2026-02-30'] as $date) {
            $price = FrenchPlnPricing::convert(3000, 'PLN', FrenchPlnPricing::JAREK_MULTIPLIER, array_replace($rate, ['effective_date' => $date]));
            $this->assertNull($price['cents']);
            $this->assertSame('no_nbp_rate', $price['reason']);
        }
        Http::assertNothingSent();
    }

    private function part(string $name, string $currency, float $price, ?string $slug = null): Part
    {
        return Part::create(['name' => $name, 'slug' => $slug, 'sku' => 'DE-'.Part::count(), 'currency' => $currency, 'price' => $price, 'quantity' => 1, 'status' => 'ready', 'category_id' => $this->category->id]);
    }

    private function listing(Part $part, float $price, array $overrides = []): MarketplaceListing
    {
        return MarketplaceListing::create($overrides + ['part_id' => $part->id, 'marketplace' => 'ebay_de', 'price' => $price, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'active', 'match_status' => 'matched', 'external_listing_id' => 'de-'.MarketplaceListing::count()]);
    }
}
