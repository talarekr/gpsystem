<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OvokoCarDictionaryEntry;
use App\Models\User;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
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
            ->assertJsonPath('import_car_model_requirements.source', 'official_docs_and_static_code_review')
            ->assertJsonPath('import_car_model_requirements.required_fields_from_docs', ['car_model'])
            ->assertJsonPath('import_car_model_requirements.car_model_field_meaning', 'modification_or_generation')
            ->assertJsonPath('import_car_model_requirements.car_model_expected_source', '/get/car_models/{brand_id}')
            ->assertJsonPath('import_car_model_requirements.separate_general_model_field_found', false)
            ->assertJsonPath('import_car_model_requirements.separate_modification_field_found', false)
            ->assertJsonPath('import_car_model_requirements.separate_general_model_endpoint_found', false)
            ->assertJsonPath('import_car_model_requirements.separate_modification_endpoint_found', false)
            ->assertJsonPath('import_car_model_requirements.docs_example.endpoint', 'POST /crm/importCar')
            ->assertJsonPath('import_car_model_requirements.docs_example.fields.car_model', '1548')
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

    public function test_include_model_groups_builds_read_only_local_cache_heuristic_groups(): void
    {
        $this->actingAsAdminUser();

        $brand = OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'brands',
            'ovoko_id' => '1',
            'name' => 'BMW',
            'synced_at' => now(),
        ]);

        foreach ([
            ['ovoko_id' => '1548', 'name' => '3 E46', 'year_from' => 1998, 'year_to' => 2005],
            ['ovoko_id' => '2600', 'name' => '5 E60 E61', 'year_from' => 2004, 'year_to' => 2010],
            ['ovoko_id' => '2601', 'name' => '5 F10', 'year_from' => 2010, 'year_to' => 2017],
            ['ovoko_id' => '3000', 'name' => 'X4 F26', 'year_from' => 2014, 'year_to' => 2017],
            ['ovoko_id' => '3100', 'name' => 'X4M F98', 'year_from' => 2019, 'year_to' => null],
            ['ovoko_id' => '3200', 'name' => 'M5 F90', 'year_from' => 2017, 'year_to' => 2023],
            ['ovoko_id' => '3300', 'name' => '1500 2500', 'year_from' => 1962, 'year_to' => 1977],
        ] as $model) {
            OvokoCarDictionaryEntry::query()->create([
                'dictionary' => 'models',
                'ovoko_id' => $model['ovoko_id'],
                'ovoko_brand_id' => $brand->ovoko_id,
                'name' => $model['name'],
                'year_from' => $model['year_from'],
                'year_to' => $model['year_to'],
                'synced_at' => now(),
            ]);
        }

        Http::fake(function (): void {
            $this->fail('Model grouping diagnostics must remain local-cache-only and must not call external HTTP endpoints.');
        });

        $this->getJson('/admin/tools/ovoko/car-dictionaries-diagnose?json=1&brand_search=BMW&brand_id=1&models_limit=20&include_raw=1&include_model_groups=1')
            ->assertOk()
            ->assertJsonPath('model_groups.ovoko_brand_id', '1')
            ->assertJsonPath('model_groups.brand_name', 'BMW')
            ->assertJsonPath('model_groups.source', 'local_cache_heuristic_from_model_name')
            ->assertJsonPath('model_groups.groups_count', 6)
            ->assertJsonFragment([
                'model_group_key' => '5',
                'model_group_label' => 'Series 5',
                'confidence' => 'heuristic',
                'modification_count' => 2,
            ])
            ->assertJsonFragment([
                'ovoko_id' => '2600',
                'name' => '5 E60 E61',
                'display_name' => '5 E60 E61 (2004 - 2010)',
                'year_start' => '2004',
                'year_end' => '2010',
            ])
            ->assertJsonFragment([
                'model_group_key' => 'X4',
                'model_group_label' => 'X4',
                'confidence' => 'heuristic',
                'modification_count' => 1,
            ])
            ->assertJsonFragment([
                'model_group_key' => 'X4M',
                'model_group_label' => 'X4M',
                'confidence' => 'heuristic',
                'modification_count' => 1,
            ])
            ->assertJsonFragment([
                'model_group_key' => 'M5',
                'model_group_label' => 'M5',
                'confidence' => 'heuristic',
                'modification_count' => 1,
            ])
            ->assertJsonFragment([
                'model_group_key' => '1500 2500',
                'model_group_label' => '1500 2500',
                'confidence' => 'low',
            ])
            ->assertJsonPath('model_groups.uncertain_groups.0.reason', 'numeric_historical_or_ambiguous_name')
            ->assertJsonPath('safety_flags.read_only_diagnose', true)
            ->assertJsonPath('safety_flags.no_import_car', true)
            ->assertJsonPath('safety_flags.no_import_part', true);
    }

    public function test_model_sync_cache_maps_year_start_and_year_end_to_top_level_year_columns(): void
    {
        $service = app(OvokoCarDictionaryService::class);
        $method = new \ReflectionMethod($service, 'storeRows');
        $method->setAccessible(true);

        $stored = $method->invoke($service, 'models', [[
            'id' => '1548',
            'name' => '3 E46',
            'year_start' => '1998',
            'year_end' => '',
        ], [
            'id' => '2600',
            'name' => '5 E60 E61',
            'year_start' => '2004',
            'year_end' => '2010',
        ]], '1');

        $this->assertSame(2, $stored);
        $this->assertDatabaseHas('ovoko_car_dictionary_entries', [
            'dictionary' => 'models',
            'ovoko_id' => '1548',
            'ovoko_brand_id' => '1',
            'year_from' => 1998,
            'year_to' => null,
        ]);
        $this->assertDatabaseHas('ovoko_car_dictionary_entries', [
            'dictionary' => 'models',
            'ovoko_id' => '2600',
            'ovoko_brand_id' => '1',
            'year_from' => 2004,
            'year_to' => 2010,
        ]);
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
