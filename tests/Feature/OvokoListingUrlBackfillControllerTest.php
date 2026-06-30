<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Marketplace\OvokoListingUrlBackfillService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                ->withArgs(fn (bool $apply, int $limit, int $offset, bool $onlyMissing, bool $includeExistingInvalid): bool =>
                    $apply === false
                    && $limit === 6500
                    && $offset === 0
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


    public function test_bulk_offset_returns_distinct_non_overlapping_diagnostic_ranges(): void
    {
        $now = now();
        $rows = [];

        for ($id = 1; $id <= 1005; $id++) {
            $rows[] = [
                'id' => $id,
                'marketplace' => 'ovoko',
                'part_id' => null,
                'external_offer_id' => (string) (100000 + $id),
                'url' => 'https://ovoko.pl/czesci-samochodowe/hgf'.(100000 + $id),
                'currency' => 'PLN',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('marketplace_listings')->insert($chunk);
        }

        $service = app(OvokoListingUrlBackfillService::class);

        $firstRange = $service->runLocalGeneratedBulk(apply: false, limit: 10, offset: 0, onlyMissing: true);
        $secondRange = $service->runLocalGeneratedBulk(apply: false, limit: 10, offset: 1000, onlyMissing: true);

        $this->assertSame(1, $firstRange['first_inspected_listing_id']);
        $this->assertSame(10, $firstRange['last_inspected_listing_id']);
        $this->assertSame(1001, $secondRange['first_inspected_listing_id']);
        $this->assertSame(1005, $secondRange['last_inspected_listing_id']);
        $this->assertSame(0, $firstRange['offset_applied']);
        $this->assertSame(1000, $secondRange['offset_applied']);
        $this->assertSame(1005, $firstRange['total_ovoko_listings_count']);
        $this->assertSame(0, $firstRange['total_ovoko_missing_url_count']);
        $this->assertEmpty(array_intersect($firstRange['inspected_listing_ids_sample'], $secondRange['inspected_listing_ids_sample']));
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
