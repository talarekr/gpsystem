<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Admin\PartMarketplaceStatusResolver;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EbayMarketplaceDiagnoseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_get_only_renders_form_without_api_calls_or_bulk_audit(): void
    {
        $this->actingAsAdminUser();
        Http::fake(fn () => throw new \RuntimeException('Unexpected eBay API call'));

        $part = Part::query()->create(['name' => 'eBay part', 'sku' => 'EB-1', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_listing_id' => '123456789012', 'status' => 'active']);

        $this->get('/admin/tools/ebay/marketplace-diagnose')
            ->assertOk()
            ->assertSee('Sprawdź podane part_id')
            ->assertSee('Uruchom audyt masowy')
            ->assertSee('Oznacz zakończone jako historyczne', false)
            ->assertDontSee('EB-1');

        Http::assertNothingSent();
    }

    public function test_empty_json_get_does_not_default_to_bulk_or_check_api(): void
    {
        $this->actingAsAdminUser();
        Http::fake(fn () => throw new \RuntimeException('Unexpected eBay API call'));

        $this->getJson('/admin/tools/ebay/marketplace-diagnose?format=json')
            ->assertOk()
            ->assertJsonPath('input.bulk_mode', false)
            ->assertJsonPath('input.check_api', false)
            ->assertJsonCount(0, 'rows');

        Http::assertNothingSent();
    }

    public function test_past_end_date_is_ended_not_active_and_does_not_block_duplicate_guard(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Ended eBay', 'sku' => 'EB-END', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_de',
            'external_listing_id' => '123456789012',
            'status' => 'active',
            'last_api_status' => 'active',
            'raw_payload' => ['itemEndDate' => '2026-05-31T10:00:00.000Z'],
        ]);

        $resolverRow = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'ebay');
        $this->assertSame('✕', $resolverRow['display_icon']);

        Http::fake(['*' => Http::response([
            'itemId' => 'v1|123456789012|0',
            'itemEndDate' => '2026-05-31T10:00:00.000Z',
            'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']],
        ], 200)]);

        $this->getJson('/admin/tools/ebay/marketplace-diagnose?action=part&part_ids='.$part->id.'&check_api=1&format=json')
            ->assertOk()
            ->assertJsonPath('rows.0.audit_classification', 'ended/stale should_show_x_and_allow_new_publish')
            ->assertJsonPath('rows.0.duplicate_guard_would_block', false)
            ->assertJsonPath('rows.0.marketplace_listings.0.api.api_listing_status', 'ended')
            ->assertJsonPath('rows.0.marketplace_listings.0.api.end_date_is_past', true);
    }


    public function test_ended_target_ebay_listing_is_not_overridden_by_active_other_ebay_marketplace(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Mixed eBay', 'sku' => 'EB-MIX', 'quantity' => 1, 'status' => 'ready']);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_de',
            'external_listing_id' => '123456789012',
            'status' => 'active',
            'last_api_status' => 'active',
            'raw_payload' => ['itemEndDate' => '2026-05-31T10:00:00.000Z'],
        ]);
        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ebay_fr',
            'external_listing_id' => '987654321098',
            'status' => 'active',
            'last_api_status' => 'active',
        ]);

        $resolverRow = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'ebay');
        $this->assertSame('✕', $resolverRow['display_icon']);
        $this->assertSame('ebay_end_date_in_past', $resolverRow['reason']);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            return str_contains($url, '123456789012')
                ? Http::response(['itemId' => 'v1|123456789012|0', 'itemEndDate' => '2026-05-31T10:00:00.000Z'], 200)
                : Http::response(['itemId' => 'v1|987654321098|0', 'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200);
        });

        $this->getJson('/admin/tools/ebay/marketplace-diagnose?action=part&part_ids='.$part->id.'&check_api=1&format=json')
            ->assertOk()
            ->assertJsonPath('rows.0.audit_classification', 'ended/stale should_show_x_and_allow_new_publish')
            ->assertJsonPath('rows.0.duplicate_guard_would_block', false)
            ->assertJsonPath('rows.0.resolver_ebay.display_icon', '✕')
            ->assertJsonPath('rows.0.resolver_ebay.reason', 'ebay_end_date_in_past');
    }

    public function test_apply_inactive_is_not_allowed_by_get(): void
    {
        $this->actingAsAdminUser();

        $this->get('/admin/tools/ebay/marketplace-diagnose?action=apply_inactive&check_api=1')
            ->assertStatus(405);
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create(['name' => 'Owner Admin', 'email' => 'owner-ebay@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
