<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Marketplace\OvokoListingUrlBackfillService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoListingUrlBackfillControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_admin_endpoint_accepts_6500_limit_for_dry_run(): void
    {
        $this->actingAsAdminUser();

        $this->mock(OvokoListingUrlBackfillService::class, function ($mock): void {
            $mock->shouldReceive('runLocalGeneratedBulk')
                ->once()
                ->withArgs(fn (bool $apply, int $limit, bool $onlyMissing, bool $includeExistingInvalid): bool =>
                    $apply === false
                    && $limit === 6500
                    && $onlyMissing === true
                    && $includeExistingInvalid === false
                )
                ->andReturn([
                    'mode' => 'dry_run',
                    'summary' => ['inspected' => 6500],
                    'results' => [],
                    'warnings' => [],
                ]);
        });

        $this->getJson('/admin/tools/marketplace/ovoko-url-backfill?limit=6500&only_missing=1')
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('apply_requested', false)
            ->assertJsonPath('apply_confirmed', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('crm_import_part', false)
            ->assertJsonPath('publish', false)
            ->assertJsonPath('stock_order_price_sync', false)
            ->assertJsonPath('summary.inspected', 6500);
    }

    public function test_diagnostic_endpoint_still_caps_limit_at_1000(): void
    {
        $this->actingAsAdminUser();

        $this->mock(OvokoListingUrlBackfillService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (
                    bool $apply,
                    bool $force,
                    ?int $partId,
                    int $limit,
                    ?string $csvPath,
                    ?int $listingId,
                    int $maxPages
                ): bool => $apply === false && $force === false && $partId === 123 && $limit === 1000 && $csvPath === null && $listingId === null && $maxPages === 3)
                ->andReturn([
                    'mode' => 'dry_run',
                    'summary' => ['inspected' => 1000],
                    'results' => [],
                    'warnings' => [],
                ]);
        });

        $this->getJson('/admin/tools/marketplace/ovoko-url-backfill?part_id=123&limit=6500')
            ->assertOk()
            ->assertJsonPath('summary.inspected', 1000);
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
