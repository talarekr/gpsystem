<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Part;
use App\Services\Storefront\FrenchOrderNotifications;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StripeFrWebhook
{
    public function __construct(private FrenchOrderNotifications $notifications) {}

    public function handle(array $event): void
    {
        $type = $event['type'] ?? '';
        if (! in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
            return;
        }
        $eventId = $event['id'] ?? '';
        $session = $event['data']['object'] ?? [];
        if (! is_string($eventId) || ! str_starts_with($eventId, 'evt_') || strlen($eventId) > 255
            || ($session['object'] ?? '') !== 'checkout.session') {
            $this->reject();
        }
        $orderId = DB::transaction(function () use ($event, $eventId, $session, $type): int {
            $order = Order::query()->whereKey($session['metadata']['order_id'] ?? 0)->lockForUpdate()->first();
            if (! $order || ! $order->isFrenchStorefront() || $order->paymentProvider() !== 'stripe'
                || $order->currency !== 'EUR' || data_get($order->meta, 'locale') !== 'fr'
                || $order->country !== 'FR' || ($session['metadata']['storefront_code'] ?? '') !== 'gpswiss_fr'
                || ($session['metadata']['payment_provider'] ?? '') !== 'stripe'
                || ($session['client_reference_id'] ?? '') !== (string) $order->id
                || ($session['mode'] ?? '') !== 'payment' || ($session['currency'] ?? '') !== 'eur'
                || ($event['livemode'] ?? null) !== (config('stripe-fr.mode') === 'live')
                || ($session['livemode'] ?? null) !== ($event['livemode'] ?? null)
                || data_get($order->meta, 'stripe.mode') !== config('stripe-fr.mode')
                || ! is_int($session['amount_total'] ?? null)
                || $session['amount_total'] !== data_get($order->meta, 'stripe.expected_total_cents')
                || $session['amount_total'] !== (int) round((float) $order->total * 100)) {
                $this->reject();
            }
            $attempt = DB::table('stripe_fr_attempts')->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $attempt || ($session['metadata']['checkout_key'] ?? '') !== $attempt->checkout_key
                || ! str_starts_with((string) ($session['id'] ?? ''), config('stripe-fr.mode') === 'test' ? 'cs_test_' : 'cs_live_')
                || ($attempt->session_id && $attempt->session_id !== $session['id'])) {
                $this->reject();
            }
            // Check after order lock: concurrent duplicate deliveries serialize on the same order.
            $processed = DB::table('stripe_fr_events')->where('event_id', $eventId)->first();
            if ($processed) {
                if ((int) $processed->order_id !== $order->id) {
                    $this->reject();
                }

                return $order->id;
            }
            if ($type === 'checkout.session.completed') {
                if (($session['status'] ?? '') !== 'complete' || ($session['payment_status'] ?? '') !== 'paid'
                    || ! is_string($session['payment_intent'] ?? null) || ! str_starts_with($session['payment_intent'], 'pi_')) {
                    $this->reject();
                }
                if ($order->payment_status !== 'paid') {
                    if ($order->payment_status !== 'pending' || $order->status !== 'new') {
                        $this->reject();
                    }
                    $item = $order->items()->sole();
                    if ((int) $item->quantity !== 1 || $item->currency !== 'EUR' || (int) $item->part_id !== (int) $attempt->part_id) {
                        $this->reject();
                    }
                    $jarek = $attempt->jarek_fr_product_id !== null;
                    if ($jarek && ($item->part_id !== null || $attempt->part_id !== null
                        || (int) $item->jarek_fr_product_id !== (int) $attempt->jarek_fr_product_id
                        || data_get($item->meta, 'source_type') !== 'jarek_gearbox'
                        || ($session['metadata']['source_type'] ?? '') !== 'jarek_gearbox'
                        || ($session['metadata']['jarek_fr_product_id'] ?? '') !== (string) $attempt->jarek_fr_product_id
                        || ($session['metadata']['jarek_gearbox_id'] ?? '') !== (string) data_get($item->meta, 'source_id'))) {
                        $this->reject();
                    }
                    if (! $jarek && ($item->jarek_fr_product_id !== null || data_get($item->meta, 'source_type') === 'jarek_gearbox')) {
                        $this->reject();
                    }
                    $part = $jarek ? null : Part::query()->whereKey($item->part_id)->lockForUpdate()->first();
                    $stockAvailable = $jarek || ($part && $part->isAvailableForLocalSale());
                    $live = config('stripe-fr.mode') === 'live';
                    $meta = $order->meta;
                    $meta['stripe']['payment_intent'] = $session['payment_intent'];
                    $meta['stripe']['session_id'] = $session['id'];
                    $meta['stripe']['paid_at'] = now()->toISOString();
                    $meta['stripe']['stock_applied'] = ! $jarek && $live && $stockAvailable;
                    if ($jarek) {
                        $meta['stripe']['stock_behavior'] = 'supplier_virtual_no_decrement';
                    }
                    if (! $jarek && $live && $stockAvailable) {
                        // Local one-unit fulfillment only; never call marketplace sold()/sync here.
                        $values = ['quantity' => (int) $part->quantity - 1];
                        if ($values['quantity'] === 0) {
                            $values += ['status' => 'sold', 'is_visible_storefront' => false,
                                'sale_source' => 'gpswiss_fr', 'sold_at' => now()];
                        }
                        // Bypass Part::saving: it recomputes eBay prices even on stock-only saves.
                        Part::query()->whereKey($part->id)->update($values + ['updated_at' => now()]);
                    } elseif (! $live || ! $stockAvailable) {
                        $meta['stripe']['review_reason'] = $live ? 'stock_conflict_after_payment' : 'test_payment_no_fulfillment';
                    }
                    $order->update(['payment_status' => 'paid', 'status' => $live && $stockAvailable ? 'processing' : 'on_hold',
                        'status_changed_at' => now(), 'meta' => $meta]);
                } elseif (data_get($order->meta, 'stripe.payment_intent') !== $session['payment_intent']) {
                    $this->reject();
                }
                $this->notifications->record($order, 'paid');
                $status = 'paid';
            } else {
                if (($session['status'] ?? '') !== 'expired') {
                    $this->reject();
                }
                if ($order->payment_status === 'pending' && $order->status === 'new') {
                    $order->update(['payment_status' => 'failed', 'status' => 'cancelled', 'status_changed_at' => now()]);
                    $this->notifications->record($order, 'expired');
                }
                $status = $order->payment_status === 'paid' ? 'paid' : 'expired';
            }
            DB::table('stripe_fr_attempts')->where('checkout_key', $attempt->checkout_key)->update([
                'session_id' => $session['id'], 'status' => $status, 'updated_at' => now(),
            ]);
            DB::table('stripe_fr_events')->insert(['event_id' => $eventId, 'event_type' => $type,
                'order_id' => $order->id, 'processed_at' => now()]);

            return $order->id;
        });
        $this->notifications->deliver($orderId);
    }

    private function reject(): never
    {
        throw new HttpException(422, 'Stripe FR event does not match the order.');
    }
}
