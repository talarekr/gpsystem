<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PaymentProviderResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StripeFrReturnController extends Controller
{
    public function success(Request $request, Order $order, PaymentProviderResolver $providers): Response
    {
        return $this->receipt($request, $order, $providers, false);
    }

    public function cancel(Request $request, Order $order, PaymentProviderResolver $providers): Response
    {
        return $this->receipt($request, $order, $providers, true);
    }

    private function receipt(Request $request, Order $order, PaymentProviderResolver $providers, bool $cancelled): Response
    {
        $token = $request->query('token');
        $expected = data_get($order->meta, 'stripe.return_token');
        abort_unless(
            $providers->resolve($request) === 'stripe'
            && data_get($order->meta, 'source') === 'storefront'
            && data_get($order->meta, 'payment_provider') === 'stripe'
            && data_get($order->meta, 'storefront_code') === 'gpswiss_fr'
            && is_string($token) && is_string($expected) && strlen($expected) === 64
            && hash_equals($expected, $token),
            404,
        );

        // A browser return only reads local state; the signed webhook confirms payment.
        return response()->view('storefront.checkout.stripe-fr-return', [
            'order' => $order,
            'cancelled' => $cancelled,
            'metaTitle' => 'Confirmation du paiement',
            'metaDescription' => 'Confirmation de votre commande.',
            'breadcrumbs' => [['label' => 'Accueil', 'url' => 'https://gpswiss.fr'], ['label' => 'Paiement']],
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
