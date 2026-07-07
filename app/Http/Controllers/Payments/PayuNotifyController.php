<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PayuService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

class PayuNotifyController extends Controller
{
    public function __invoke(Request $request, PayuService $payu): Response
    {
        $body = $request->getContent();
        if (! $payu->verifySignature($body, $request->header('OpenPayu-Signature'))) {
            return response('Invalid signature', 401);
        }

        $payload = json_decode($body, true) ?: [];
        $payuOrder = Arr::get($payload, 'order', []);
        $payuOrderId = (string) Arr::get($payuOrder, 'orderId', '');
        $extOrderId = (string) Arr::get($payuOrder, 'extOrderId', '');
        $status = strtoupper((string) Arr::get($payuOrder, 'status', ''));

        $order = Order::query()
            ->where('meta->payu->order_id', $payuOrderId)
            ->when($extOrderId !== '', fn ($query) => $query->orWhere('meta->payu->ext_order_id', $extOrderId))
            ->first();

        if ($order) {
            $meta = $order->meta ?? [];
            data_set($meta, 'payu.last_notification', [
                'received_at' => now()->toIso8601String(),
                'status' => $status,
                'order_id' => $payuOrderId,
                'ext_order_id' => $extOrderId,
                'payload' => $payload,
            ]);

            $updates = ['meta' => $meta, 'marketplace_status' => $status ?: $order->marketplace_status];
            if ($status === 'COMPLETED') {
                $updates['payment_status'] = 'paid';
                $updates['status'] = $order->status === 'new' ? 'processing' : $order->status;
                $updates['status_changed_at'] = $order->status === 'new' ? now() : $order->status_changed_at;
            } elseif (in_array($status, ['CANCELED', 'CANCELLED'], true)) {
                $updates['payment_status'] = 'failed';
            } elseif (in_array($status, ['PENDING', 'WAITING_FOR_CONFIRMATION'], true)) {
                $updates['payment_status'] = $order->payment_status ?: 'pending';
            }
            $order->update($updates);
        }

        return response('OK', 200);
    }
}
