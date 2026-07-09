<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OvokoCarDictionaryEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoCarDictionariesDiagnoseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_search_reads_local_cache_and_reports_model_counts(): void
    {
        $this->actingAsAdminUser();

        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'brands',
            'ovoko_id' => '16',
            'name' => 'BMW',
            'ovoko_brand_id' => '',
            'synced_at' => '2026-07-09 10:11:12',
        ]);
        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'brands',
            'ovoko_id' => '160',
            'name' => 'BMW Alpina',
            'ovoko_brand_id' => '',
            'synced_at' => '2026-07-09 10:12:13',
        ]);
        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'models',
            'ovoko_id' => '3',
            'name' => '3 Series',
            'ovoko_brand_id' => '16',
            'synced_at' => '2026-07-09 10:13:14',
        ]);

        $this->getJson('/admin/tools/ovoko/car-dictionaries-diagnose?json=1&brand_search=bmw')
            ->assertOk()
            ->assertJsonPath('marker', 'ovoko_car_dictionaries_cache_diagnostics_v1')
            ->assertJsonPath('safety_flags.read_only_diagnose', true)
            ->assertJsonPath('safety_flags.no_ovoko_requests_in_get', true)
            ->assertJsonPath('safety_flags.local_cache_only', true)
            ->assertJsonPath('brand_search.query', 'bmw')
            ->assertJsonPath('brand_search.matches.0.ovoko_id', '16')
            ->assertJsonPath('brand_search.matches.0.name', 'BMW')
            ->assertJsonPath('brand_search.matches.0.models_count_in_cache', 1)
            ->assertJsonPath('brand_search.matches.1.ovoko_id', '160')
            ->assertJsonPath('brand_search.matches.1.models_count_in_cache', 0);
    }

    public function test_brand_search_can_be_narrowed_by_brand_id(): void
    {
        $this->actingAsAdminUser();

        OvokoCarDictionaryEntry::query()->create(['dictionary' => 'brands', 'ovoko_id' => '16', 'name' => 'BMW', 'ovoko_brand_id' => '', 'synced_at' => now()]);
        OvokoCarDictionaryEntry::query()->create(['dictionary' => 'brands', 'ovoko_id' => '160', 'name' => 'BMW Alpina', 'ovoko_brand_id' => '', 'synced_at' => now()]);

        $this->getJson('/admin/tools/ovoko/car-dictionaries-diagnose?json=1&brand_search=BMW&brand_id=160')
            ->assertOk()
            ->assertJsonPath('brand_search.query', 'BMW')
            ->assertJsonPath('brand_search.brand_id', '160')
            ->assertJsonCount(1, 'brand_search.matches')
            ->assertJsonPath('brand_search.matches.0.ovoko_id', '160');
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
