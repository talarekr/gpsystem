<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class StripeFrGateway
{
    public function ready(): bool
    {
        $mode = config('stripe-fr.mode');
        $prefix = $mode === 'test' ? 'test' : 'live';
        $public = (string) config('stripe-fr.publishable_key');

        return config('stripe-fr.enabled') && in_array($mode, ['test', 'live'], true)
            && str_starts_with((string) config('stripe-fr.secret_key'), 'sk_'.$prefix.'_')
            && str_starts_with((string) config('stripe-fr.webhook_secret'), 'whsec_')
            && ($public === '' || str_starts_with($public, 'pk_'.$prefix.'_'))
            && Schema::hasTable('stripe_fr_attempts') && Schema::hasTable('stripe_fr_events');
    }

    public function createSession(array $payload, string $key): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('Stripe FR unavailable.');
        }
        // No automatic retry with a different key: uncertain responses retain this attempt.
        $response = Http::withToken(config('stripe-fr.secret_key'))->asForm()->acceptJson()
            ->withHeaders(['Idempotency-Key' => 'gpswiss-fr-'.$key, 'Stripe-Version' => '2025-06-30.basil'])
            ->connectTimeout(5)->timeout(15)->post('https://api.stripe.com/v1/checkout/sessions', $payload);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Stripe FR session could not be confirmed.');
        }

        return $response->json();
    }

    public function verifyWebhook(string $body, string $header): bool
    {
        $secret = (string) config('stripe-fr.webhook_secret');
        if (! str_starts_with($secret, 'whsec_')) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }
            if ($name === 'v1') {
                $signatures[] = $value;
            }
        }
        if (! $timestamp || abs(now()->timestamp - $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
