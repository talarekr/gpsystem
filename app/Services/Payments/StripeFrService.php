<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class StripeFrService
{
    public function isAvailable(): bool
    {
        return config('stripe_fr.enabled') === true
            && config('stripe_fr.mode') === 'test'
            && preg_match('/^sk_test_[A-Za-z0-9_]+$/D', (string) config('stripe_fr.secret_key')) === 1
            && preg_match('/^pk_test_[A-Za-z0-9_]+$/D', (string) config('stripe_fr.publishable_key')) === 1
            && preg_match('/^whsec_[A-Za-z0-9_]+$/D', (string) config('stripe_fr.webhook_secret')) === 1;
    }

    /** @return array{url: string} */
    public function createCheckoutSession(Order $order): array
    {
        try {
            return $this->createSession($order);
        } catch (InvalidArgumentException) {
            throw new RuntimeException('Stripe Checkout is unavailable for this order.');
        }
    }

    private function createSession(Order $order): array
    {
        $this->assertAvailable();

        // Persist the receipt capability and amount before the remote request. A
        // retry uses the same payload and idempotency key, even after a timeout.
        $prepared = DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertStorefrontOrder($locked);
            if ($locked->payment_status === 'paid') {
                throw new InvalidArgumentException('This order has already been paid.');
            }

            $meta = $locked->meta ?? [];
            $stripe = $meta['stripe'] ?? [];
            if (! is_array($stripe) || (isset($stripe['return_token'])
                && (! is_string($stripe['return_token']) || ! preg_match('/^[A-Za-z0-9]{64}$/D', $stripe['return_token'])))) {
                throw new InvalidArgumentException('Invalid payment metadata.');
            }
            $amount = $this->amountMinor($locked);
            $currency = $this->currency($locked);
            if ((isset($stripe['amount_minor']) && $stripe['amount_minor'] !== $amount)
                || (isset($stripe['currency']) && $stripe['currency'] !== $currency)) {
                throw new InvalidArgumentException('The payment amount has changed.');
            }

            $meta['stripe'] = array_merge($stripe, [
                'environment' => 'test',
                'test' => true,
                'livemode' => false,
                'amount_minor' => $amount,
                'currency' => $currency,
                'return_token' => $stripe['return_token'] ?? Str::random(64),
                'event_ids' => $stripe['event_ids'] ?? [],
            ]);
            $locked->update(['meta' => $meta]);

            return $locked;
        });

        $sessionId = data_get($prepared->meta, 'stripe.session_id');
        $session = $sessionId
            ? $this->retrieveCheckoutSession((string) $sessionId)
            : $this->requestSession($prepared);
        $this->assertSession($session, $prepared);
        $url = $session['url'] ?? null;
        if (! is_string($url) || strlen($url) > 4096
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'checkout.stripe.com'
            || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException('Stripe Checkout is temporarily unavailable.');
        }

        // Read a fresh snapshot under a lock: a webhook may have updated meta
        // while Stripe answered, and must not be overwritten by this response.
        DB::transaction(function () use ($prepared, $session): void {
            $locked = Order::query()->whereKey($prepared->id)->lockForUpdate()->firstOrFail();
            $this->assertStorefrontOrder($locked);
            $this->assertSession($session, $locked);
            $meta = $locked->meta ?? [];
            $stripe = $meta['stripe'];
            if (filled($stripe['session_id'] ?? null) && $stripe['session_id'] !== $session['id']) {
                throw new InvalidArgumentException('The payment session does not match.');
            }
            $intent = $this->paymentIntentId($session['payment_intent'] ?? null);
            if (filled($stripe['payment_intent_id'] ?? null) && $intent !== null
                && $stripe['payment_intent_id'] !== $intent) {
                throw new InvalidArgumentException('The payment intent does not match.');
            }
            $stripe['session_id'] = $session['id'];
            $stripe['payment_intent_id'] = $intent ?? ($stripe['payment_intent_id'] ?? null);
            $stripe['created_at'] ??= now()->toIso8601String();
            $meta['stripe'] = $stripe;
            $locked->update(['meta' => $meta]);
        });

        $order->refresh();

        return ['url' => $url];
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $this->assertAvailable();
        if (! preg_match('/^cs_test_[A-Za-z0-9_]+$/D', $sessionId)) {
            throw new InvalidArgumentException('Invalid payment session.');
        }
        try {
            $response = $this->http()->get('/checkout/sessions/'.rawurlencode($sessionId));
        } catch (Throwable) {
            throw new RuntimeException('Stripe Checkout is temporarily unavailable.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Stripe Checkout is temporarily unavailable.');
        }

        return $response->json();
    }

    public function verifySignature(string $body, ?string $header): bool
    {
        $secret = (string) config('stripe_fr.webhook_secret');
        if ($header === null || ! str_starts_with($secret, 'whsec_')) {
            return false;
        }
        $timestamps = [];
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamps[] = $value;
            } elseif ($key === 'v1' && preg_match('/^[a-fA-F0-9]{64}$/D', $value)) {
                $signatures[] = strtolower($value);
            }
        }
        if (count($timestamps) !== 1 || ! preg_match('/^[0-9]{1,12}$/D', $timestamps[0])
            || abs(now()->getTimestamp() - (int) $timestamps[0]) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamps[0].'.'.$body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function assertStorefrontOrder(Order $order): void
    {
        if (data_get($order->meta, 'source') !== 'storefront'
            || data_get($order->meta, 'payment_provider') !== 'stripe'
            || data_get($order->meta, 'storefront_code') !== 'gpswiss_fr'
            || filled($order->marketplace)) {
            throw new InvalidArgumentException('This order does not belong to Stripe FR.');
        }
    }

    public function assertMetadata(mixed $metadata, Order $order): void
    {
        if (! is_array($metadata)
            || ($metadata['order_id'] ?? null) !== (string) $order->id
            || ($metadata['storefront_code'] ?? null) !== 'gpswiss_fr'
            || ($metadata['payment_provider'] ?? null) !== 'stripe'
            || ($metadata['environment'] ?? null) !== 'test') {
            throw new InvalidArgumentException('The payment metadata does not match.');
        }
    }

    public function assertSession(array $session, Order $order): void
    {
        $this->assertMetadata($session['metadata'] ?? null, $order);
        if (! is_string($session['id'] ?? null)
            || ! preg_match('/^cs_test_[A-Za-z0-9_]+$/D', $session['id'])
            || ($session['livemode'] ?? null) !== false
            || ($session['object'] ?? null) !== 'checkout.session'
            || ($session['mode'] ?? null) !== 'payment'
            || ($session['client_reference_id'] ?? null) !== (string) $order->id
            || ($session['amount_total'] ?? null) !== $this->amountMinor($order)
            || ($session['currency'] ?? null) !== $this->currency($order)) {
            throw new InvalidArgumentException('The payment session does not match.');
        }
        $storedId = data_get($order->meta, 'stripe.session_id');
        if (filled($storedId) && $storedId !== $session['id']) {
            throw new InvalidArgumentException('The payment session does not match.');
        }
        $this->paymentIntentId($session['payment_intent'] ?? null);
    }

    public function paymentIntentId(mixed $intent): ?string
    {
        if ($intent === null) {
            return null;
        }
        if (! is_string($intent) || ! preg_match('/^pi_[A-Za-z0-9_]+$/D', $intent)) {
            throw new InvalidArgumentException('Invalid payment intent.');
        }

        return $intent;
    }

    public function amountMinor(Order $order): int
    {
        $amount = (string) $order->total;
        if (! preg_match('/^([0-9]{1,10})\.([0-9]{2})$/D', $amount, $matches)) {
            throw new InvalidArgumentException('Invalid payment amount.');
        }
        $minor = (int) $matches[1] * 100 + (int) $matches[2];
        if ($minor < 1) {
            throw new InvalidArgumentException('Invalid payment amount.');
        }

        return $minor;
    }

    public function currency(Order $order): string
    {
        $currency = strtolower((string) $order->currency);
        if (! in_array($currency, ['eur', 'pln'], true)) {
            throw new InvalidArgumentException('Unsupported payment currency.');
        }

        return $currency;
    }

    private function requestSession(Order $order): array
    {
        $metadata = [
            'order_id' => (string) $order->id,
            'storefront_code' => 'gpswiss_fr',
            'payment_provider' => 'stripe',
            'environment' => 'test',
        ];
        $token = rawurlencode((string) data_get($order->meta, 'stripe.return_token'));
        $payload = [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'locale' => 'fr',
            'client_reference_id' => (string) $order->id,
            'success_url' => 'https://gpswiss.fr/stripe/fr/success/'.$order->id.'?token='.$token,
            'cancel_url' => 'https://gpswiss.fr/stripe/fr/cancel/'.$order->id.'?token='.$token,
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $this->currency($order),
                    'unit_amount' => $this->amountMinor($order),
                    'product_data' => ['name' => 'Commande GPSwiss #'.$order->order_number],
                ],
            ]],
        ];
        try {
            $response = $this->http()->asForm()
                ->withHeaders(['Idempotency-Key' => 'gpswiss-fr-test-'.$order->id.'-'.hash('sha256', $order->order_number)])
                ->post('/checkout/sessions', $payload);
        } catch (Throwable) {
            throw new RuntimeException('Stripe Checkout is temporarily unavailable.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Stripe Checkout is temporarily unavailable.');
        }

        return $response->json();
    }

    private function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Stripe Checkout is unavailable.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com/v1')
            ->withToken((string) config('stripe_fr.secret_key'))
            ->withHeaders(['Stripe-Version' => '2024-06-20'])
            ->acceptJson()->withoutRedirecting()->timeout(15);
    }
}
