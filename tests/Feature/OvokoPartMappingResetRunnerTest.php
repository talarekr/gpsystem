<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoPartMappingResetRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAsAdminUser();
    }

    public function test_dry_run_completes_without_mutation(): void
    {
        $candidate = $this->part('GPS-GMAIL-1');
        $listing = $this->ovoko($candidate, 'OV-1');

        $this->postJson('/admin/tools/ovoko/part-mapping-reset-runner/start', ['mode' => 'dry_run', 'batch_size' => 10, 'delay_seconds' => 1, 'confirm' => 'start-ovoko-part-mapping-reset-runner'])->assertOk()->assertJsonPath('total_candidates', 1);
        $this->postJson('/admin/tools/ovoko/part-mapping-reset-runner/run-next-batch', ['confirm' => 'run-ovoko-part-mapping-reset-runner-batch'])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('dry_run_count', 1)->assertJsonPath('reset_count', 0);

        $this->assertSame('OV-1', $listing->fresh()->external_offer_id);
    }

    public function test_live_resets_only_strict_candidates_and_preserves_other_marketplaces(): void
    {
        $candidate = $this->part('GPS-GMAIL-2');
        $ovoko = $this->ovoko($candidate, 'OV-2');
        $allegro = MarketplaceListing::query()->create(['part_id' => $candidate->id, 'marketplace' => 'allegro', 'external_offer_id' => 'A-2', 'url' => 'https://allegro.test/A-2', 'status' => 'ACTIVE']);
        $priced = $this->part('GPS-GMAIL-PRICED', ['price' => 10]);
        $pricedListing = $this->ovoko($priced, 'OV-PRICE');
        $menu = $this->part('GPS-GMAIL-MENU', ['is_visible_storefront' => true]);
        $menuListing = $this->ovoko($menu, 'OV-MENU');
        $published = $this->part('GPS-GMAIL-PUB', ['status' => 'published']);
        $publishedListing = $this->ovoko($published, 'OV-PUB', ['status' => 'published']);
        $nonOvoko = $this->part('GPS-GMAIL-OTHER');
        $otherListing = MarketplaceListing::query()->create(['part_id' => $nonOvoko->id, 'marketplace' => 'ebay', 'external_offer_id' => 'E-1', 'sku' => 'GPS-GMAIL-OTHER', 'url' => 'https://ebay.test/E-1', 'status' => 'imported']);

        $this->postJson('/admin/tools/ovoko/part-mapping-reset-runner/start', ['mode' => 'live', 'batch_size' => 25, 'delay_seconds' => 1, 'confirm' => 'start-ovoko-part-mapping-reset-runner'])->assertOk()->assertJsonPath('total_candidates', 1);
        $this->postJson('/admin/tools/ovoko/part-mapping-reset-runner/run-next-batch', ['confirm' => 'run-ovoko-part-mapping-reset-runner-batch'])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('reset_count', 1);

        $ovoko->refresh();
        $this->assertNull($ovoko->external_offer_id);
        $this->assertNull($ovoko->url);
        $this->assertSame('unlinked', $ovoko->status);
        $this->assertSame('OV-2', data_get($ovoko->raw_payload, 'metadata.previous_external_offer_id'));
        $this->assertSame('A-2', $allegro->fresh()->external_offer_id);
        $this->assertSame('OV-PRICE', $pricedListing->fresh()->external_offer_id);
        $this->assertSame('OV-MENU', $menuListing->fresh()->external_offer_id);
        $this->assertSame('OV-PUB', $publishedListing->fresh()->external_offer_id);
        $this->assertSame('E-1', $otherListing->fresh()->external_offer_id);
    }

    public function test_batch_state_tracks_multiple_batches(): void
    {
        $this->ovoko($this->part('GPS-GMAIL-B1'), 'OV-B1');
        $this->ovoko($this->part('GPS-GMAIL-B2'), 'OV-B2');
        $this->postJson('/admin/tools/ovoko/part-mapping-reset-runner/start', ['mode' => 'dry_run', 'batch_size' => 1, 'delay_seconds' => 1, 'confirm' => 'start-ovoko-part-mapping-reset-runner'])->assertOk()->assertJsonPath('remaining', 2);
        $this->postJson('/admin/tools/ovoko/part-mapping-reset-runner/run-next-batch', ['confirm' => 'run-ovoko-part-mapping-reset-runner-batch'])->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('remaining', 1)->assertJsonPath('status', 'running');
        $this->postJson('/admin/tools/ovoko/part-mapping-reset-runner/run-next-batch', ['confirm' => 'run-ovoko-part-mapping-reset-runner-batch'])->assertOk()->assertJsonPath('processed', 2)->assertJsonPath('remaining', 0)->assertJsonPath('status', 'completed');
    }

    private function part(string $sku, array $overrides = []): Part
    {
        return Part::query()->create(array_merge(['name' => $sku, 'sku' => $sku, 'quantity' => 1, 'status' => 'imported', 'price' => null, 'ovoko_price' => null, 'is_visible_storefront' => false, 'needs_listing' => true], $overrides));
    }

    private function ovoko(Part $part, string $id, array $overrides = []): MarketplaceListing
    {
        return MarketplaceListing::query()->create(array_merge(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => $id, 'external_listing_id' => $id, 'external_inventory_id' => $id, 'sku' => $part->sku, 'url' => 'https://ovoko.example.test/'.$id, 'status' => 'imported', 'sync_status' => 'mapped', 'match_status' => 'confirmed'], $overrides));
    }

    private function actingAsAdminUser(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $user->assignRole(UserRole::Admin->value);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);
        return $user;
    }
}
