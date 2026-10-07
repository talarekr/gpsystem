<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class StripeFrWebhookService
{
    public function __construct(private readonly StripeFrService $stripe) {}

    public function handle(array $event): void
    {
        if (! is_string($event['id'] ?? null) || strlen($event['id']) > 255
            || ! preg_match('/^evt_[A-Za-z0-9_]+$/D', $event['id'])
            || ($event['object'] ?? null) !== 'event'
            || ($event['livemode'] ?? null) !== false) {
            throw new InvalidArgumentException('Invalid Stripe event.');
        }
        $type = $event['type'] ?? null;
        if (! in_array($type, ['checkout.session.completed', 'payment_intent.succeeded', 'payment_intent.payment_failed'], true)) {
            return;
        }
        $data = $event['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;
        $metadata = is_array($object) ? ($object['metadata'] ?? null) : null;
        $orderId = is_array($metadata) ? ($metadata['order_id'] ?? null) : null;
        if (! is_array($object) || ! is_string($orderId)
            || ! preg_match('/^[1-9][0-9]{0,18}$/D', $orderId)) {
            throw new InvalidArgumentException('Invalid Stripe event.');
        }

        DB::transaction(function () use ($event, $type, $object, $orderId): void {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                throw new InvalidArgumentException('The payment order does not match.');
            }
            $this->stripe->assertStorefrontOrder($order);
            $this->stripe->assertMetadata($object['metadata'] ?? null, $order);
            $meta = $order->meta ?? [];
            $payment = $meta['stripe'] ?? [];
            if (! is_array($payment) || ($payment['environment'] ?? null) !== 'test'
                || ($payment['test'] ?? null) !== true
                || ($payment['livemode'] ?? null) !== false
                || ($payment['amount_minor'] ?? null) !== $this->stripe->amountMinor($order)
                || ($payment['currency'] ?? null) !== $this->stripe->currency($order)
                || ($object['livemode'] ?? null) !== false) {
                throw new InvalidArgumentException('The payment environment or amount does not match.');
            }
            // A signed callback can precede the checkout API response. Retry
            // after the session is persisted instead of trusting a new ID.
            if (blank($payment['session_id'] ?? null)) {
                throw new RuntimeException('Stripe Checkout confirmation is not ready.');
            }
            $eventIds = $payment['event_ids'] ?? [];
            if (! is_array($eventIds)) {
                throw new InvalidArgumentException('Invalid payment event history.');
            }
            if (in_array($event['id'], $eventIds, true)) {
                return;
            }

            $paid = false;
            $failed = false;
            if ($type === 'checkout.session.completed') {
                $this->stripe->assertSession($object, $order);
                if (($object['payment_status'] ?? null) !== 'paid') {
                    throw new InvalidArgumentException('Invalid checkout payment status.');
                }
                $intentId = $this->stripe->paymentIntentId($object['payment_intent'] ?? null);
                $paid = $object['payment_status'] === 'paid';
                if ($paid && $intentId === null) {
                    throw new InvalidArgumentException('Missing payment intent.');
                }
            } else {
                $intentId = $this->stripe->paymentIntentId($object['id'] ?? null);
                if (($object['object'] ?? null) !== 'payment_intent'
                    || $intentId === null || ($object['amount'] ?? null) !== $payment['amount_minor']
                    || ($object['currency'] ?? null) !== $payment['currency']) {
                    throw new InvalidArgumentException('The payment intent amount does not match.');
                }
                if (blank($payment['payment_intent_id'] ?? null)) {
                    $session = $this->stripe->retrieveCheckoutSession($payment['session_id']);
                    $this->stripe->assertSession($session, $order);
                    $boundIntent = $this->stripe->paymentIntentId($session['payment_intent'] ?? null);
                    if ($boundIntent === null) {
                        throw new RuntimeException('Stripe Checkout confirmation is not ready.');
                    }
                    if ($boundIntent !== $intentId) {
                        throw new InvalidArgumentException('The payment intent does not match the checkout session.');
                    }
                }
                $paid = $type === 'payment_intent.succeeded';
                $failed = $type === 'payment_intent.payment_failed';
                if ($paid && (($object['status'] ?? null) !== 'succeeded'
                    || ($object['amount_received'] ?? null) !== $payment['amount_minor'])) {
                    throw new InvalidArgumentException('The payment intent is not paid in full.');
                }
                if ($failed && ! in_array($object['status'] ?? null, ['requires_payment_method', 'canceled'], true)) {
                    throw new InvalidArgumentException('Invalid failed payment status.');
                }
            }
            if (filled($payment['payment_intent_id'] ?? null) && $intentId !== null
                && $payment['payment_intent_id'] !== $intentId) {
                throw new InvalidArgumentException('The payment intent does not match.');
            }
            $payment['payment_intent_id'] = $intentId ?? ($payment['payment_intent_id'] ?? null);
            $payment['event_ids'] = [...$eventIds, $event['id']];
            $payment['last_event'] = ['id' => $event['id'], 'type' => $type, 'received_at' => now()->toIso8601String()];
            $meta['stripe'] = $payment;
            $updates = ['meta' => $meta];
            if ($paid) {
                $updates['payment_status'] = 'paid';
                if ($order->status === 'new') {
                    $updates['status'] = 'processing';
                    $updates['status_changed_at'] = now();
                }
            } elseif ($failed && $order->payment_status !== 'paid') {
                $updates['payment_status'] = 'failed';
            }
            $order->update($updates);
        });
    }
}
