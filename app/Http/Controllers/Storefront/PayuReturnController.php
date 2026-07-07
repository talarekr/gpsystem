<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PayuService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class PayuReturnController extends Controller
{
    public function __invoke(Request $request, PayuService $payu): View
    {
        $order = Order::query()->find($request->integer('order'));
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
            'breadcrumbs' => [['label' => 'Strona główna', 'url' => route('storefront.home')], ['label' => 'Powrót z PayU']],
            'metaTitle' => 'Potwierdzenie płatności PayU - GPSwiss',
            'metaDescription' => 'Oczekiwanie na potwierdzenie płatności PayU.',
        ]);
    }
}
