<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroOfferPublicationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_offer_sends_allegro_vendor_media_type_headers_and_json_payload(): void
    {
        Http::fake(['https://api.allegro.test/sale/product-offers/17759363397' => Http::response(['publication' => ['status' => 'ENDED']], 200)]);
        $account = $this->account();

        $result = (new AllegroApiClient('allegro_main', $account))->endOffer('17759363397');

        $this->assertTrue($result['ok']);
        $this->assertSame('application/vnd.allegro.public.v1+json', $result['request_summary']['headers']['Accept']);
        $this->assertSame('application/vnd.allegro.public.v1+json', $result['request_summary']['headers']['Content-Type']);
        $this->assertSame(['publication' => ['status' => 'ENDED']], $result['request_summary']['payload']);
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://api.allegro.test/sale/product-offers/17759363397'
            && $request->hasHeader('Accept', 'application/vnd.allegro.public.v1+json')
            && $request->hasHeader('Content-Type', 'application/vnd.allegro.public.v1+json')
            && $request->hasHeader('Authorization', 'Bearer token')
            && $request->data() === ['publication' => ['status' => 'ENDED']]);
    }

    public function test_activate_offer_uses_same_allegro_vendor_media_type_headers(): void
    {
        Http::fake(['https://api.allegro.test/sale/product-offers/offer-123' => Http::response(['publication' => ['status' => 'ACTIVE']], 200)]);

        $result = (new AllegroApiClient('allegro_main', $this->account()))->activateOffer('offer-123');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request->hasHeader('Accept', 'application/vnd.allegro.public.v1+json')
            && $request->hasHeader('Content-Type', 'application/vnd.allegro.public.v1+json')
            && $request->data() === ['publication' => ['status' => 'ACTIVE']]);
    }

    public function test_failed_end_offer_logs_sanitized_request_and_response_body(): void
    {
        Http::fake(['https://api.allegro.test/sale/product-offers/offer-415' => Http::response(['errors' => [['code' => 'UnsupportedMediaType', 'message' => 'Unsupported Media Type']]], 415)]);

        $result = (new AllegroApiClient('allegro_main', $this->account()))->endOffer('offer-415');

        $this->assertFalse($result['ok']);
        $this->assertSame(415, $result['http_status']);
        $this->assertSame('Bearer ***', $result['request_summary']['headers']['Authorization']);
        $this->assertSame('PATCH', $result['request_summary']['method']);
        $this->assertSame([['code' => 'UnsupportedMediaType', 'message' => 'Unsupported Media Type']], $result['response_summary']['errors']);
    }

    private function account(): MarketplaceAccount
    {
        return MarketplaceAccount::query()->create([
            'code' => 'allegro_main',
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.test',
            'api_credentials' => ['access_token' => 'token'],
        ]);
    }
}
