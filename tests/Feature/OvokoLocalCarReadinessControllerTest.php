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

        $car = Car::query()->create([
            'make' => 'BMW',
            'model' => 'Series 5',
            'model_variant' => '5 E60 E61 (2004 - 2010)',
            'production_year' => 2007,
            'status' => 'kupiony',
            'legacy_payload' => [
                'ovoko_brand_id' => '1',
                'ovoko_model_group_label' => 'Series 5',
                'ovoko_car_model_id' => '2600',
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
            ->assertJsonPath('mapping_ids_exist_in_cache.ovoko_car_model_id', true)
            ->assertJsonPath('planned_import_car_payload.car_model', '2600')
            ->assertJsonPath('planned_import_car_payload.car_years', 2007)
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
            ->assertJsonFragment(['status']);
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
