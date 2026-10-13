<?php

namespace Tests\Feature;

use App\Mail\FrenchOrderMail;
use App\Models\Order;
use App\Models\ShopEvent;
use App\Models\User;
use App\Services\Storefront\FrenchOrderNotifications;
use App\Support\FrenchOrderPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class FrenchOrderCommunicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false]);
    }

    public function test_mail_has_french_identity_snapshot_and_a_fixed_https_signed_guest_link(): void
    {
        $order = $this->order();
        $mail = new FrenchOrderMail($order, 'paid');
        $html = $mail->render();
        foreach (['GREGOR SWISS FR', '7 rue de Castellane', '75008 Paris', 'France',
            'contact@gpswiss.fr', '+33 6 95 12 11 80', 'Moteur français', '155,00 EUR',
            'Paiement confirmé', 'lang="fr"'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        foreach (['biuro@gpswiss.fr', 'Milanowska', 'Sobolew', 'Pologne'] as $value) {
            $this->assertStringNotContainsString($value, $html);
        }
        $this->assertSame('contact@gpswiss.fr', $mail->envelope()->from->address);
        $this->assertSame('contact@gpswiss.fr', $mail->envelope()->replyTo[0]->address);
        $this->assertStringContainsString('Confirmation de paiement', $mail->envelope()->subject);
        // Generated outside an FR HTTP request; global APP_URL may point to PL.
        $link = FrenchOrderPresentation::link($order);
        $this->assertSame('gpswiss.fr', parse_url($link, PHP_URL_HOST));
        $this->assertSame('https', parse_url($link, PHP_URL_SCHEME));
        $this->assertTrue(app('url')->hasValidSignature(Request::create($link)));
        $response = $this->get($link)->assertOk()->assertSee($order->email)->assertSee('Commande reçue')
            ->assertSee('En attente de paiement')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, noarchive');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('https://gpswiss.fr/commande/suivi/'.$order->id)->assertForbidden();
        $this->get('https://gpswiss.fr/zamowienie/dziekujemy/'.$order->id)->assertForbidden();
        $this->get(str_replace('order', 'other', $link).'&tampered=1')->assertForbidden();
        $this->travel(31)->days();
        $this->get($link)->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_existing_signed_thank_you_shows_french_order_status_and_rejects_wrong_host(): void
    {
        $order = $this->order(['status' => 'processing', 'payment_status' => 'paid']);
        $urls = clone app('url');
        $urls->forceRootUrl('https://gpswiss.fr');
        $urls->forceScheme('https');
        $link = $urls->temporarySignedRoute('storefront.checkout.thank-you', now()->addDays(7), ['order' => $order->id]);
        $this->get($link)->assertOk()->assertViewIs('storefront.fr.order')->assertSee('En préparation')
            ->assertSee('Paiement confirmé')->assertDontSee('W realizacji');
        $urls->forceRootUrl('https://gpswiss.pl');
        $plLink = $urls->temporarySignedRoute('storefront.checkout.thank-you', now()->addDays(7), ['order' => $order->id]);
        $this->get($plLink)->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_signed_fr_link_cannot_reveal_a_polish_order(): void
    {
        $order = $this->order(['meta' => ['storefront_code' => 'gpswiss_pl', 'payment_provider' => 'payu']]);
        $this->get(FrenchOrderPresentation::link($order))->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_history_uses_real_customer_owned_fr_orders_not_email_matches_or_shop_events(): void
    {
        $user = $this->user('client@example.test');
        $mine = $this->order(['customer_id' => $user->id, 'email' => $user->email, 'status' => 'shipped']);
        $guest = $this->order(['email' => $user->email]);
        $other = $this->order(['customer_id' => $this->user('other@example.test')->id, 'email' => $user->email]);
        $pl = $this->order(['customer_id' => $user->id, 'meta' => ['storefront_code' => 'gpswiss_pl']]);
        ShopEvent::create(['event_type' => 'order', 'source' => 'storefront', 'title' => 'Fake event order',
            'payload' => ['customer_email' => $user->email], 'occurred_at' => now(), 'severity' => 'info']);
        $this->actingAs($user)->get('https://gpswiss.fr/moje-konto')->assertOk()
            ->assertViewIs('storefront.fr.account')
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->id === $mine->id)
            ->assertSee($mine->order_number)->assertSee('Expédiée')->assertSee('155,00 EUR')
            ->assertSee('signature=')->assertDontSee($guest->order_number)->assertDontSee($other->order_number)
            ->assertDontSee($pl->order_number)->assertDontSee('Fake event order');
        $this->get('https://gpswiss.fr/commande/suivi/'.$other->id)->assertForbidden();
        $this->post('https://gpswiss.fr/moje-konto/zwroty', ['order_id' => $mine->id, 'reason' => 'Retour'])
            ->assertRedirect()->assertSessionHas('warning');
        $this->assertDatabaseCount('customer_returns', 0);
        $this->assertDatabaseCount('shop_events', 1);
        Mail::assertNothingSent();
    }

    public function test_empty_account_explains_guest_access_and_requires_authentication(): void
    {
        $this->get('https://gpswiss.fr/moje-konto')->assertRedirect();
        $this->actingAs($this->user('guest@example.test'))->get('https://gpswiss.fr/moje-konto')->assertOk()
            ->assertSee('Aucune commande GPSwiss.fr')->assertSee('lien personnel reçu par e-mail');
    }

    public function test_polish_account_keeps_legacy_dashboard_and_events(): void
    {
        $user = $this->user('pl@example.test');
        ShopEvent::create(['event_type' => 'order', 'source' => 'storefront', 'title' => 'Legacy PL order',
            'payload' => ['customer_email' => $user->email], 'severity' => 'info', 'occurred_at' => now()]);
        $this->actingAs($user)->get('https://gpswiss.pl/moje-konto')->assertOk()
            ->assertViewIs('storefront.account.dashboard')->assertSee('Legacy PL order');
        Mail::assertNothingSent();
    }

    public function test_all_order_statuses_have_french_labels_and_unknown_values_are_not_exposed(): void
    {
        $expected = ['Commande reçue', 'En préparation', 'En attente de vérification', 'Prête à être expédiée',
            'Prête à être retirée', 'Expédiée', 'Retirée', 'Terminée', 'Annulée'];
        foreach (array_combine(Order::STATUSES, $expected) as $status => $label) {
            $this->assertSame($label, FrenchOrderPresentation::status($status));
        }
        $this->assertSame('Statut à vérifier', FrenchOrderPresentation::status('Polski nieznany status'));
    }

    public function test_claimed_delivery_is_not_replayed_and_paid_messages_are_unique_per_order(): void
    {
        $order = $this->order(['payment_status' => 'paid']);
        $service = app(FrenchOrderNotifications::class);
        DB::transaction(function () use ($service, $order) {
            $service->record($order, 'paid');
            $service->record($order, 'paid');
        });
        DB::table('storefront_fr_order_notifications')->update(['state' => 'sending', 'claimed_at' => now()]);
        $service->deliver($order->id);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('storefront_fr_order_notifications', 1);
    }

    public function test_rollback_does_not_send_or_leave_a_payment_notification(): void
    {
        $order = $this->order(['payment_status' => 'paid']);
        $service = app(FrenchOrderNotifications::class);
        DB::beginTransaction();
        $service->record($order, 'paid');
        $service->deliver($order->id);
        Mail::assertNothingSent();
        DB::rollBack();
        $this->assertDatabaseCount('storefront_fr_order_notifications', 0);
    }

    public function test_transport_failure_does_not_undo_payment_or_trigger_a_duplicate_send(): void
    {
        $order = $this->order(['payment_status' => 'paid']);
        $service = app(FrenchOrderNotifications::class);
        DB::transaction(fn () => $service->record($order, 'paid'));
        $pending = \Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP result uncertain'));
        Mail::shouldReceive('to')->once()->with($order->email)->andReturn($pending);
        $service->deliver($order->id);
        $service->deliver($order->id);
        $this->assertDatabaseHas('storefront_fr_order_notifications', ['order_id' => $order->id, 'state' => 'uncertain']);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_pending_delivery_resumes_once_but_obsolete_awaiting_mail_is_suppressed(): void
    {
        $order = $this->order(['payment_status' => 'paid']);
        $service = app(FrenchOrderNotifications::class);
        DB::transaction(function () use ($service, $order) {
            $service->record($order, 'awaiting');
            $service->record($order, 'paid');
        });
        $service->deliver($order->id);
        $service->deliver($order->id);
        Mail::assertSentCount(1);
        Mail::assertSent(FrenchOrderMail::class, fn ($mail) => $mail->kind === 'paid');
        $this->assertDatabaseHas('storefront_fr_order_notifications', ['kind' => 'awaiting', 'state' => 'suppressed']);
        $this->assertDatabaseHas('storefront_fr_order_notifications', ['kind' => 'paid', 'state' => 'sent']);
    }

    private function user(string $email): User
    {
        return User::create(['name' => 'Client', 'email' => $email, 'password' => 'test-password']);
    }

    private function order(array $overrides = []): Order
    {
        $order = Order::create(array_replace([
            'order_number' => 'GPS-FR-'.Str::uuid(), 'status' => 'new', 'payment_status' => 'pending',
            'currency' => 'EUR', 'subtotal' => 100, 'shipping_total' => 55, 'total' => 155,
            'ordered_at' => now(), 'customer_name' => 'Jean Dupont', 'email' => 'jean@example.test',
            'phone' => '+33123456789', 'address_line1' => '7 rue Exemple', 'city' => 'Paris',
            'postal_code' => '75008', 'country' => 'FR',
            'meta' => ['storefront_code' => 'gpswiss_fr', 'locale' => 'fr', 'payment_provider' => 'stripe'],
        ], $overrides));
        $order->items()->create(['product_name' => 'Moteur français', 'sku' => 'FR-123', 'quantity' => 1,
            'unit_price' => 100, 'line_total' => 100, 'currency' => 'EUR']);

        return $order;
    }
}
