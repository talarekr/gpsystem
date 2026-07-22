<?php
namespace App\Services\Marketplace\PriceSync;
use App\Models\MarketplaceListing;
use App\Support\Marketplace\AllegroUserAgent;
use App\Services\Marketplace\OAuthTokenManager;

class AllegroPriceSyncAdapter implements MarketplacePriceSyncAdapter
{
    private const MEDIA_TYPE = 'application/vnd.allegro.public.v1+json';

    public function sync(MarketplaceListing $listing, array $price): array
    {
        $offerId = (string) $listing->external_offer_id;
        $base = rtrim((string) ($listing->account?->api_base_url ?: 'https://api.allegro.pl'), '/');
        $tokenResult = $listing->account ? app(OAuthTokenManager::class)->ensureValidToken($listing->account) : ['ok'=>false];
        if (($tokenResult['ok'] ?? false) !== true) return ['status'=>'error','http_status'=>$tokenResult['http_status'] ?? null,'endpoint'=>'PATCH /sale/product-offers/{offerId}','final_success'=>false,'blocker'=>'allegro_oauth_token_unavailable','response_summary'=>['token_refresh_status'=>'failed','message'=>$tokenResult['message'] ?? null]];
        $token = (string) $tokenResult['access_token'];
        $payload = ['sellingMode' => ['price' => ['amount' => $price['marketplace_price'], 'currency' => 'PLN']]];
        $url = $base.'/sale/product-offers/'.$offerId;
        $request = ['method'=>'PATCH','url'=>$url,'endpoint'=>'PATCH /sale/product-offers/{offerId}','headers'=>['Accept'=>self::MEDIA_TYPE,'Content-Type'=>self::MEDIA_TYPE,'Authorization'=>'Bearer ***','User-Agent'=>AllegroUserAgent::value()],'payload'=>$payload];
        $patch = AllegroUserAgent::request()->withToken($token)->withHeaders(['Accept'=>self::MEDIA_TYPE,'Content-Type'=>self::MEDIA_TYPE])->timeout(20)->patch($url, $payload);
        $patchBody = is_array($patch->json()) ? $patch->json() : [];
        if (! $patch->successful()) return ['status' => 'error', 'http_status' => $patch->status(), 'endpoint' => 'PATCH /sale/product-offers/{offerId}', 'request_summary' => $request, 'response_summary' => $this->errorSummary($patchBody, $patch->status(), $patch->header('trace-id') ?: $patch->header('x-request-id') ?: $patch->header('x-correlation-id')), 'final_success'=>false, 'blocker'=>'allegro_price_patch_failed'];
        $get = AllegroUserAgent::request()->withToken($token)->accept(self::MEDIA_TYPE)->timeout(20)->get($url);
        $amount = app(PriceNormalizer::class)->normalize(data_get($get->json(), 'sellingMode.price.amount'));
        $currency = data_get($get->json(), 'sellingMode.price.currency');
        $ok = $get->successful() && $amount === $price['marketplace_price'] && $currency === 'PLN';
        return ['status' => $ok ? 'success' : 'error', 'http_status' => $get->status(), 'endpoint' => 'PATCH /sale/product-offers/{offerId}', 'read_after_write_endpoint' => 'GET /sale/product-offers/{offerId}', 'request_summary' => $request, 'response_summary' => ['patch_status' => $patch->status(), 'get_status' => $get->status(), 'request_id'=>$patch->header('trace-id') ?: $patch->header('x-request-id') ?: $patch->header('x-correlation-id')], 'read_after_write' => ['amount' => $amount, 'currency' => $currency], 'remote_confirmed_price' => $ok ? $amount : null, 'final_success' => $ok, 'blocker' => $ok ? null : 'allegro_read_after_write_price_mismatch'];
    }

    private function errorSummary(array $body, int $status, ?string $requestId): array
    {
        return ['http_status'=>$status,'request_id'=>$requestId,'body'=>$this->safe($body),'errors'=>array_map(fn($e)=>array_intersect_key((array)$e,array_flip(['code','message','details','path','userMessage'])), (array)($body['errors'] ?? []))];
    }
    private function safe(array $payload): array { return collect($payload)->except(['access_token','authorization','token'])->map(fn($v)=>is_array($v)?$this->safe($v):$v)->take(50)->all(); }
}
