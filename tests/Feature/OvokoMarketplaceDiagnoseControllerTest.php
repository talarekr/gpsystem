<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoMarketplaceDiagnoseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_tool_renders_input_and_results_for_requested_parts(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Ovoko diagnosed', 'sku' => 'OV-WEB-1', 'quantity' => 1, 'status' => 'ready', 'needs_listing' => true]);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ovoko',
            'external_offer_id' => '11705',
            'external_listing_id' => '11705',
            'status' => 'mapped',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
            'last_api_status' => 'ok',
            'last_error' => 'old sync error',
            'url' => 'https://ovoko.pl/czesci-samochodowe/hgf11705-test',
        ]);

        $missingPartId = $part->id + 1000;

        $this->get('/admin/tools/marketplace/ovoko-diagnose?part_id='.$part->id.','.$missingPartId)
            ->assertOk()
            ->assertSee('Ovoko diagnose')
            ->assertSee('Sprawdź')
            ->assertSee((string) $part->id)
            ->assertSee('needs_listing')
            ->assertSee('old sync error')
            ->assertSee('resolved externalOfferId()')
            ->assertSee('blocking_sync_error')
            ->assertSee((string) $missingPartId)
            ->assertSee('not found');
    }

    public function test_json_tool_reports_every_requested_case_read_only(): void
    {
        $this->actingAsAdminUser();

        $active = Part::query()->create(['name' => 'Active', 'sku' => 'OV-WEB-2', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create([
            'part_id' => $active->id,
            'marketplace' => 'ovoko',
            'external_offer_id' => '11706',
            'external_listing_id' => '11706',
            'status' => 'active',
            'url' => 'https://ovoko.pl/czesci-samochodowe/hgf11706-active',
        ]);

        $notReady = Part::query()->create(['name' => 'Draft', 'sku' => 'OV-WEB-3', 'quantity' => 1, 'status' => 'draft']);
        $withoutListing = Part::query()->create(['name' => 'No listing', 'sku' => 'OV-WEB-4', 'quantity' => 1, 'status' => 'ready']);
        $missingPartId = $withoutListing->id + 1000;

        $response = $this->getJson('/admin/tools/marketplace/ovoko-diagnose?part_id='.$active->id.' '.$notReady->id.' '.$withoutListing->id.' '.$missingPartId.'&format=json')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('relisting_triggered', false)
            ->assertJsonPath('results.0.part.id', $active->id)
            ->assertJsonPath('results.0.resolver.reason', 'ovoko_active')
            ->assertJsonPath('results.0.marketplace_listings.0.resolved_external_offer_id', '11706')
            ->assertJsonPath('results.0.marketplace_listings.0.resolved_listing_url', 'https://ovoko.pl/czesci-samochodowe/hgf11706-active')
            ->assertJsonPath('results.1.part.id', $notReady->id)
            ->assertJsonPath('results.1.resolver.reason', 'part_not_ready')
            ->assertJsonPath('results.2.part.id', $withoutListing->id)
            ->assertJsonPath('results.2.resolver.reason', 'missing_listing')
            ->assertJsonPath('results.2.marketplace_listings', [])
            ->assertJsonPath('results.3.part_id', $missingPartId)
            ->assertJsonPath('results.3.found', false)
            ->assertJsonPath('results.3.resolver.reason', 'part_not_found');

        $this->assertSame([$active->id, $notReady->id, $withoutListing->id, $missingPartId], $response->json('part_ids'));
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Owner Admin',
            'email' => 'owner@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
