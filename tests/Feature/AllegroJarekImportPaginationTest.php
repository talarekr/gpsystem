<?php

namespace Tests\Feature;

use App\Services\JarekGearboxes\AllegroJarekImportService;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class AllegroJarekImportPaginationTest extends TestCase
{
    public function test_fetch_offers_paginates_sale_offers_until_requested_limit(): void
    {
        config([
            'services.allegro_jarek.client_id' => 'client',
            'services.allegro_jarek.client_secret' => 'secret',
            'services.allegro_jarek.access_token' => 'token',
            'services.allegro_jarek.base_url' => 'https://api.allegro.test',
        ]);

        Http::fake(function ($request) {
            $query = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            $offset = (int) ($query['offset'] ?? 0);
            $limit = (int) ($query['limit'] ?? 0);

            return Http::response([
                'offers' => array_map(
                    fn (int $id): array => ['id' => (string) $id, 'name' => 'Offer '.$id],
                    range($offset + 1, $offset + $limit),
                ),
            ], 200);
        });

        $method = new ReflectionMethod(new AllegroJarekImportService(), 'fetchOffers');
        $method->setAccessible(true);
        $result = $method->invoke(new AllegroJarekImportService(), 250, 0);

        $this->assertCount(250, $result['offers']);
        $this->assertSame(250, $result['pagination']['requested_limit']);
        $this->assertSame(250, $result['pagination']['effective_limit']);
        $this->assertSame(100, $result['pagination']['page_size']);
        $this->assertSame(3, $result['pagination']['pages_fetched']);
        $this->assertTrue($result['pagination']['reached_requested_limit']);
        $this->assertTrue($result['pagination']['has_more_after_limit']);

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'limit=100') && str_contains($request->url(), 'offset=0'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'limit=100') && str_contains($request->url(), 'offset=100'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'limit=50') && str_contains($request->url(), 'offset=200'));
    }
}
