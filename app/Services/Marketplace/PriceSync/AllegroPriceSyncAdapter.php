<?php
namespace App\Services\Marketplace\PriceSync;
use App\Models\MarketplaceListing;
use Illuminate\Support\Facades\Http;

class AllegroPriceSyncAdapter implements MarketplacePriceSyncAdapter
{
    public function sync(MarketplaceListing $listing, array $price): array
    {
        $offerId = (string) $listing->external_offer_id;
        $base = rtrim((string) ($listing->account?->api_base_url ?: 'https://api.allegro.pl'), '/');
        $payload = ['sellingMode' => ['price' => ['amount' => $price['marketplace_price'], 'currency' => 'PLN']]];
        $patch = Http::acceptJson()->asJson()->patch($base.'/sale/product-offers/'.$offerId, $payload);
        if (! $patch->successful()) return ['status' => 'error', 'http_status' => $patch->status(), 'endpoint' => 'PATCH /sale/product-offers/{offerId}', 'request_summary' => $payload, 'response_summary' => ['status' => $patch->status()]];
        $get = Http::acceptJson()->get($base.'/sale/product-offers/'.$offerId);
        $amount = app(PriceNormalizer::class)->normalize(data_get($get->json(), 'sellingMode.price.amount'));
        $currency = data_get($get->json(), 'sellingMode.price.currency');
        $ok = $get->successful() && $amount === $price['marketplace_price'] && $currency === 'PLN';
        return ['status' => $ok ? 'success' : 'error', 'http_status' => $get->status(), 'endpoint' => 'PATCH /sale/product-offers/{offerId}', 'read_after_write_endpoint' => 'GET /sale/product-offers/{offerId}', 'request_summary' => $payload, 'response_summary' => ['patch_status' => $patch->status(), 'get_status' => $get->status()], 'read_after_write' => ['amount' => $amount, 'currency' => $currency], 'remote_confirmed_price' => $ok ? $amount : null, 'final_success' => $ok, 'blocker' => $ok ? null : 'allegro_read_after_write_price_mismatch'];
    }
}
