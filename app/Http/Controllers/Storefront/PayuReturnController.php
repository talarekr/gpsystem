<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PayuService;
use App\Support\StorefrontContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class PayuReturnController extends Controller
{
    public function __invoke(Request $request, PayuService $payu): View
    {
        if (StorefrontContext::isFrench($request)) {
            return view('storefront.checkout.unavailable');
        }

        $orderId = $request->integer('order');
        $sessionAccess = (int) $request->session()->get('storefront.order_access.'.$orderId, 0) > now()->timestamp;
        // Older or unrelated sessions see only the generic return page.
        // Never query the provider for an untrusted numeric order ID.
        $order = ($sessionAccess || $request->hasValidSignature())
            ? Order::query()->find($orderId) : null;
        $remoteStatus = null;

        if ($order && ($payuOrderId = data_get($order->meta, 'payu.order_id'))) {
            try {
                $remoteStatus = data_get($payu->getOrder((string) $payuOrderId), 'orders.0.status');
            } catch (Throwable) {
                $remoteStatus = null;
            }
        }

        return view('storefront.checkout.payu-return', [
            'order' => $order,
            'remoteStatus' => $remoteStatus,
            'breadcrumbs' => [['label' => __('storefront.home'), 'url' => route('storefront.home')], ['label' => 'Powrót z PayU']],
            'metaTitle' => 'Potwierdzenie płatności PayU - GPSwiss',
            'metaDescription' => 'Oczekiwanie na potwierdzenie płatności PayU.',
        ]);
    }
}
