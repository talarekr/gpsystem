<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\JarekFrProduct;
use App\Models\JarekGearbox;
use App\Models\Order;
use App\Models\Part;
use App\Models\User;
use App\Services\Marketplace\PartAvailabilityEventService;
use App\Services\Payments\PayuService;
use App\Services\Storefront\JarekFrPublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JarekFrPublicationAndCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private JarekGearbox $source;

    private const TOOL = 'https://gpswiss.pl/admin/tools/jarek-gearboxes/fr-publication';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Mail::fake();
        config(['frontend-maintenance.enabled' => false, 'stripe-fr.enabled' => true, 'stripe-fr.mode' => 'live',
            'stripe-fr.secret_key' => 'sk_live_fixture', 'stripe-fr.publishable_key' => 'pk_live_fixture',
            'stripe-fr.webhook_secret' => 'whsec_fixture']);
        $this->mock(PayuService::class)->shouldNotReceive('createOrder', 'getOrder');
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => fn ($r) => Http::response([
            'id' => 'cs_live_jarek_fixture', 'object' => 'checkout.session', 'mode' => 'payment', 'livemode' => true,
            'currency' => 'eur', 'amount_total' => $r['line_items'][0]['price_data']['unit_amount'] + $r['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'],
            'url' => 'https://checkout.stripe.com/c/pay/jarek', 'client_reference_id' => $r['client_reference_id'], 'metadata' => $r['metadata'],
        ])]);
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A',
            'rate' => 4, 'effective_date' => today()->toDateString(), 'table_no' => 'fixture/A/NBP'], 3600);
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $this->actor = User::create(['name' => 'Operator', 'email' => 'publication@example.test', 'password' => 'password']);
        $this->actor->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($this->actor);
        $this->source = $this->gearbox();
    }

    public function test_dry_run_does_not_publish_and_apply_requires_owned_snapshot_and_exact_token(): void
    {
        $before = $this->source->fresh()->getAttributes();
        $this->get(self::TOOL)->assertOk();
        $report = $this->preview();
        $this->assertSame(1, $report['summary']['would_publish_count']);
        $this->assertSame(312.5, $report['products'][0]['price_eur']);
        $this->assertDatabaseCount('jarek_fr_products', 0);
        $this->apply($report['report_id'], 'incorrect')->assertUnprocessable();
        $this->assertDatabaseCount('jarek_fr_products', 0);
        $this->apply($report['report_id'])->assertOk()->assertJsonPath('result.created_count', 1);
        $this->apply($report['report_id'])->assertOk()->assertJsonPath('result.replayed', true);
        $this->assertDatabaseCount('jarek_fr_products', 1);
        $this->assertDatabaseCount('parts', 0);
        $this->assertSame($before, $this->source->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_source_change_and_expired_preview_block_apply(): void
    {
        $report = $this->preview();
        $this->source->update(['price' => 1100]);
        $this->apply($report['report_id'])->assertUnprocessable()->assertJsonValidationErrors('report_id');
        $report = $this->preview();
        $this->travel(61)->minutes();
        $this->apply($report['report_id'])->assertUnprocessable();
        $this->assertDatabaseCount('jarek_fr_products', 0);
    }

    public function test_missing_data_blocks_only_bad_rows_and_source_stock_is_ignored(): void
    {
        $this->gearbox(['price' => null]);
        $this->gearbox(['images' => [], 'main_image_url' => null]);
        $report = $this->preview();
        $this->assertSame(3, $report['summary']['total_jarek']);
        $this->assertSame(1, $report['summary']['would_publish_count']);
        $this->assertSame(2, $report['summary']['blocked_count']);
        $this->apply($report['report_id'])->assertOk();
        $this->assertDatabaseCount('jarek_fr_products', 1);
    }

    public function test_missing_nbp_refreshes_normally_and_failed_rate_blocks_publication(): void
    {
        Cache::forget('nbp_table_a_rate:EUR:latest');
        Http::fake(['api.nbp.pl/*' => Http::response([], 503)]);
        $report = $this->preview();
        $this->assertSame(1, $report['summary']['no_nbp_rate_count']);
        $this->apply($report['report_id'])->assertUnprocessable();
        $this->assertDatabaseCount('jarek_fr_products', 0);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.nbp.pl/*' => Http::response(['rates' => [['mid' => 5, 'effectiveDate' => today()->toDateString(), 'no' => 'fixture']]])]);
        $report = $this->preview();
        $this->assertSame(250, $report['products'][0]['price_eur']);
        $this->apply($report['report_id'])->assertOk();
    }

    public function test_fr_catalog_cart_checkout_and_paid_webhook_never_change_source_stock(): void
    {
        $before = $this->source->fresh()->getAttributes();
        $product = $this->publish();
        $this->get('https://gpswiss.fr/boites-jarek/'.$product->id)->assertOk()->assertSee('312,50 EUR')->assertSee('130,00 EUR')->assertDontSee('PLN');
        $this->get('https://gpswiss.fr/czesci')->assertOk()->assertSee($this->source->title);
        $this->get('https://gpswiss.pl/czesci')->assertOk()->assertDontSee($this->source->title);
        $this->get('https://gpswiss.pl/boites-jarek/'.$product->id.'?lang=fr')->assertNotFound();
        $this->get('https://gpswiss.fr/sitemaps/jarek-1.xml')->assertOk()->assertSee('/boites-jarek/'.$product->id);
        $this->post('https://gpswiss.fr/boites-jarek/'.$product->id.'/panier', ['quantity' => 1])->assertRedirect();
        $this->withSession(['storefront.fr.address' => $this->address()]);
        $this->get('https://gpswiss.fr/panier')->assertOk()->assertSee('312,50 EUR')->assertSee('130,00 EUR');
        $key = $this->get('https://gpswiss.fr/commande')->assertOk()->viewData('draft')['checkout_key'];
        $this->post('https://gpswiss.fr/commande', ['checkout_key' => $key, 'terms' => 1])->assertRedirect('https://checkout.stripe.com/c/pay/jarek');
        $this->post('https://gpswiss.fr/commande', ['checkout_key' => $key, 'terms' => 1])->assertRedirect('https://checkout.stripe.com/c/pay/jarek');
        $order = Order::sole();
        $this->assertSame('442.50', $order->total);
        $item = $order->items()->sole();
        $this->assertNull($item->part_id);
        $this->assertSame($product->id, (int) $item->jarek_fr_product_id);
        $this->assertSame('jarek_gearbox', $item->meta['source_type']);
        $this->get('https://gpswiss.pl/admin/orders/'.$order->id)->assertOk()->assertSee('Źródło: Jarek gearbox / gpswiss_fr')->assertSee('gpswiss_fr · fr · EUR');
        $attempt = DB::table('stripe_fr_attempts')->sole();
        $payload = json_decode($attempt->request_payload, true);
        $event = ['id' => 'evt_jarek_fixture', 'type' => 'checkout.session.completed', 'livemode' => true,
            'data' => ['object' => ['object' => 'checkout.session', 'id' => $attempt->session_id, 'mode' => 'payment',
                'livemode' => true, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_jarek',
                'currency' => 'eur', 'amount_total' => 44250, 'client_reference_id' => (string) $order->id, 'metadata' => $payload['metadata']]]];
        $invalid = $event;
        $invalid['data']['object']['metadata']['jarek_fr_product_id'] = '999999';
        $this->webhook($invalid)->assertUnprocessable();
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertFalse($order->fresh()->meta['stripe']['stock_applied']);
        $this->assertSame($before, $this->source->fresh()->getAttributes());
        $this->assertDatabaseCount('parts', 0);
        $this->assertDatabaseCount('stripe_fr_events', 1);
        Http::assertSentCount(1);
    }

    public function test_reviewed_translation_overrides_source_and_unreviewed_text_falls_back(): void
    {
        $this->source->translations()->create(['locale' => 'fr', 'title' => 'Boîte française', 'description' => 'Description française', 'status' => 'reviewed']);
        $product = $this->publish();
        $this->get('https://gpswiss.fr/boites-jarek/'.$product->id)->assertSee('Boîte française')->assertSee('Description française');
        $this->source->translations()->update(['status' => 'pending']);
        $this->get('https://gpswiss.fr/boites-jarek/'.$product->id)->assertSee($this->source->title)->assertDontSee('Boîte française');
        Http::assertNothingSent();
    }

    public function test_cart_rejects_second_product_and_more_than_one_unit_and_hides_fr_cart_on_pl(): void
    {
        $product = $this->publish();
        $this->post('https://gpswiss.fr/boites-jarek/'.$product->id.'/panier', ['quantity' => 2])->assertSessionHasErrors('quantity');
        $this->assertSame([], session('storefront.cart.items', []));
        $this->post('https://gpswiss.fr/boites-jarek/'.$product->id.'/panier', ['quantity' => 1])->assertRedirect();
        $this->post('https://gpswiss.fr/boites-jarek/'.$product->id.'/panier', ['quantity' => 1])->assertSessionHas('warning');
        $part = Part::create(['name' => 'Ordinary part', 'sku' => 'ORDINARY', 'price' => 100, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'ready']);
        $this->post('https://gpswiss.fr/koszyk/dodaj/'.$part->id, ['quantity' => 1])->assertSessionHas('warning');
        $this->assertCount(1, session('storefront.cart.items'));
        $this->get('https://gpswiss.pl/koszyk')->assertOk()->assertDontSee($this->source->title);
        $this->post('https://gpswiss.pl/boites-jarek/'.$product->id.'/panier', ['quantity' => 1])->assertNotFound();
        $this->get('https://gpswiss.fr/boites-jarek')->assertOk()->assertDontSee('noindex, noarchive')->assertDontSee('hreflang="pl"', false);
        $this->get('https://gpswiss.fr/boites-jarek/'.$product->id)->assertOk()->assertDontSee('noindex, noarchive')->assertDontSee('hreflang="pl"', false);
    }

    public function test_failed_rate_blocks_checkout_before_order_or_stripe(): void
    {
        $product = $this->publish();
        $this->post('https://gpswiss.fr/boites-jarek/'.$product->id.'/panier');
        $this->withSession(['storefront.fr.address' => $this->address()]);
        Cache::forget('nbp_table_a_rate:EUR:latest');
        Http::fake(['api.nbp.pl/*' => Http::response([], 503)]);
        $draft = $this->get('https://gpswiss.fr/commande')->assertOk()->viewData('draft');
        $this->assertFalse($draft['can_place_order']);
        $this->post('https://gpswiss.fr/commande', ['checkout_key' => $draft['checkout_key'], 'terms' => 1])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.stripe.com'));
    }

    public function test_price_change_invalidates_checkout_key(): void
    {
        $product = $this->publish();
        $this->post('https://gpswiss.fr/boites-jarek/'.$product->id.'/panier');
        $this->withSession(['storefront.fr.address' => $this->address()]);
        $key = $this->get('https://gpswiss.fr/commande')->viewData('draft')['checkout_key'];
        $this->source->update(['price' => 2000]);
        $this->post('https://gpswiss.fr/commande', ['checkout_key' => $key, 'terms' => 1])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_outstanding_stripe_attempt_prevents_parallel_supplier_checkout(): void
    {
        $product = $this->publish();
        $this->post('https://gpswiss.fr/boites-jarek/'.$product->id.'/panier');
        $this->withSession(['storefront.fr.address' => $this->address()]);
        $key = $this->get('https://gpswiss.fr/commande')->viewData('draft')['checkout_key'];
        $this->post('https://gpswiss.fr/commande', ['checkout_key' => $key, 'terms' => 1])->assertRedirect('https://checkout.stripe.com/c/pay/jarek');
        $this->flushSession();
        $this->post('https://gpswiss.fr/boites-jarek/'.$product->id.'/panier');
        $this->withSession(['storefront.fr.address' => $this->address()]);
        $otherKey = $this->get('https://gpswiss.fr/commande')->viewData('draft')['checkout_key'];
        $this->assertNotSame($key, $otherKey);
        $this->post('https://gpswiss.fr/commande', ['checkout_key' => $otherKey, 'terms' => 1])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(1);
    }

    public function test_publication_report_is_owner_only_and_cannot_be_applied_by_another_actor(): void
    {
        $id = $this->preview()['report_id'];
        $other = User::create(['name' => 'Other', 'email' => 'other-publication@example.test', 'password' => 'password']);
        $this->actingAs($other);
        $this->getJson(self::TOOL)->assertForbidden();
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->apply($id)->assertUnprocessable();
        $this->getJson(self::TOOL.'?report_id='.$id)->assertNotFound();
        $this->postJson('https://gpswiss.fr/admin/tools/jarek-gearboxes/fr-publication', ['action' => 'dry-run'])->assertForbidden();
        $this->assertDatabaseCount('jarek_fr_products', 0);
    }

    public function test_new_preview_of_already_published_sources_creates_no_duplicates(): void
    {
        $product = $this->publish();
        $before = $product->getAttributes();
        $report = $this->preview();
        $this->assertSame(0, $report['summary']['would_publish_count']);
        $this->assertSame(1, $report['summary']['already_active_count']);
        $this->apply($report['report_id'])->assertOk()->assertJsonPath('result.created_count', 0);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('jarek_fr_products', 1);
    }

    public function test_stale_nbp_cache_is_refreshed_and_rate_change_requires_new_preview(): void
    {
        $id = $this->preview()['report_id'];
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A',
            'rate' => 4, 'effective_date' => today()->subDays(8)->toDateString(), 'table_no' => 'old'], 3600);
        Http::fake(['api.nbp.pl/*' => Http::response(['rates' => [['mid' => 5, 'effectiveDate' => today()->toDateString(), 'no' => 'new']]])]);
        $this->apply($id)->assertUnprocessable()->assertJsonValidationErrors('report_id');
        $this->assertDatabaseCount('jarek_fr_products', 0);
        $report = $this->preview();
        $this->assertSame(250, $report['products'][0]['price_eur']);
        $this->apply($report['report_id'])->assertOk();
        Http::assertSentCount(1);
    }

    private function gearbox(array $values = []): JarekGearbox
    {
        return JarekGearbox::create($values + ['source_account' => 'jarek', 'allegro_offer_id' => (string) (1000000 + JarekGearbox::count()),
            'title' => 'Skrzynia Jarka AUDIT-XYZ', 'plain_description' => 'Opis źródłowy', 'price' => 1000, 'currency' => 'PLN',
            'quantity' => 0, 'allegro_status' => 'ENDED', 'main_image_url' => 'https://example.test/gearbox.jpg', 'images' => []]);
    }

    private function preview(): array
    {
        return $this->postJson(self::TOOL, ['action' => 'dry-run'])->assertOk()->json('report');
    }

    private function apply(string $id, string $token = JarekFrPublication::CONFIRM)
    {
        return $this->postJson(self::TOOL, ['action' => 'apply', 'report_id' => $id, 'confirm' => $token]);
    }

    private function publish(): JarekFrProduct
    {
        $this->apply($this->preview()['report_id'])->assertOk();

        return JarekFrProduct::sole();
    }

    private function webhook(array $event)
    {
        $body = json_encode($event);
        $signature = hash_hmac('sha256', now()->timestamp.'.'.$body, 'whsec_fixture');

        return $this->call('POST', 'https://gpswiss.fr/payments/stripe/fr/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => 't='.now()->timestamp.',v1='.$signature], $body);
    }

    private function address(): array
    {
        return ['customer_type' => 'private', 'billing_first_name' => 'Jean', 'billing_last_name' => 'Dupont',
            'billing_address_line1' => '7 rue Exemple', 'billing_postal_code' => '75008', 'billing_city' => 'Paris',
            'billing_country' => 'FR', 'billing_email' => 'jean@example.test', 'billing_phone' => '+33123456789',
            'shipping_same_as_billing' => true, 'shipping_country' => 'FR'];
    }
}
