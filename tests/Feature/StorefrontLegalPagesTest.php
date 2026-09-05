<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontLegalPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_terms_page_contains_the_complete_legal_content(): void
    {
        $response = $this->get('/regulamin');

        $response->assertOk()
            ->assertSee('REGULAMIN SKLEPU INTERNETOWEGO')
            ->assertSee('GREGOR swiss GRZEGORZ PACIOREK')
            ->assertSee('8262157853')
            ->assertSeeInOrder([
                '6. Zwroty',
                'w terminie 14 dni',
                '7. Wymiana towaru',
                'w terminie 14 dni',
            ])
            ->assertDontSee('21 dni')
            ->assertDontSee('get_header')
            ->assertDontSee('get_footer')
            ->assertDontSee('ABSPATH');
    }

    public function test_privacy_policy_page_contains_the_complete_legal_content(): void
    {
        $response = $this->get('/polityka-prywatnosci');

        $response->assertOk()
            ->assertSee('POLITYKA PRYWATNOŚCI')
            ->assertSee('GREGOR swiss GRZEGORZ PACIOREK')
            ->assertSee('biuro@gpswiss.pl')
            ->assertDontSee('get_header')
            ->assertDontSee('get_footer')
            ->assertDontSee('ABSPATH');
    }
}
