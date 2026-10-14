<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Services\Marketplace\NbpExchangeRateService;
use App\Services\Storefront\FrenchPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FrenchNbpPriceFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false]);
    }

    public function test_nbp_module_rounding_cache_and_public_eur_fallback_do_not_modify_pln(): void
    {
        Http::fake(['api.nbp.pl/*' => Http::response(['rates' => [[
            'mid' => 4.321, 'effectiveDate' => today()->toDateString(), 'no' => 'NBP-test',
        ]]])]);
        $part = Part::create(['name' => 'Moteur test', 'currency' => 'PLN', 'price' => 100,
            'quantity' => 1, 'status' => 'ready']);
        $price = app(FrenchPriceResolver::class)->resolve($part);
        $this->assertSame((int) round(round(100 * 1.25 / 4.321, 2) * 100), $price['cents']);
        $view = $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()
            ->assertSee('28,93 EUR')->assertDontSee('PLN')->assertDontSee('Prix indisponible</strong>', false);
        preg_match('/<aside class="sf-purchase">.*?<strong>(.*?)<\/strong>/s', $view->getContent(), $priceMarkup);
        $this->assertSame('28,93 EUR', trim(strip_tags($priceMarkup[1])));
        $this->get('https://gpswiss.pl/produkt/'.$part->slug)->assertOk()->assertSee('100,00 PLN');
        $this->assertSame('100.00', $part->fresh()->price);
        Http::assertSentCount(1);
    }

    public function test_failed_nbp_is_looked_up_once_per_request_and_blocks_fallback(): void
    {
        Http::fake(['api.nbp.pl/*' => Http::response([], 503)]);
        $part = Part::create(['name' => 'Moteur test', 'currency' => 'PLN', 'price' => 100,
            'quantity' => 1, 'status' => 'ready']);
        $resolver = app(FrenchPriceResolver::class);
        $this->assertNull($resolver->resolve($part)['cents']);
        $this->assertNull($resolver->resolve($part)['cents']);
        Http::assertSentCount(1);
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()
            ->assertSee('Prix indisponible')->assertDontSee('PLN')->assertDontSee('class="sf-purchase__cart"', false);
        $this->post('https://gpswiss.fr/koszyk/dodaj/'.$part->id)->assertRedirect()->assertSessionHas('warning');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_invalid_stale_or_future_rates_are_rejected_even_when_marked_ok(): void
    {
        $part = Part::create(['name' => 'Moteur test', 'currency' => 'PLN', 'price' => 100,
            'quantity' => 1, 'status' => 'ready']);
        foreach ([
            ['rate' => 0, 'date' => today()->toDateString()],
            ['rate' => -5, 'date' => today()->toDateString()],
            ['rate' => 5, 'date' => today()->subDays(8)->toDateString()],
            ['rate' => 5, 'date' => today()->addDay()->toDateString()],
            ['rate' => 5, 'date' => '2026-02-30'],
        ] as $case) {
            request()->attributes->remove('storefront.fr.nbp.eur_rate');
            $this->mock(NbpExchangeRateService::class)->shouldReceive('currentEurPln')->once()
                ->andReturn(['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A',
                    'rate' => $case['rate'], 'effective_date' => $case['date']]);
            $this->assertNull(app(FrenchPriceResolver::class)->resolve($part)['cents']);
        }
        Http::assertNothingSent();
    }
}
