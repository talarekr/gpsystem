<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
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
        $this->category = PartCategory::create(['name' => 'Moteurs', 'slug' => 'de-price-category', 'full_slug_path' => 'de-price-category', 'is_visible' => true]);
        MarketplaceCategoryMapping::create(['local_category_id' => $this->category->id, 'channel' => 'ebay_fr', 'shipping_group' => 'fr_55_eur', 'fulfillment_policy_id' => '260547694013']);
    }

    public function test_de_price_wins_over_part_eur_and_fr_offer_on_public_views_and_sort_without_nbp(): void
    {
        $part = $this->part('DE fixture', 'EUR', 100);
        $this->listing($part, 900, ['marketplace' => 'ebay_fr']);
        $de = $this->listing($part, 1476.35);
        $before = $part->fresh()->getRawOriginal();
        foreach (['/czesci', '/czesci?sort=price_asc&price_min=1400&price_max=1500', '/produkt/'.$part->slug, '/kategoria-produktu/'.$this->category->slug] as $path) {
            $this->get('https://gpswiss.fr'.$path)->assertOk()->assertSee('1 476,35 EUR')->assertDontSee('900,00 EUR')->assertDontSee('PLN');
        }
        $this->assertSame(147635, app(FrenchPriceResolver::class)->resolve($part)['cents']);
        $this->assertStringContainsString('(ebay_de,EUR)', app(FrenchPriceResolver::class)->resolve($part)['source']);
        $this->get('https://gpswiss.pl/produkt/'.$part->slug)->assertOk()->assertSee('100,00 EUR');
        $this->assertSame($before, $part->fresh()->getRawOriginal());
        $this->assertSame('1476.35', $de->fresh()->price);
        Http::assertNothingSent();
    }

    public function test_generic_de_account_and_explicit_de_url_are_supported_but_fr_and_unknown_are_not(): void
    {
        $part = $this->part('Channels', 'EUR', 100);
        $account = MarketplaceAccount::create(['code' => 'ebay_de', 'name' => 'DE', 'marketplace' => 'ebay']);
        $listing = $this->listing($part, 200, ['marketplace' => 'ebay', 'marketplace_account_id' => $account->id]);
        $this->assertSame(20000, app(FrenchPriceResolver::class)->resolve($part)['cents']);
        $account->update(['code' => 'ebay_fr']);
        $this->assertSame(10000, app(FrenchPriceResolver::class)->resolve($part)['cents']);
        $listing->update(['marketplace_account_id' => null, 'url' => 'https://www.ebay.de/itm/1234']);
        $this->assertSame(20000, app(FrenchPriceResolver::class)->resolve($part)['cents']);
        $listing->update(['url' => 'https://evil.test/ebay.de/itm/1234']);
        $this->assertSame(10000, app(FrenchPriceResolver::class)->resolve($part)['cents']);
        $listing->update(['marketplace' => 'ebay_de', 'marketplace_account_id' => $account->id]);
        $this->assertSame(10000, app(FrenchPriceResolver::class)->resolve($part)['cents']);
        Http::assertNothingSent();
    }

    public function test_duplicate_equal_de_prices_are_unambiguous_but_conflicting_prices_block_purchase_and_filter(): void
    {
        $part = $this->part('Conflict', 'EUR', 100);
        $this->listing($part, 200);
        $second = $this->listing($part, 200);
        $this->assertSame(20000, app(FrenchPriceResolver::class)->resolve($part)['cents']);
        $second->update(['price' => 300]);
        $this->assertSame('ambiguous_ebay_listing', app(FrenchPriceResolver::class)->resolve($part)['reason']);
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee('Prix indisponible')->assertDontSee('class="sf-purchase__cart"', false);
        $this->get('https://gpswiss.fr/czesci?price_min=50')->assertOk()->assertDontSee('Conflict');
        Http::assertNothingSent();
    }

    public function test_inactive_stale_wrong_currency_or_unmatched_de_rows_do_not_supply_price(): void
    {
        $part = $this->part('Invalid offers', 'EUR', 100);
        foreach ([['status' => 'ended'], ['quantity' => 0], ['currency' => 'USD'], ['sync_status' => 'stale'],
            ['last_api_status' => 'ended'], ['not_seen_in_active_api_at' => now()], ['match_status' => 'unmatched']] as $overrides) {
            $listing = $this->listing($part, 200, $overrides);
            $this->assertSame(10000, app(FrenchPriceResolver::class)->resolve($part)['cents']);
            $listing->delete();
        }
        $this->listing($part, 321.99, ['status' => 'LIVE']);
        $this->assertSame(32199, app(FrenchPriceResolver::class)->resolve($part)['cents']);
        Http::assertNothingSent();
    }

    public function test_no_de_uses_part_eur_or_pln_nbp_at_the_end_never_ebay_fr(): void
    {
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A', 'rate' => 4, 'effective_date' => today()->toDateString()], 3600);
        $part = $this->part('Fallback', 'PLN', 400);
        $this->listing($part, 900, ['marketplace' => 'ebay_fr']);
        $price = app(FrenchPriceResolver::class)->resolve($part);
        $this->assertSame(10000, $price['cents']);
        $this->assertSame('parts.price(PLN)->NBP_TABLE_A(EUR)', $price['source']);
        $part->update(['currency' => 'EUR', 'price' => 123]);
        $this->assertSame(12300, app(FrenchPriceResolver::class)->resolve($part)['cents']);
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
        $this->assertSame(3, $report['summary']['total_checked']);
        $this->assertSame(2, $report['summary']['exact_match_count']);
        $this->assertSame(2, $report['summary']['previous_policy_mismatch_count']);
        $this->assertSame(1, $report['summary']['missing_ebay_de_count']);
        $this->assertCount(2, $report['examples']);
        $this->assertSame(1476.35, $report['examples'][0]['gpswiss_fr_display_price']);
        $this->assertSame(3487, $report['examples'][1]['gpswiss_fr_display_price']);
        $this->assertNotNull($report['examples'][0]['part_id']);
        $this->assertSame('fr_uses_nbp_fallback', $report['examples'][0]['previous_reason_code']);
        $this->assertStringNotContainsString('do-not-print', json_encode($report));
        $this->assertSame($before, DB::table('parts')->orderBy('id')->get()->toJson());
        $this->assertSame($listingBefore, DB::table('marketplace_listings')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_report_missing_cache_never_fetches_nbp_and_does_not_fabricate_product_ids(): void
    {
        $part = $this->part('Needs cache', 'PLN', 100);
        $report = app(FrenchPriceAudit::class)->report();
        $this->assertFalse($report['nbp_cache']['ok']);
        $this->assertSame(1, $report['summary']['no_visible_fr_price_count']);
        $this->assertSame('part_not_found', $report['examples'][0]['reason_code']);
        $this->assertNull($report['examples'][0]['part_id']);
        Http::assertNothingSent();
    }

    public function test_stripe_uses_the_same_de_price_with_fr_shipping_and_no_source_price_or_stock_write(): void
    {
        config(['stripe-fr.enabled' => true, 'stripe-fr.mode' => 'live', 'stripe-fr.secret_key' => 'sk_live_fixture', 'stripe-fr.publishable_key' => 'pk_live_fixture', 'stripe-fr.webhook_secret' => 'whsec_fixture']);
        $part = $this->part('Stripe DE', 'EUR', 100);
        $this->listing($part, 1476.35);
        $this->withSession(['storefront' => ['fr' => ['address' => ['customer_type' => 'private', 'billing_email' => 'buyer@example.test', 'billing_first_name' => 'Jean', 'billing_last_name' => 'Test', 'billing_phone' => '+33123456789',
            'billing_address_line1' => '7 rue Exemple', 'billing_postal_code' => '75008', 'billing_city' => 'Paris', 'billing_country' => 'FR',
            'shipping_same_as_billing' => true, 'shipping_country' => 'FR']]]]);
        Http::fake(['api.stripe.com/*' => fn ($r) => Http::response(['id' => 'cs_live_de_fixture', 'object' => 'checkout.session', 'mode' => 'payment', 'livemode' => true,
            'currency' => 'eur', 'amount_total' => 153135, 'url' => 'https://checkout.stripe.com/c/pay/de-fixture', 'client_reference_id' => $r['client_reference_id'], 'metadata' => $r['metadata']])]);
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee('1 476,35 EUR');
        $this->post('https://gpswiss.fr/koszyk/dodaj/'.$part->id, ['quantity' => 1])->assertRedirect();
        $key = $this->get('https://gpswiss.fr/commande')->assertOk()->viewData('draft')['checkout_key'];
        $this->post('https://gpswiss.fr/commande', ['checkout_key' => $key, 'terms' => 1])->assertSessionHasNoErrors()->assertRedirect('https://checkout.stripe.com/c/pay/de-fixture');
        Http::assertSent(fn ($r) => $r['line_items'][0]['price_data']['unit_amount'] === 147635 && $r['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] === 5500);
        $this->assertSame('1531.35', Order::sole()->total);
        $this->assertSame('100.00', $part->fresh()->price);
        $this->assertSame(1, $part->fresh()->quantity);
        Http::assertSentCount(1);
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
        $this->assertStringContainsString('gpswiss_fr_price_source', $csv->streamedContent());
        $this->get(str_replace('gpswiss.pl', 'gpswiss.fr', $url))->assertForbidden();
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
