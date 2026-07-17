<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AllegroListingDiagnosisReadOnlyTest extends TestCase
{
    use RefreshDatabase;


    public function test_guest_cannot_access_diagnosis(): void
    {
        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id=3025')
            ->assertUnauthorized();
    }

    public function test_authenticated_non_admin_cannot_access_diagnosis(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Customer', 'email' => 'customer'.uniqid().'@example.test', 'password' => 'password']));

        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id=3025')
            ->assertForbidden();
    }

    public function test_admin_can_access_with_part_id_without_token(): void
    {
        $this->actingAsAdminUser();
        [$part] = $this->fixture(offerId: null, listingId: null);
        Http::fake(fn () => $this->fail('Diagnosis without offer_id must not call Allegro.'));

        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('part_id', $part->id)
            ->assertJsonMissingPath('token');
    }

    public function test_missing_part_id_returns_validation_error(): void
    {
        $this->actingAsAdminUser();

        $this->getJson('/tools/check-allegro-listing-status-read-only')
            ->assertStatus(422);
    }

    public function test_invalid_part_id_returns_safe_not_found_diagnostic(): void
    {
        $this->actingAsAdminUser();

        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id=999999')
            ->assertOk()
            ->assertJsonPath('remote.request_attempted', false)
            ->assertJsonPath('remote.error', 'part_not_found')
            ->assertJsonPath('writes.database', false)
            ->assertJsonPath('writes.allegro', false);
    }

    public function test_diagnose_does_not_mutate_listing_and_uses_only_get_product_offer(): void
    {
        $this->actingAsAdminUser();
        [$part, $listing] = $this->fixture(status: 'active', lastApiStatus: 'ACTIVE');
        $before = $listing->fresh()->getAttributes();

        Http::fake(['https://api.allegro.test/sale/product-offers/offer-123' => Http::response([
            'publication' => ['status' => 'ENDED', 'endingAt' => '2026-07-01T00:00:00Z'],
            'stock' => ['available' => 0],
        ], 200)]);

        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('comparison.classification', 'remote_ended_local_active')
            ->assertJsonPath('writes.database', false)
            ->assertJsonPath('writes.allegro', false)
            ->assertJsonPath('local.last_error', 'keep-this-error')
            ->assertJsonPath('remote.request_attempted', true)
            ->assertJsonPath('remote.publication_status', 'ENDED');

        $this->assertSame($before, $listing->fresh()->getAttributes());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains((string) $request->url(), '/sale/product-offers/offer-123'));
    }

    public function test_active_local_and_active_remote_is_consistent(): void
    {
        $this->actingAsAdminUser();
        [$part] = $this->fixture(status: 'active', lastApiStatus: 'ACTIVE');
        Http::fake(['https://api.allegro.test/sale/product-offers/offer-123' => Http::response(['publication' => ['status' => 'ACTIVE'], 'stock' => ['available' => 1]], 200)]);

        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('comparison.consistent', true)
            ->assertJsonPath('comparison.classification', 'consistent');
    }

    public function test_missing_offer_id_does_not_call_allegro(): void
    {
        $this->actingAsAdminUser();
        [$part, $listing] = $this->fixture(offerId: null, listingId: null);
        $before = $listing->fresh()->getAttributes();
        Http::fake(fn () => $this->fail('Diagnosis without offer_id must not call Allegro.'));

        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('remote.request_attempted', false)
            ->assertJsonPath('remote.http_status', null)
            ->assertJsonPath('recommended_next_step', 'No Allegro offer_id is available locally; verify or repair the local mapping before any remote diagnosis.');

        $this->assertSame($before, $listing->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_404_returns_diagnostics_without_mutation(): void
    {
        $this->actingAsAdminUser();
        [$part, $listing] = $this->fixture();
        $before = $listing->fresh()->getAttributes();
        Http::fake(['https://api.allegro.test/sale/product-offers/offer-123' => Http::response(['message' => 'Not found'], 404)]);

        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('remote.http_status', 404)
            ->assertJsonPath('remote.error', 'Not found')
            ->assertJsonPath('writes.database', false);

        $this->assertSame($before, $listing->fresh()->getAttributes());
    }

    /** @dataProvider nonSuccessStatuses */
    public function test_error_statuses_do_not_mutate_local_record(int $status): void
    {
        $this->actingAsAdminUser();
        [$part, $listing] = $this->fixture();
        $before = $listing->fresh()->getAttributes();
        Http::fake(['https://api.allegro.test/sale/product-offers/offer-123' => Http::response(['error' => 'api_error'], $status)]);

        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('remote.http_status', $status)
            ->assertJsonPath('remote.error', 'api_error');

        $this->assertSame($before, $listing->fresh()->getAttributes());
    }

    public static function nonSuccessStatuses(): array
    {
        return [[401], [403], [429], [500], [503]];
    }

    public function test_indicator_reasons_explain_check_symbol_and_remote_is_not_used(): void
    {
        $this->actingAsAdminUser();
        [$part] = $this->fixture(status: 'active', lastApiStatus: 'ACTIVE');
        Http::fake(['https://api.allegro.test/sale/product-offers/offer-123' => Http::response(['publication' => ['status' => 'ENDED']], 200)]);

        $payload = $this->getJson('/tools/check-allegro-listing-status-read-only?part_id='.$part->id)
            ->assertOk()
            ->json();

        $this->assertTrue($payload['local']['active_indicator']);
        $this->assertContains(['condition' => 'listing.status === active', 'actual' => 'active', 'passed' => true], $payload['local']['indicator_reasons']);
        $this->assertContains(['condition' => 'remote publication status', 'actual' => 'ENDED', 'used_by_current_indicator' => false], $payload['local']['indicator_reasons']);
    }


    public function test_rate_limiting_applies_to_admin_diagnosis_endpoint(): void
    {
        $this->actingAsAdminUser();
        [$part] = $this->fixture(offerId: null, listingId: null);
        Http::fake(fn () => $this->fail('Rate limited diagnosis without offer_id must not call Allegro.'));

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/tools/check-allegro-listing-status-read-only?part_id='.$part->id)->assertOk();
        }

        $this->getJson('/tools/check-allegro-listing-status-read-only?part_id='.$part->id)
            ->assertStatus(429);
    }

    private function fixture(string $status = 'active', ?string $lastApiStatus = 'old-api-status', ?string $offerId = 'offer-123', ?string $listingId = 'offer-123'): array
    {
        $account = MarketplaceAccount::query()->create([
            'code' => 'allegro_main',
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.test',
            'api_credentials' => ['access_token' => 'token'],
        ]);
        $part = Part::query()->create(['name' => 'Diagnosis part', 'sku' => uniqid('ALG-DIAG-'), 'quantity' => 1, 'status' => 'ready']);
        $listing = MarketplaceListing::query()->create([
            'marketplace_account_id' => $account->id,
            'part_id' => $part->id,
            'marketplace' => 'allegro',
            'external_offer_id' => $offerId,
            'external_listing_id' => $listingId,
            'url' => $offerId ? 'https://allegro.pl/oferta/'.$offerId : null,
            'status' => $status,
            'sync_status' => 'published',
            'match_status' => 'matched',
            'last_api_status' => $lastApiStatus,
            'last_error' => 'keep-this-error',
            'last_synced_at' => now()->subDay(),
            'raw_payload' => ['keep' => 'raw'],
        ]);

        return [$part, $listing, $account];
    }

    private function actingAsAdminUser(): void
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::query()->create(['name' => 'Owner Admin', 'email' => 'owner'.uniqid().'@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
