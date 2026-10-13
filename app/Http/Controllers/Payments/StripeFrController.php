<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\StripeFrGateway;
use App\Services\Payments\StripeFrWebhook;
use App\Support\StorefrontContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use JsonException;

class StripeFrController extends Controller
{
    public function returned(Request $request, Order $order): Response
    {
        abort_unless(StorefrontContext::isFrench($request) && $order->isFrenchStorefront()
            && $order->paymentProvider() === 'stripe', 404);

        // Signed access only, no remote lookup and no payment/order mutation.
        $attempt = DB::table('stripe_fr_attempts')->where('order_id', $order->id)->first();
        $retryUrl = $order->status === 'new' && $order->payment_status === 'pending'
            && $attempt && $attempt->status === 'open' && now()->lessThan($attempt->expires_at)
            ? $attempt->checkout_url : null;

        return response()->view('storefront.fr.stripe-return', [
            'retryUrl' => $retryUrl,
            'order' => $order, 'metaTitle' => 'Paiement — GPSwiss France',
            'cancelled' => $request->routeIs('storefront.fr.stripe.cancel', 'storefront.fr-www.stripe.cancel'),
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function webhook(Request $request, StripeFrGateway $stripe, StripeFrWebhook $events): Response
    {
        abort_unless($request->getHost() === 'gpswiss.fr', 404);
        $body = $request->getContent();
        abort_if(strlen($body) > 512000, 413);
        abort_unless($stripe->verifyWebhook($body, (string) $request->header('Stripe-Signature')), 400);
        try {
            $event = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400);
        }
        abort_unless(is_array($event), 400);
        $events->handle($event);

        return response('OK', 200);
    }
}
