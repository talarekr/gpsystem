<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\StorefrontContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FrenchOrderController extends Controller
{
    public function show(Request $request, Order $order): Response
    {
        abort_unless(StorefrontContext::isFrench($request) && $order->isFrenchStorefront()
            && $order->paymentProvider() === 'stripe' && $order->currency === 'EUR', 404);

        return response()->view('storefront.fr.order', [
            'order' => $order->load('items'), 'metaTitle' => 'Votre commande — GPSwiss.fr',
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
