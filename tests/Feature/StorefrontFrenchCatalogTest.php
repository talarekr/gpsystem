<?php

namespace Tests\Feature;

use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontFrenchCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_hosts_render_the_same_catalog_and_product_data(): void
    {
        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false]);

        $part = Part::query()->create([
            'name' => 'Silnik testowy GPSwiss',
            'slug' => 'silnik-testowy-gpswiss',
            'description' => 'Obecny opis produktu',
            'status' => 'ready',
            'price' => 1234,
            'currency' => 'PLN',
            'quantity' => 2,
            'needs_listing' => false,
            'needs_review' => false,
        ]);
        $original = $part->refresh()->getRawOriginal();

        foreach (['gpswiss.pl' => 'pl', 'gpswiss.fr' => 'fr'] as $host => $locale) {
            $this->withSession(['locale' => 'pl'])
                ->get('https://'.$host.'/czesci')
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee($part->name)
                ->assertSee('https://'.$host.'/produkt/'.$part->slug);

            $this->get('https://'.$host.'/produkt/'.$part->slug)
                ->assertOk()
                ->assertViewIs('storefront.parts.show')
                ->assertViewHas('part', fn (Part $rendered): bool => $rendered->id === $part->id)
                ->assertSee('Obecny opis produktu')
                ->assertSee('1 234,00 PLN')
                ->assertSee('https://'.$host.'/regulamin');
        }

        $this->assertSame($original, $part->fresh()->getRawOriginal());
    }
}
