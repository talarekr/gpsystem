<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveStorefrontHost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontFrenchHostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false]);

        Route::middleware('web')->get('/_storefront-locale-probe', function (Request $request) {
            return response()->json([
                'locale' => app()->getLocale(),
                'terms_profile' => $request->attributes->get('storefront_terms_profile'),
                'home_url' => route('storefront.home'),
            ]);
        })->name('storefront.locale-probe');
    }

    public function test_pl_preserves_default_locale_and_selected_session_language(): void
    {
        $this->get('https://gpswiss.pl/_storefront-locale-probe')
            ->assertOk()->assertJsonPath('locale', 'pl');

        $this->withSession(['locale' => 'de'])
            ->get('https://gpswiss.pl/_storefront-locale-probe')
            ->assertOk()
            ->assertJsonPath('locale', 'de')
            ->assertJsonPath('terms_profile', 'pl')
            ->assertJsonPath('home_url', 'https://gpswiss.pl');
    }

    public function test_fr_uses_french_even_with_a_previous_language_selection(): void
    {
        $this->withSession(['locale' => 'pl'])
            ->get('https://gpswiss.fr/_storefront-locale-probe')
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath('terms_profile', 'fr')
            ->assertJsonPath('home_url', 'https://gpswiss.fr')
            ->assertSessionHas('locale', 'fr')
            ->assertCookie('gpswiss_locale', 'fr');
    }

    public function test_fr_overrides_a_polish_locale_cookie(): void
    {
        $this->withCookie('gpswiss_locale', 'pl')
            ->get('https://gpswiss.fr/_storefront-locale-probe')
            ->assertOk()->assertJsonPath('locale', 'fr');
    }

    public function test_unknown_host_keeps_legacy_language_behavior(): void
    {
        $this->withSession(['locale' => 'en'])
            ->get('https://preview.example/_storefront-locale-probe')
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('terms_profile', null);
    }

    public function test_language_selection_remains_unchanged_on_pl(): void
    {
        $this->post('https://gpswiss.pl/jezyk', ['locale' => 'de'])
            ->assertRedirect()->assertSessionHas('locale', 'de');
    }

    public function test_language_selection_cannot_override_french_host(): void
    {
        $this->post('https://gpswiss.fr/jezyk', ['locale' => 'pl'])
            ->assertRedirect()->assertSessionHas('locale', 'fr');
    }

    public function test_pl_legal_profile_does_not_follow_selected_language(): void
    {
        $this->withSession(['locale' => 'fr'])
            ->get('https://gpswiss.pl/regulamin')
            ->assertOk()
            ->assertViewIs('storefront.terms')
            ->assertSee('REGULAMIN SKLEPU INTERNETOWEGO')
            ->assertDontSee('CONDITIONS GÉNÉRALES DE VENTE');
    }

    public function test_fr_terms_are_separate_and_link_to_fr_privacy(): void
    {
        $this->get('https://gpswiss.fr/regulamin')
            ->assertOk()
            ->assertViewIs('storefront.fr.terms')
            ->assertSee('CONDITIONS GÉNÉRALES DE VENTE')
            ->assertSee('lang="fr"', false)
            ->assertSee('https://gpswiss.fr/polityka-prywatnosci')
            ->assertSee('8262157853')
            ->assertDontSee('REGULAMIN SKLEPU INTERNETOWEGO');
    }

    public function test_fr_privacy_is_separate_and_links_to_fr_terms(): void
    {
        $this->get('https://gpswiss.fr/polityka-prywatnosci')
            ->assertOk()
            ->assertViewIs('storefront.fr.privacy-policy')
            ->assertSee('POLITIQUE DE CONFIDENTIALITÉ')
            ->assertSee('https://gpswiss.fr/regulamin')
            ->assertDontSee('POLITYKA PRYWATNOŚCI');
    }

    public function test_pl_privacy_is_unchanged(): void
    {
        $this->get('https://gpswiss.pl/polityka-prywatnosci')
            ->assertOk()->assertViewIs('storefront.privacy-policy')
            ->assertSee('POLITYKA PRYWATNOŚCI');
    }

    #[DataProvider('excludedRoutes')]
    public function test_operational_routes_never_receive_fr_context(string $method, string $path): void
    {
        // Resolve real routes but do not execute their controllers or call any provider.
        $request = Request::create('https://gpswiss.fr'.$path, $method);
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        app()->setLocale('en');

        (new ResolveStorefrontHost())->handle($request, function (Request $request) {
            $this->assertFalse($request->attributes->has('storefront_locale'));
            $this->assertFalse($request->attributes->has('storefront_terms_profile'));
            $this->assertSame('en', app()->getLocale());

            return response('unchanged');
        });
    }

    public static function excludedRoutes(): array
    {
        return [
            'admin' => ['GET', '/admin/login'],
            'admin csrf' => ['GET', '/admin/csrf-token'],
            'livewire' => ['POST', '/livewire/update'],
            'payu webhook' => ['POST', '/payu/notify'],
            'marketplace photos' => ['GET', '/marketplace/ovoko/photos/1/'.str_repeat('a', 24).'/photo.jpg'],
            'ebay assets' => ['GET', '/ebay-template/assets/icon-shipping.png'],
            'tools' => ['GET', '/tools/debug-allegro-shipment-preview'],
            'integration info' => ['GET', '/api-info'],
        ];
    }
}
