<?php

namespace Tests\Feature;

use App\Http\Controllers\Storefront\ContactController;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class FrenchStorefrontContactTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false]);
    }

    #[DataProvider('frenchPages')]
    public function test_french_pages_use_the_same_identity_and_have_no_polish_contact_address(string $path): void
    {
        $response = $this->get('https://gpswiss.fr'.$path)->assertOk();
        foreach ([
            'GREGOR SWISS FR', '7 rue de Castellane', '75008 Paris', 'France',
            'contact@gpswiss.fr', '+33 6 95 12 11 80', 'SARL au capital de 1 000,00 €',
            'SIREN : 105 670 004', 'SIRET : 105 670 004 00016', 'RCS Paris : 105 670 004',
            'FR56 105 670 004', '45.32Z', 'Gérant : Grzegorz Paciorek',
            'mailto:contact@gpswiss.fr', 'tel:+33695121180',
        ] as $value) {
            $response->assertSee($value);
        }
        foreach (['biuro@gpswiss.fr', 'Milanowska', 'Sobolew', '+48504266984', '+48 504 266 984'] as $value) {
            $response->assertDontSee($value);
        }
        // Ignore translated marketing strings in the JS dictionary: check rendered addresses.
        $visibleHtml = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $response->getContent());
        $this->assertStringNotContainsString('Pologne', $visibleHtml);
    }

    public static function frenchPages(): array
    {
        return [
            'terms' => ['/regulamin'],
            'privacy' => ['/polityka-prywatnosci'],
            'contact' => ['/kontakt'],
        ];
    }

    public function test_french_footer_and_header_are_scoped_to_host_not_selected_locale(): void
    {
        $this->withSession(['locale' => 'fr'])->get('https://gpswiss.pl/kontakt')
            ->assertOk()->assertSee('Milanowska 137')->assertSee('08-460 Sobolew')
            ->assertSee('8262157853')->assertSee('368948917')
            ->assertSee('biuro@gpswiss.pl')->assertSee('tel:+48504266984')
            ->assertSee('+48 504 266 984')->assertSee('504 266 984')
            ->assertSee('Mapa Google - Milanowska 137, 08-460 Sobolew');
    }

    public function test_polish_legal_documents_keep_their_company_details(): void
    {
        foreach (['/regulamin', '/polityka-prywatnosci'] as $path) {
            $this->get('https://gpswiss.pl'.$path)->assertOk()
                ->assertSee('Milanowska')->assertSee('Sobolew')->assertSee('biuro@gpswiss.pl')
                ->assertDontSee('GREGOR SWISS FR');
        }
    }

    public function test_only_genuinely_unknown_legal_details_remain_placeholders(): void
    {
        $this->get('https://gpswiss.fr/regulamin')->assertOk()->assertSee('Médiateur désigné : à compléter');
        $this->get('https://gpswiss.fr/polityka-prywatnosci')->assertOk()
            ->assertSee('Cette section devra être précisée selon les prestataires effectivement utilisés')
            ->assertSee('Hébergeur : identité et coordonnées à compléter après confirmation.')
            ->assertSee('Liste définitive des cookies et fournisseurs');
    }

    #[DataProvider('contactRecipients')]
    public function test_contact_form_recipient_and_failure_contact_follow_the_host(string $host, string $recipient, bool $failure): void
    {
        app()->setLocale('fr');
        // Mock validated input to keep DNS/email delivery outside this identity regression test.
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getHost')->andReturn($host);
        $request->shouldReceive('validate')->once()->andReturn([
            'name' => 'Client', 'email' => 'client@example.com', 'message' => 'Question',
        ]);
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $callback) use ($recipient, $failure) {
            $email = new Email;
            $callback(new Message($email));
            $this->assertSame($recipient, $email->getTo()[0]->getAddress());
            $this->assertSame('client@example.com', $email->getReplyTo()[0]->getAddress());
            if ($failure) {
                throw new \RuntimeException('Simulated mail failure');
            }
        });
        $response = (new ContactController)->send($request);
        $this->assertSame(302, $response->getStatusCode());
        if ($failure) {
            $this->assertStringContainsString($recipient, session('error'));
            $this->assertStringNotContainsString(':email', session('error'));
        } else {
            $this->assertSame(__('storefront.message_sent'), session('success'));
        }
    }

    public static function contactRecipients(): array
    {
        return [
            'FR success' => ['gpswiss.fr', 'contact@gpswiss.fr', false],
            'PL success' => ['gpswiss.pl', 'biuro@gpswiss.pl', false],
            'FR failure' => ['gpswiss.fr', 'contact@gpswiss.fr', true],
            'PL failure' => ['gpswiss.pl', 'biuro@gpswiss.pl', true],
        ];
    }
}
