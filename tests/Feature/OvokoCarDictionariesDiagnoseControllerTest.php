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

    public function test_models_sample_can_target_brand_id_and_models_limit_without_external_calls(): void
    {
        $this->actingAsAdminUser();

        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'brands',
            'ovoko_id' => '142',
            'name' => 'AC',
            'synced_at' => now(),
        ]);
        $brand = OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'brands',
            'ovoko_id' => '1',
            'name' => 'BMW',
            'synced_at' => now(),
        ]);

        for ($i = 1; $i <= 25; $i++) {
            OvokoCarDictionaryEntry::query()->create([
                'dictionary' => 'models',
                'ovoko_id' => (string) (1000 + $i),
                'ovoko_brand_id' => $brand->ovoko_id,
                'name' => sprintf('BMW Model %02d', $i),
                'synced_at' => now(),
            ]);
        }

        Http::fake(function (): void {
            $this->fail('Diagnostics must remain local-cache-only and must not call Ovoko or any external HTTP endpoint.');
        });

        $this->getJson('/admin/tools/ovoko/car-dictionaries-diagnose?json=1&brand_search=BMW&brand_id=1&models_limit=20')
            ->assertOk()
            ->assertJsonPath('samples.models_for_brand.ovoko_brand_id', '1')
            ->assertJsonPath('samples.models_for_brand.brand_name', 'BMW')
            ->assertJsonPath('samples.models_for_brand.models_count', 25)
            ->assertJsonCount(20, 'samples.models_for_brand.models')
            ->assertJsonPath('samples.models_for_brand.models.0.ovoko_brand_id', '1')
            ->assertJsonPath('safety_flags.read_only_diagnose', true)
            ->assertJsonPath('safety_flags.no_import_car', true)
            ->assertJsonPath('safety_flags.no_import_part', true)
            ->assertJsonPath('safety_flags.no_parts_mutation', true)
            ->assertJsonPath('safety_flags.no_local_cars_mutation', true);
    }

    public function test_include_raw_adds_cached_model_raw_payload_without_external_calls(): void
    {
        $this->actingAsAdminUser();

        $brand = OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'brands',
            'ovoko_id' => '1',
            'name' => 'BMW',
            'synced_at' => now(),
        ]);

        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'models',
            'ovoko_id' => '1548',
            'ovoko_brand_id' => $brand->ovoko_id,
            'name' => '3 E46',
            'raw_payload' => ['id' => '1548', 'name' => '3 E46', 'model' => 'Series 3', 'parent_id' => '3'],
            'synced_at' => now(),
        ]);

        Http::fake(function (): void {
            $this->fail('Diagnostics with include_raw must remain local-cache-only and must not call external HTTP endpoints.');
        });

        $this->getJson('/admin/tools/ovoko/car-dictionaries-diagnose?json=1&brand_search=BMW&brand_id=1&models_limit=20&include_raw=1')
            ->assertOk()
            ->assertJsonPath('samples.models_for_brand.models.0.ovoko_id', '1548')
            ->assertJsonPath('samples.models_for_brand.models.0.name', '3 E46')
            ->assertJsonPath('samples.models_for_brand.models.0.raw_payload.model', 'Series 3')
            ->assertJsonPath('samples.models_for_brand.models.0.raw_payload.parent_id', '3')
            ->assertJsonPath('model_modification_diagnostics.static_code_review.separate_general_model_endpoint_found', false)
            ->assertJsonPath('model_modification_diagnostics.static_code_review.cached_raw_payload_available_for_review', true)
            ->assertJsonPath('safety_flags.read_only_diagnose', true)
            ->assertJsonPath('safety_flags.no_import_car', true)
            ->assertJsonPath('safety_flags.no_import_part', true)
            ->assertJsonPath('safety_flags.no_parts_mutation', true)
            ->assertJsonPath('safety_flags.no_local_cars_mutation', true);
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
