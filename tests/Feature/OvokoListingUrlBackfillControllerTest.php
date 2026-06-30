<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoListingUrlBackfillControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_admin_endpoint_accepts_limit_above_1000_up_to_6500(): void
    {
        $this->actingAsAdmin();

        $now = now();
        $rows = [];
        for ($i = 1; $i <= 1001; $i++) {
            $rows[] = [
                'marketplace' => 'ovoko',
                'external_offer_id' => (string) (100000 + $i),
                'url' => 'https://ovoko.pl/czesci-samochodowe/hgf'.(100000 + $i),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('marketplace_listings')->insert($chunk);
        }

        $this->getJson('/admin/tools/marketplace/ovoko-url-backfill?limit=6500&only_missing=1')
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('apply_confirmed', false)
            ->assertJsonPath('limit', 6500)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('crm_import_part', false)
            ->assertJsonPath('publish', false)
            ->assertJsonPath('stock_order_price_sync', false)
            ->assertJsonPath('summary.inspected', 1001)
            ->assertJsonPath('summary.already_has_url', 1001)
            ->assertJsonPath('summary.skipped', 1001);
    }

    public function test_bulk_admin_endpoint_caps_limit_at_6500(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/admin/tools/marketplace/ovoko-url-backfill?limit=999999&only_missing=1')
            ->assertOk()
            ->assertJsonPath('limit', 6500)
            ->assertJsonPath('dry_run', true);
    }

    private function actingAsAdmin(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Owner Admin',
            'email' => 'owner-admin@example.test',
            'password' => 'password',
        ]);
        $user->assignRole(UserRole::OwnerAdmin->value);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
