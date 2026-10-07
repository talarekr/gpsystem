<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Marketplace\PartAvailabilityEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StripeFrWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'frontend-maintenance.enabled' => false,
            'stripe_fr.enabled' => true,
            'stripe_fr.mode' => 'test',
            'stripe_fr.secret_key' => 'sk_test_webhook_secret',
            'stripe_fr.publishable_key' => 'pk_test_webhook_public',
            'stripe_fr.webhook_secret' => 'whsec_webhook_test',
        ]);
        $this->mock(PartAvailabilityEventService::class)->shouldNotReceive('sold');
    }

    #[DataProvider('acceptedEvents')]
    public function test_correctly_signed_event_updates_only_matching_fr_order(string $type, string $paymentStatus): void
    {
        $order = $this->order();
        $other = $this->order(['order_number' => 'GPS-OTHER', 'marketplace' => 'allegro', 'meta' => ['source' => 'allegro']]);
        $otherOriginal = $other->getRawOriginal();
        $event = $this->event($order, $type);

        $this->sendEvent($event)->assertOk();

        $order->refresh();
        $this->assertSame($paymentStatus, $order->payment_status);
        $this->assertSame($paymentStatus === 'paid' ? 'processing' : 'new', $order->status);
        $this->assertContains($event['id'], data_get($order->meta, 'stripe.event_ids'));
        $this->assertSame('pi_webhook_test', data_get($order->meta, 'stripe.payment_intent_id'));
        $this->assertSame($otherOriginal, $other->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function acceptedEvents(): array
    {
        return [
            'session completed' => ['checkout.session.completed', 'paid'],
            'intent succeeded' => ['payment_intent.succeeded', 'paid'],
            'intent failed' => ['payment_intent.payment_failed', 'failed'],
        ];
    }

    #[DataProvider('invalidSignatures')]
    public function test_bad_missing_stale_or_future_signature_rejects_without_mutating_order(string $signatureCase): void
    {
        $order = $this->order();
        $original = $order->getRawOriginal();
        $event = $this->event($order);
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp + match ($signatureCase) {
            'stale' => -301,
            'future' => 301,
            default => 0,
        };
        $signature = match ($signatureCase) {
            'missing' => '',
            'bad HMAC' => 't='.$timestamp.',v1='.str_repeat('0', 64),
            'tampered body' => $this->signature($body.' ', $timestamp),
            default => $this->signature($body, $timestamp),
        };

        $this->sendRaw($body, $signature)->assertUnauthorized();

        $this->assertSame($original, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function invalidSignatures(): array
    {
        return [
            'missing' => ['missing'],
            'bad HMAC' => ['bad HMAC'],
            'raw body was altered' => ['tampered body'],
            'expired timestamp' => ['stale'],
            'future timestamp' => ['future'],
        ];
    }

    public function test_signature_key_rotation_accepts_one_valid_v1_signature(): void
    {
        $order = $this->order();
        $body = json_encode($this->event($order), JSON_THROW_ON_ERROR);
        $signature = $this->signature($body, now()->timestamp).',v1='.str_repeat('0', 64);

        $this->sendRaw($body, $signature)->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    #[DataProvider('mismatchedEvents')]
    public function test_signed_mismatched_event_does_not_mark_order_paid(string $type, array $changes): void
    {
        $order = $this->order();
        $original = $order->getRawOriginal();
        $event = $this->event($order, $type);
        foreach ($changes as $key => $value) {
            data_set($event, $key, $value);
        }

        $this->sendEvent($event)->assertStatus(422);

        $this->assertSame($original, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function mismatchedEvents(): array
    {
        $completed = 'checkout.session.completed';
        $succeeded = 'payment_intent.succeeded';

        return [
            'session amount' => [$completed, ['data.object.amount_total' => 1235]],
            'session currency' => [$completed, ['data.object.currency' => 'pln']],
            'storefront' => [$completed, ['data.object.metadata.storefront_code' => 'gpswiss_pl']],
            'provider' => [$completed, ['data.object.metadata.payment_provider' => 'payu']],
            'environment' => [$completed, ['data.object.metadata.environment' => 'live']],
            'missing order reference' => [$completed, ['data.object.metadata.order_id' => null]],
            'unknown order reference' => [$completed, ['data.object.metadata.order_id' => '999999']],
            'client reference differs' => [$completed, ['data.object.client_reference_id' => '999999']],
            'wrong stored session' => [$completed, ['data.object.id' => 'cs_test_other']],
            'wrong stored intent' => [$completed, ['data.object.payment_intent' => 'pi_other']],
            'unpaid session' => [$completed, ['data.object.payment_status' => 'unpaid']],
            'subscription session' => [$completed, ['data.object.mode' => 'subscription']],
            'live event' => [$completed, ['livemode' => true]],
            'live object' => [$completed, ['data.object.livemode' => true]],
            'intent amount' => [$succeeded, ['data.object.amount' => 1235]],
            'intent amount received' => [$succeeded, ['data.object.amount_received' => 1233]],
            'intent currency' => [$succeeded, ['data.object.currency' => 'pln']],
            'wrong intent id' => [$succeeded, ['data.object.id' => 'pi_other']],
        ];
    }

    #[DataProvider('wrongOrderMetadata')]
    public function test_signed_stripe_event_cannot_update_non_fr_or_legacy_order(array $metadata): void
    {
        $order = $this->order();
        $event = $this->event($order);
        $order->update(['meta' => array_replace($order->meta, $metadata)]);
        $original = $order->getRawOriginal();

        $this->sendEvent($event)->assertStatus(422);

        $this->assertSame($original, $order->fresh()->getRawOriginal());
    }

    public static function wrongOrderMetadata(): array
    {
        return [
            'PL order' => [['storefront_code' => 'gpswiss_pl']],
            'PayU order' => [['payment_provider' => 'payu']],
            'marketplace order' => [['source' => 'allegro']],
        ];
    }

    public function test_duplicate_event_is_idempotent_and_later_failure_cannot_regress_paid_order(): void
    {
        $order = $this->order();
        $completed = $this->event($order);
        $this->sendEvent($completed)->assertOk();
        $original = $order->fresh()->getRawOriginal();

        $this->sendEvent($completed)->assertOk();
        $this->assertSame($original, $order->fresh()->getRawOriginal());

        $failed = $this->event($order, 'payment_intent.payment_failed', 'evt_after_paid_failure');
        $this->sendEvent($failed)->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertSame($original['status_changed_at'], $order->getRawOriginal('status_changed_at'));
        $ids = data_get($order->meta, 'stripe.event_ids');
        $this->assertCount(2, $ids);
        $this->assertContains($completed['id'], $ids);
        $this->assertContains($failed['id'], $ids);
        Http::assertNothingSent();
    }

    public function test_success_after_failed_attempt_can_mark_order_paid(): void
    {
        $order = $this->order();
        $failed = $this->event($order, 'payment_intent.payment_failed', 'evt_first_failed');
        $this->sendEvent($failed)->assertOk();
        $this->assertSame('failed', $order->fresh()->payment_status);

        $succeeded = $this->event($order, 'payment_intent.succeeded', 'evt_later_succeeded');
        $this->sendEvent($succeeded)->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertCount(2, data_get($order->fresh()->meta, 'stripe.event_ids'));
    }

    public function test_intent_event_before_session_completed_is_bound_to_stored_session_via_stripe(): void
    {
        $order = $this->order();
        $meta = $order->meta;
        data_set($meta, 'stripe.payment_intent_id', null);
        $order->update(['meta' => $meta]);
        $session = $this->event($order)['data']['object'];
        Http::fake(['https://api.stripe.com/v1/checkout/sessions/cs_test_webhook' => Http::response($session)]);

        $this->sendEvent($this->event($order, 'payment_intent.succeeded'))->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('pi_webhook_test', data_get($order->meta, 'stripe.payment_intent_id'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.stripe.com/v1/checkout/sessions/cs_test_webhook'
            && $request->hasHeader('Authorization', 'Bearer sk_test_webhook_secret'));
    }

    public function test_intent_without_local_binding_cannot_attach_to_a_different_remote_session_intent(): void
    {
        $order = $this->order();
        $meta = $order->meta;
        data_set($meta, 'stripe.payment_intent_id', null);
        $order->update(['meta' => $meta]);
        $original = $order->getRawOriginal();
        $session = $this->event($order)['data']['object'];
        $session['payment_intent'] = 'pi_other';
        Http::fake(['https://api.stripe.com/v1/checkout/sessions/cs_test_webhook' => Http::response($session)]);

        $this->sendEvent($this->event($order, 'payment_intent.succeeded'))->assertStatus(422);

        $this->assertSame($original, $order->fresh()->getRawOriginal());
        Http::assertSentCount(1);
    }

    public function test_transient_session_lookup_failure_can_retry_same_event_without_partial_update(): void
    {
        $order = $this->order();
        $meta = $order->meta;
        data_set($meta, 'stripe.payment_intent_id', null);
        $order->update(['meta' => $meta]);
        $original = $order->getRawOriginal();
        $session = $this->event($order)['data']['object'];
        Http::fake(['https://api.stripe.com/v1/checkout/sessions/cs_test_webhook' => Http::sequence()
            ->push(['error' => ['message' => 'Temporary provider failure']], 503)
            ->push($session)]);
        $event = $this->event($order, 'payment_intent.succeeded');

        $this->sendEvent($event)->assertStatus(503);
        $this->assertSame($original, $order->fresh()->getRawOriginal());

        $this->sendEvent($event)->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame([$event['id']], data_get($order->fresh()->meta, 'stripe.event_ids'));
        Http::assertSentCount(2);
    }

    public function test_payment_confirmation_preserves_existing_order_fulfillment_status(): void
    {
        $order = $this->order(['status' => 'ready_to_ship', 'status_changed_at' => '2026-01-01 12:00:00']);
        $changedAt = $order->getRawOriginal('status_changed_at');

        $this->sendEvent($this->event($order))->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('ready_to_ship', $order->status);
        $this->assertSame($changedAt, $order->getRawOriginal('status_changed_at'));
    }

    public function test_disabled_stripe_webhook_does_not_update_order(): void
    {
        config(['stripe_fr.enabled' => false]);
        $order = $this->order();
        $original = $order->getRawOriginal();

        $this->sendEvent($this->event($order))->assertStatus(503);

        $this->assertSame($original, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_event_before_initial_session_is_saved_retries_without_poisoning_deduplication(): void
    {
        $order = $this->order();
        $meta = $order->meta;
        data_set($meta, 'stripe.session_id', null);
        data_set($meta, 'stripe.payment_intent_id', null);
        $order->update(['meta' => $meta]);
        $original = $order->getRawOriginal();
        $event = $this->event($order);

        $this->sendEvent($event)->assertStatus(503);
        $this->assertSame($original, $order->fresh()->getRawOriginal());

        data_set($meta, 'stripe.session_id', 'cs_test_webhook');
        $order->update(['meta' => $meta]);
        $this->sendEvent($event)->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame([$event['id']], data_get($order->fresh()->meta, 'stripe.event_ids'));
        Http::assertNothingSent();
    }

    #[DataProvider('nonFrenchHosts')]
    public function test_webhook_rejects_requests_on_other_hosts(string $host): void
    {
        $order = $this->order();
        $original = $order->getRawOriginal();

        $this->sendEvent($this->event($order), $host)->assertNotFound();

        $this->assertSame($original, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function nonFrenchHosts(): array
    {
        return ['PL' => ['gpswiss.pl'], 'preview' => ['preview.example.test']];
    }

    public function test_www_french_host_accepts_signed_webhook(): void
    {
        $order = $this->order();

        $this->sendEvent($this->event($order), 'www.gpswiss.fr')->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_webhook_does_not_trust_order_id_or_status_from_query_parameters(): void
    {
        $order = $this->order();
        $other = $this->order(['order_number' => 'GPS-QUERY-OTHER']);
        $otherOriginal = $other->getRawOriginal();
        $body = json_encode($this->event($order), JSON_THROW_ON_ERROR);

        $this->sendRaw($body, $this->signature($body, now()->timestamp), 'gpswiss.fr', '?order_id='.$other->id.'&status=paid')
            ->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($otherOriginal, $other->fresh()->getRawOriginal());
    }

    public function test_signed_invalid_json_is_rejected(): void
    {
        $body = '{invalid-json';

        $this->sendRaw($body, $this->signature($body, now()->timestamp))->assertStatus(400);

        Http::assertNothingSent();
    }

    private function event(Order $order, string $type = 'checkout.session.completed', string $eventId = 'evt_webhook_test'): array
    {
        $metadata = [
            'order_id' => (string) $order->id,
            'storefront_code' => 'gpswiss_fr',
            'payment_provider' => 'stripe',
            'environment' => 'test',
        ];
        $object = $type === 'checkout.session.completed' ? [
            'id' => 'cs_test_webhook',
            'object' => 'checkout.session',
            'mode' => 'payment',
            'amount_total' => 1234,
            'currency' => 'eur',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_webhook_test',
            'client_reference_id' => (string) $order->id,
        ] : [
            'id' => 'pi_webhook_test',
            'object' => 'payment_intent',
            'status' => $type === 'payment_intent.succeeded' ? 'succeeded' : 'requires_payment_method',
            'amount' => 1234,
            'amount_received' => $type === 'payment_intent.succeeded' ? 1234 : 0,
            'currency' => 'eur',
        ];

        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created' => now()->timestamp,
            'livemode' => false,
            'data' => ['object' => array_merge($object, ['livemode' => false, 'metadata' => $metadata])],
        ];
    }

    private function sendEvent(array $event, string $host = 'gpswiss.fr'): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        return $this->sendRaw($body, $this->signature($body, now()->timestamp), $host);
    }

    private function signature(string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_webhook_test');
    }

    private function sendRaw(string $body, string $signature, string $host = 'gpswiss.fr', string $query = ''): TestResponse
    {
        return $this->call('POST', 'https://'.$host.'/stripe/fr/webhook'.$query, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $body);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_replace([
            'order_number' => 'GPS-WEBHOOK-TEST',
            'status' => 'new',
            'currency' => 'EUR',
            'subtotal' => '12.34',
            'shipping_total' => 0,
            'total' => '12.34',
            'payment_status' => 'pending',
            'customer_name' => 'Jean Test',
            'email' => 'jean@example.test',
            'phone' => '0102030405',
            'address_line1' => 'Rue Test 1',
            'postal_code' => '75001',
            'city' => 'Paris',
            'country' => 'FR',
            'meta' => [
                'source' => 'storefront',
                'payment_provider' => 'stripe',
                'storefront_code' => 'gpswiss_fr',
                'stripe' => [
                    'session_id' => 'cs_test_webhook',
                    'payment_intent_id' => 'pi_webhook_test',
                    'environment' => 'test',
                    'test' => true,
                    'livemode' => false,
                    'amount_minor' => 1234,
                    'currency' => 'eur',
                    'return_token' => str_repeat('r', 64),
                    'event_ids' => [],
                ],
            ],
        ], $overrides))->refresh();
    }
}
