<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PayuService
{
    public function configForDiagnostics(): array
    {
        return [
            'env' => config('payu.env'),
            'base_url' => $this->baseUrl(),
            'client_id_configured' => filled(config('payu.client_id')),
            'client_secret_configured' => filled(config('payu.client_secret')),
            'merchant_pos_id_configured' => filled(config('payu.merchant_pos_id')),
            'second_key_configured' => filled(config('payu.second_key')),
            'currency' => config('payu.currency'),
            'continue_url' => config('payu.continue_url'),
            'notify_url' => config('payu.notify_url'),
        ];
    }

    public function token(): string
    {
        return Cache::remember($this->tokenCacheKey(), now()->addMinutes(4), function (): string {
            $response = $this->http()->asForm()->post('/pl/standard/user/oauth/authorize', [
                'grant_type' => 'client_credentials',
                'client_id' => config('payu.client_id'),
                'client_secret' => config('payu.client_secret'),
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('PayU OAuth failed: HTTP '.$response->status());
            }

            $json = $response->json();
            $token = (string) ($json['access_token'] ?? '');
            $expiresIn = max(60, (int) ($json['expires_in'] ?? 300) - 60);

            if ($token === '') {
                throw new RuntimeException('PayU OAuth response does not contain access_token.');
            }

            Cache::put($this->tokenCacheKey(), $token, now()->addSeconds($expiresIn));

            return $token;
        });
    }

    public function createOrder(Order $order, string $customerIp): array
    {
        $payload = $this->orderPayload($order, $customerIp);
        $response = $this->authorizedHttp()->withoutRedirecting()->post('/api/v2_1/orders', $payload);
        $json = $response->json() ?? [];

        if (! in_array($response->status(), [200, 201, 302], true)) {
            throw new RuntimeException('PayU create order failed: HTTP '.$response->status().' '.$response->body());
        }

        $redirectUri = (string) ($json['redirectUri'] ?? $response->header('Location', ''));
        if ($redirectUri === '') {
            throw new RuntimeException('PayU create order response does not contain redirectUri.');
        }

        return ['payload' => $payload, 'response' => $json, 'redirectUri' => $redirectUri, 'status' => $response->status()];
    }

    public function getOrder(string $payuOrderId): array
    {
        $response = $this->authorizedHttp()->get('/api/v2_1/orders/'.rawurlencode($payuOrderId));

        if (! $response->successful()) {
            throw new RuntimeException('PayU get order failed: HTTP '.$response->status());
        }

        return $response->json() ?? [];
    }

    public function orderPayload(Order $order, string $customerIp = '127.0.0.1'): array
    {
        $order->loadMissing('items');
        $nameParts = preg_split('/\s+/', trim($order->customer_name), 2) ?: [];

        return [
            'notifyUrl' => config('payu.notify_url'),
            'continueUrl' => config('payu.continue_url').'?order='.$order->id,
            'customerIp' => $customerIp,
            'merchantPosId' => (string) config('payu.merchant_pos_id'),
            'description' => 'Zamówienie GPSWISS #'.$order->order_number,
            'currencyCode' => config('payu.currency', 'PLN'),
            'totalAmount' => $this->amountToMinor($order->total),
            'extOrderId' => $this->extOrderId($order),
            'buyer' => [
                'email' => $order->email,
                'phone' => $order->phone,
                'firstName' => $nameParts[0] ?? $order->customer_name,
                'lastName' => $nameParts[1] ?? '',
                'language' => 'pl',
            ],
            'products' => $order->items->map(fn ($item): array => [
                'name' => Str::limit((string) $item->product_name, 255, ''),
                'unitPrice' => $this->amountToMinor($item->unit_price),
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ];
    }

    public function verifySignature(string $body, ?string $signatureHeader): bool
    {
        if (! $signatureHeader || ! config('payu.second_key')) return false;
        parse_str(str_replace(';', '&', $signatureHeader), $parts);
        $signature = (string) ($parts['signature'] ?? '');
        $algorithm = strtolower((string) ($parts['algorithm'] ?? 'md5'));
        if ($signature === '' || $algorithm !== 'md5') return false;
        return hash_equals($signature, md5($body.config('payu.second_key')));
    }

    public function extOrderId(Order $order): string
    {
        return (string) data_get($order->meta, 'payu.ext_order_id', $order->order_number.'-'.$order->id);
    }

    private function authorizedHttp(): PendingRequest
    {
        return $this->http()->withToken($this->token())->acceptJson();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())->timeout((int) config('payu.timeout', 15));
    }

    private function baseUrl(): string
    {
        return config('payu.base_urls.'.config('payu.env'), config('payu.base_urls.sandbox'));
    }

    private function tokenCacheKey(): string
    {
        return 'payu.oauth.'.config('payu.env').'.'.sha1((string) config('payu.client_id'));
    }

    private function amountToMinor(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
