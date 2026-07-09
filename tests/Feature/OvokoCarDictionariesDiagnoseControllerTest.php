<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OvokoCarDictionaryEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoCarDictionariesDiagnoseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_search_reads_local_cache_and_reports_model_modification_diagnostics(): void
    {
        $this->actingAsAdminUser();

        $brand = OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'brands',
            'ovoko_id' => '16',
            'name' => 'BMW',
            'synced_at' => now(),
        ]);
        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'models',
            'ovoko_id' => '500',
            'ovoko_brand_id' => $brand->ovoko_id,
            'name' => 'Series 5',
            'synced_at' => now(),
        ]);

        Http::fake(function (): void {
            $this->fail('Diagnostics must not call Ovoko or any external HTTP endpoint.');
        });

        $this->getJson('/admin/tools/ovoko/car-dictionaries-diagnose?json=1&brand_search=BMW')
            ->assertOk()
            ->assertJsonPath('marker', 'ovoko_car_dictionaries_cache_diagnostics_v1')
            ->assertJsonPath('brand_search.query', 'BMW')
            ->assertJsonPath('brand_search.matches.0.ovoko_id', '16')
            ->assertJsonPath('brand_search.matches.0.name', 'BMW')
            ->assertJsonPath('brand_search.matches.0.models_count_in_cache', 1)
            ->assertJsonPath('model_modification_diagnostics.known_endpoint', null)
            ->assertJsonPath('model_modification_diagnostics.car_models_endpoint', '/get/car_models/{brand_id}')
            ->assertJsonPath('model_modification_diagnostics.car_models_endpoint_may_represent', 'unknown')
            ->assertJsonPath('safety_flags.read_only_diagnose', true)
            ->assertJsonPath('safety_flags.no_import_car', true)
            ->assertJsonPath('safety_flags.no_import_part', true);
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
