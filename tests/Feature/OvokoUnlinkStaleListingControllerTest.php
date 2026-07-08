<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoUnlinkStaleListingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_marks_single_listing_ignored_for_publish_and_preserves_external_reference(): void
    {
        $this->actingAsAdminUser();

        $part = Part::query()->create(['name' => 'Ovoko stale', 'sku' => 'OV-STALE-1', 'quantity' => 1, 'status' => 'draft', 'needs_listing' => true]);
        $listing = MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ovoko',
            'external_offer_id' => '11419',
            'external_listing_id' => '11419',
            'status' => 'imported',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
            'url' => 'https://ovoko.pl/czesci-samochodowe/hgf11419',
        ]);

        $this->postJson('/admin/tools/ovoko/unlink-stale-listing?json=1', [
            'part_id' => $part->id,
            'marketplace_listing_id' => $listing->id,
            'confirm' => 'unlink-stale-ovoko-listing',
        ])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('changed.0.marketplace_listing_id', $listing->id)
            ->assertJsonPath('after.marketplace_listings.0.ignored_for_publish', true)
            ->assertJsonPath('after.duplicate_guard_currently_would_block', false)
            ->assertJsonPath('safety.ovoko_write', false)
            ->assertJsonPath('safety.remote_delete', false)
            ->assertJsonPath('safety.local_only', true);

        $listing->refresh();
        $this->assertSame('historical', $listing->status);
        $this->assertSame('stale', $listing->sync_status);
        $this->assertSame('11419', $listing->external_offer_id);
        $this->assertSame('https://ovoko.pl/czesci-samochodowe/hgf11419', $listing->url);
        $this->assertTrue((bool) data_get($listing->raw_payload, 'metadata.ovoko_unlinked_for_republish'));
        $this->assertSame(1, MarketplaceSyncLog::query()->where('action', 'ovoko_unlink_stale_listing_for_republish')->count());
    }


    public function test_resolver_hides_ignored_ovoko_listing_as_current_link_but_keeps_historical_url(): void
    {
        $part = Part::query()->create([
            'name' => 'Ovoko stale history',
            'sku' => 'OV-HIST-1',
            'quantity' => 1,
            'status' => 'ready',
            'needs_listing' => true,
            'ovoko_price' => 10,
        ]);

        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ovoko',
            'external_offer_id' => '11419',
            'external_listing_id' => '11419',
            'status' => 'historical',
            'sync_status' => 'stale',
            'match_status' => 'confirmed',
            'url' => 'https://ovoko.pl/czesci-samochodowe/hgf11419',
            'raw_payload' => ['metadata' => ['ovoko_unlinked_for_republish' => true]],
        ]);

        $row = collect(app(\App\Services\Admin\PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))
            ->firstWhere('key', 'ovoko');

        $this->assertFalse($row['has_link']);
        $this->assertNull($row['url']);
        $this->assertNull($row['current_url']);
        $this->assertSame('https://ovoko.pl/czesci-samochodowe/hgf11419', $row['historical_url']);
        $this->assertFalse($row['is_active']);
        $this->assertSame('✕', $row['display_icon']);
        $this->assertSame('x', $row['icon']);
        $this->assertTrue($row['stale_history_listing_detected']);
    }

    public function test_apply_returns_readable_json_error_instead_of_blank_500(): void
    {
        $this->actingAsAdminUser();

        $this->postJson('/admin/tools/ovoko/unlink-stale-listing?json=1', [
            'part_id' => 7567,
            'marketplace_listing_id' => 5753,
            'confirm' => 'wrong-confirm',
        ])
            ->assertStatus(422)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('error', true)
            ->assertJsonPath('failed_step', 'validate_request')
            ->assertJsonPath('part_id', 7567)
            ->assertJsonPath('marketplace_listing_id', 5753)
            ->assertJsonStructure(['exception_class', 'message', 'failed_step', 'part_id', 'marketplace_listing_id']);
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Owner Admin',
            'email' => 'owner-unlink@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
