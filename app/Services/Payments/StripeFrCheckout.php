<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Part;
use App\Services\Storefront\CartService;
use App\Services\Storefront\FrenchCheckoutService;
use App\Services\Storefront\FrenchOrderNotifications;
use App\Support\StorefrontContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StripeFrCheckout
{
    public function __construct(private FrenchCheckoutService $drafts, private StripeFrGateway $stripe, private CartService $cart, private FrenchOrderNotifications $notifications) {}

    public function start(Request $request): string
    {
        if (! StorefrontContext::isFrench($request) || app()->getLocale() !== 'fr' || ! $this->stripe->ready()) {
            $this->unavailable();
        }
        $input = $request->validate(['checkout_key' => ['required', 'uuid'], 'terms' => ['accepted']]);
        $key = $input['checkout_key'];
        if (! hash_equals((string) $request->session()->get('storefront.fr.checkout_key', ''), $key)) {
            $this->unavailable();
        }
        try {
            return Cache::lock('stripe:fr:checkout:'.$key, 60)->block(1, function () use ($request, $key): string {
                $attempt = DB::table('stripe_fr_attempts')->where('checkout_key', $key)->first();
                if (! $attempt) {
                    $draft = $this->drafts->draft($request);
                    $this->requireDraft($draft, $key);
                    $attempt = DB::transaction(function () use ($request, $draft, $key) {
                        $part = Part::query()->whereKey($draft['items'][0]['part_id'])->lockForUpdate()->first();
                        if (! $part || ! $part->isAvailableForLocalSale()) {
                            $this->unavailable();
                        }
                        // Re-read price, category quote and address, never use posted totals.
                        $fresh = $this->drafts->draft($request);
                        $this->requireDraft($fresh, $key);
                        $held = DB::table('stripe_fr_attempts')->where('part_id', $part->id)
                            ->whereIn('status', ['creating', 'open'])->where('expires_at', '>', now())->count();
                        if ($held >= (int) $part->quantity) {
                            $this->unavailable();
                        }
                        $expires = now()->addMinutes(35);
                        $attributes = $fresh['order_attributes'];
                        $attributes['meta']['stripe'] = ['mode' => config('stripe-fr.mode'),
                            'checkout_key' => $key, 'expected_total_cents' => $fresh['total_cents'],
                            'stock_applied' => false, 'terms_accepted_at' => now()->toISOString()];
                        $order = Order::create($attributes + [
                            'order_number' => 'GPS-FR-'.Str::uuid(), 'customer_id' => $request->user()?->id, 'ordered_at' => now(),
                        ]);
                        $order->items()->create($fresh['order_items'][0]);
                        $metadata = ['order_id' => (string) $order->id, 'checkout_key' => $key,
                            'storefront_code' => 'gpswiss_fr', 'payment_provider' => 'stripe'];
                        $success = URL::temporarySignedRoute('storefront.fr.stripe.return', now()->addDays(7), ['order' => $order->id]);
                        $cancel = URL::temporarySignedRoute('storefront.fr.stripe.cancel', now()->addDays(7), ['order' => $order->id]);
                        $payload = [
                            'mode' => 'payment', 'locale' => 'fr', 'payment_method_types' => ['card'],
                            'customer_email' => $order->email, 'client_reference_id' => (string) $order->id,
                            'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata],
                            'success_url' => $success, 'cancel_url' => $cancel, 'expires_at' => $expires->timestamp,
                            'adaptive_pricing' => ['enabled' => 'false'], 'allow_promotion_codes' => 'false',
                            'automatic_tax' => ['enabled' => 'false'],
                            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur',
                                'unit_amount' => $fresh['subtotal_cents'], 'product_data' => ['name' => $fresh['items'][0]['product_name']]]]],
                            'shipping_options' => [['shipping_rate_data' => ['type' => 'fixed_amount',
                                'fixed_amount' => ['amount' => $fresh['shipping_cents'], 'currency' => 'eur'],
                                'display_name' => 'Livraison par transporteur en France']]],
                        ];
                        DB::table('stripe_fr_attempts')->insert(['checkout_key' => $key, 'order_id' => $order->id,
                            'part_id' => $part->id, 'status' => 'creating', 'expires_at' => $expires->copy()->addMinutes(5),
                            'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

                        return DB::table('stripe_fr_attempts')->where('checkout_key', $key)->first();
                    });
                }
                $order = Order::findOrFail($attempt->order_id);
                if (! $order->isFrenchStorefront() || $order->paymentProvider() !== 'stripe'
                    || $order->currency !== 'EUR' || data_get($order->meta, 'locale') !== 'fr'
                    || data_get($order->meta, 'stripe.mode') !== config('stripe-fr.mode')) {
                    $this->unavailable();
                }
                if ($order->payment_status === 'paid') {
                    return URL::temporarySignedRoute('storefront.fr.stripe.return', now()->addDays(7), ['order' => $order->id]);
                }
                if ($order->status !== 'new' || $order->payment_status !== 'pending'
                    || (int) round((float) $order->total * 100) !== data_get($order->meta, 'stripe.expected_total_cents')
                    || ! in_array($attempt->status, ['creating', 'open'], true) || now()->greaterThanOrEqualTo($attempt->expires_at)) {
                    $this->unavailable();
                }
                if ($attempt->checkout_url) {
                    $this->notifications->awaiting($order);

                    return $attempt->checkout_url;
                }
                try {
                    $session = $this->stripe->createSession(json_decode($attempt->request_payload, true, 512, JSON_THROW_ON_ERROR), $key);
                    $prefix = config('stripe-fr.mode') === 'test' ? 'cs_test_' : 'cs_live_';
                    if (! str_starts_with((string) ($session['id'] ?? ''), $prefix)
                        || ($session['object'] ?? '') !== 'checkout.session' || ($session['mode'] ?? '') !== 'payment'
                        || ($session['livemode'] ?? null) !== (config('stripe-fr.mode') === 'live')
                        || ($session['client_reference_id'] ?? '') !== (string) $order->id
                        || ($session['metadata']['checkout_key'] ?? '') !== $key
                        || ($session['currency'] ?? '') !== 'eur' || ($session['amount_total'] ?? null) !== data_get($order->meta, 'stripe.expected_total_cents')
                        || parse_url((string) ($session['url'] ?? ''), PHP_URL_SCHEME) !== 'https'
                        || parse_url((string) ($session['url'] ?? ''), PHP_URL_HOST) !== 'checkout.stripe.com') {
                        $this->unavailable();
                    }
                    DB::transaction(function () use ($key, $session): void {
                        $row = DB::table('stripe_fr_attempts')->where('checkout_key', $key)->lockForUpdate()->first();
                        if ($row->session_id && $row->session_id !== $session['id']) {
                            $this->unavailable();
                        }
                        DB::table('stripe_fr_attempts')->where('checkout_key', $key)->update([
                            'session_id' => $session['id'], 'checkout_url' => $session['url'],
                            'status' => $row->status === 'creating' ? 'open' : $row->status, 'updated_at' => now(),
                        ]);
                    });
                } catch (Throwable) {
                    // Preserve attempt + immutable payload/idempotency key after uncertain network errors.
                    $this->unavailable('Le paiement n’a pas pu être confirmé. Réessayez depuis cette page; aucune nouvelle tentative indépendante ne sera créée.');
                }
                $this->notifications->awaiting($order);
                $this->cart->clear();

                return $session['url'];
            });
        } catch (LockTimeoutException) {
            $this->unavailable('Une tentative est déjà en cours. Veuillez patienter.');
        }
    }

    private function requireDraft(array $draft, string $key): void
    {
        if (! $draft['can_place_order'] || ! is_int($draft['total_cents'])
            || $draft['total_cents'] < 50 || $draft['total_cents'] > 99999999 || $draft['currency'] !== 'EUR' || $draft['storefront_code'] !== 'gpswiss_fr'
            || $draft['locale'] !== 'fr' || $draft['payment_provider'] !== 'stripe' || $draft['checkout_key'] !== $key) {
            $this->unavailable('Vérifiez votre adresse, le prix EUR et la livraison. Une seule pièce, en quantité un, est autorisée.');
        }
    }

    private function unavailable(string $message = 'Les paiements de la boutique française sont temporairement indisponibles.'): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
