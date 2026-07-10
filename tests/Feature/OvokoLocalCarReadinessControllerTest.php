<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Car;
use App\Models\OvokoCarDictionaryEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoLocalCarReadinessControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_car_readiness_reports_future_import_car_mapping_without_external_calls(): void
    {
        $this->actingAsAdminUser();

        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'brands',
            'ovoko_id' => '1',
            'name' => 'BMW',
            'synced_at' => now(),
        ]);

        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'models',
            'ovoko_id' => '2600',
            'ovoko_brand_id' => '1',
            'name' => '5 E60 E61',
            'year_from' => 2004,
            'year_to' => 2010,
            'synced_at' => now(),
        ]);

        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'car_status',
            'ovoko_id' => '1',
            'name' => 'Kupiony',
            'synced_at' => now(),
        ]);

        $car = Car::query()->create([
            'make' => 'BMW',
            'model' => 'Series 5',
            'model_variant' => '5 E60 E61 (2004 - 2010)',
            'production_year' => 2007,
            'status' => 'kupiony',
            'fuel_type' => 'Benzyna',
            'engine_code' => 'ABC',
            'vin' => 'TESTVIN1234567890',
            'mileage_km' => 98765,
            'legacy_payload' => [
                'ovoko_brand_id' => '1',
                'ovoko_model_group_label' => 'Series 5',
                'ovoko_car_model_id' => '2600',
                'ovoko_status_id' => '1',
                'ovoko_fuel_id' => '2',
                'ovoko_gearbox_type_id' => '0',
                'ovoko_body_type_id' => '1',
                'ovoko_wheel_drive_id' => '1',
            ],
        ]);

        Http::fake(function (): void {
            $this->fail('Local-car readiness must not call Ovoko importCar, importPart, or any external HTTP endpoint.');
        });

        $this->getJson('/admin/tools/ovoko/local-car-ovoko-readiness?car_id='.$car->id.'&json=1')
            ->assertOk()
            ->assertJsonPath('local_car_id', $car->id)
            ->assertJsonPath('ovoko_car_id', null)
            ->assertJsonPath('ovoko_car_id_set', false)
            ->assertJsonPath('ovoko_brand_id', '1')
            ->assertJsonPath('ovoko_model_group_label', 'Series 5')
            ->assertJsonPath('ovoko_car_model_id', '2600')
            ->assertJsonPath('ovoko_car_model_id_exists_in_cache', true)
            ->assertJsonPath('ovoko_status_id', '1')
            ->assertJsonPath('ovoko_status_id_exists_in_cache', true)
            ->assertJsonPath('mapping_ids_exist_in_cache.ovoko_car_model_id', true)
            ->assertJsonPath('mapping_ids_exist_in_cache.ovoko_status_id', true)
            ->assertJsonPath('planned_import_car_payload.car_model', '2600')
            ->assertJsonPath('planned_import_car_payload.car_years', 2007)
            ->assertJsonPath('planned_import_car_payload.status', '1')
            ->assertJsonPath('planned_import_car_payload.car_fuel', '2')
            ->assertJsonPath('planned_import_car_payload.car_engine_code', 'ABC')
            ->assertJsonPath('planned_import_car_payload.vin', 'TESTVIN1234567890')
            ->assertJsonPath('planned_import_car_payload.mileage', 98765)
            ->assertJsonPath('included_optional_fields.car_fuel.reason', 'filled_supported_confirmed_api_param')
            ->assertJsonPath('skipped_optional_fields.gearbox.reason', 'missing_confirmed_api_param')
            ->assertJsonPath('skipped_optional_fields.body_type.reason', 'missing_confirmed_api_param')
            ->assertJsonPath('skipped_optional_fields.wheel_drive.reason', 'missing_confirmed_api_param')
            ->assertJsonPath('ready_for_future_import_car', true)
            ->assertJsonPath('safety_flags.no_import_car', true)
            ->assertJsonPath('safety_flags.no_import_part', true)
            ->assertJsonPath('safety_flags.no_mutation', true);
    }

    public function test_local_car_readiness_lists_missing_year_and_status(): void
    {
        $this->actingAsAdminUser();

        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'models',
            'ovoko_id' => '2600',
            'ovoko_brand_id' => '1',
            'name' => '5 E60 E61',
            'synced_at' => now(),
        ]);

        $car = Car::query()->create([
            'make' => 'BMW',
            'model' => 'Series 5',
            'status' => '',
            'legacy_payload' => [
                'ovoko_brand_id' => '1',
                'ovoko_car_model_id' => '2600',
            ],
        ]);

        Http::fake(function (): void {
            $this->fail('Local-car readiness must remain local-only.');
        });

        $this->getJson('/admin/tools/ovoko/local-car-ovoko-readiness?car_id='.$car->id.'&json=1')
            ->assertOk()
            ->assertJsonPath('ovoko_car_model_id_exists_in_cache', true)
            ->assertJsonPath('ready_for_future_import_car', false)
            ->assertJsonFragment(['car_years'])
            ->assertJsonFragment(['status'])
            ->assertJsonFragment(['ovoko_status_id']);
    }


    public function test_set_car_status_mapping_updates_only_one_car_legacy_payload_status_mapping(): void
    {
        $this->actingAsAdminUser();

        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'models',
            'ovoko_id' => '1391',
            'ovoko_brand_id' => '1',
            'name' => 'Model 1391',
            'synced_at' => now(),
        ]);

        OvokoCarDictionaryEntry::query()->create([
            'dictionary' => 'car_status',
            'ovoko_id' => '1',
            'name' => 'Kupiony',
            'synced_at' => now(),
        ]);

        $car = Car::query()->create([
            'make' => 'Test',
            'model' => 'Car',
            'production_year' => 2007,
            'status' => 'kupiony',
            'fuel_type' => 'Benzyna',
            'engine_code' => 'ABC',
            'vin' => 'TESTVIN1234567890',
            'mileage_km' => 98765,
            'legacy_payload' => [
                'ovoko_brand_id' => '1',
                'ovoko_car_model_id' => '1391',
                'untouched_key' => 'untouched-value',
            ],
        ]);

        $otherCar = Car::query()->create([
            'make' => 'Other',
            'model' => 'Car',
            'status' => 'kupiony',
            'legacy_payload' => ['untouched_key' => 'other-value'],
        ]);

        Http::fake(function (): void {
            $this->fail('Set-status mapping must not call Ovoko importCar, importPart, or any external HTTP endpoint.');
        });

        $this->postJson('/admin/tools/ovoko/cars/set-status-mapping', [
            'car_id' => $car->id,
            'ovoko_status_id' => '1',
            'confirm' => 'set-ovoko-car-status',
        ])
            ->assertOk()
            ->assertJsonPath('marker', 'ovoko_car_status_mapping_readiness_v1')
            ->assertJsonPath('local_car_id', $car->id)
            ->assertJsonPath('ovoko_status_id', '1')
            ->assertJsonPath('readiness.ovoko_status_id', '1')
            ->assertJsonPath('readiness.ovoko_status_id_exists_in_cache', true)
            ->assertJsonPath('readiness.missing_fields_for_future_import_car', [])
            ->assertJsonPath('readiness.ready_for_future_import_car', true)
            ->assertJsonPath('readiness.planned_import_car_payload.status', '1')
            ->assertJsonPath('safety_flags.no_import_car', true)
            ->assertJsonPath('safety_flags.no_import_part', true)
            ->assertJsonPath('safety_flags.no_parts_mutation', true)
            ->assertJsonPath('safety_flags.no_bulk_update', true);

        $this->assertSame([
            'ovoko_brand_id' => '1',
            'ovoko_car_model_id' => '1391',
            'untouched_key' => 'untouched-value',
            'ovoko_status_id' => '1',
        ], $car->fresh()->legacy_payload);
        $this->assertSame(['untouched_key' => 'other-value'], $otherCar->fresh()->legacy_payload);
    }

    public function test_set_car_status_mapping_requires_confirm_and_cached_status(): void
    {
        $this->actingAsAdminUser();

        $car = Car::query()->create([
            'make' => 'Test',
            'model' => 'Car',
            'status' => 'kupiony',
            'legacy_payload' => [],
        ]);

        $this->postJson('/admin/tools/ovoko/cars/set-status-mapping', [
            'car_id' => $car->id,
            'ovoko_status_id' => '1',
            'confirm' => 'wrong-confirm',
        ])->assertUnprocessable()->assertJsonValidationErrors(['confirm']);

        $this->postJson('/admin/tools/ovoko/cars/set-status-mapping', [
            'car_id' => $car->id,
            'ovoko_status_id' => '1',
            'confirm' => 'set-ovoko-car-status',
        ])->assertUnprocessable()->assertJsonValidationErrors(['ovoko_status_id']);

        $this->assertSame([], $car->fresh()->legacy_payload);
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(UserRole::Admin->value);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);

        return $user;
    }
}
