<?php

namespace App\Services\Storefront;

use App\Mail\FrenchOrderMail;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class FrenchOrderNotifications
{
    /** Must run inside the transaction which confirms the corresponding order state. */
    public function record(Order $order, string $kind): void
    {
        if (! $this->eligible($order) || ! in_array($kind, ['awaiting', 'paid', 'expired'], true)) {
            return;
        }
        DB::table('storefront_fr_order_notifications')->insertOrIgnore([
            'order_id' => $order->id, 'kind' => $kind, 'state' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function awaiting(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status === 'new' && $fresh->payment_status === 'pending') {
                $this->record($fresh, 'awaiting');
            }
        });
        $this->deliver($order->id);
    }

    /** Synchronous: no worker required. Never send while the payment transaction is open. */
    public function deliver(int $orderId): void
    {
        DB::afterCommit(fn () => $this->deliverCommitted($orderId));
    }

    private function deliverCommitted(int $orderId): void
    {
        foreach (DB::table('storefront_fr_order_notifications')->where('order_id', $orderId)
            ->where('state', 'pending')->orderBy('id')->get() as $notification) {
            $order = DB::transaction(function () use ($orderId, $notification) {
                // Same lock order as Stripe webhook; duplicates serialize on the order.
                $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
                $row = DB::table('storefront_fr_order_notifications')->where('id', $notification->id)->lockForUpdate()->first();
                if (! $row || $row->state !== 'pending') {
                    return null;
                }
                $validState = $order && match ($row->kind) {
                    'paid' => $order->payment_status === 'paid',
                    'awaiting' => $order->status === 'new' && $order->payment_status === 'pending',
                    'expired' => $order->status === 'cancelled' && $order->payment_status === 'failed',
                    default => false,
                };
                if (! $order || ! $this->eligible($order) || ! $validState) {
                    DB::table('storefront_fr_order_notifications')->where('id', $row->id)
                        ->update(['state' => 'suppressed', 'updated_at' => now()]);

                    return null;
                }
                DB::table('storefront_fr_order_notifications')->where('id', $row->id)
                    ->update(['state' => 'sending', 'claimed_at' => now(), 'transport' => config('mail.default'), 'updated_at' => now()]);

                return $order->load('items');
            });
            if (! $order) {
                continue;
            }
            try {
                Mail::to($order->email)->send(new FrenchOrderMail($order, $notification->kind));
                DB::table('storefront_fr_order_notifications')->where('id', $notification->id)
                    ->update(['state' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            } catch (Throwable $exception) {
                // SMTP acceptance may be uncertain: never blindly resend a claimed message.
                DB::table('storefront_fr_order_notifications')->where('id', $notification->id)
                    ->update(['state' => 'uncertain', 'updated_at' => now()]);
                Log::error('FR order email delivery uncertain; check transport before any manual resend.', [
                    'order_id' => $orderId, 'notification_id' => $notification->id,
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }

    private function eligible(Order $order): bool
    {
        return $order->isFrenchStorefront() && $order->paymentProvider() === 'stripe'
            && $order->currency === 'EUR' && data_get($order->meta, 'locale') === 'fr';
    }
}
