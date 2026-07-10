<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Car;
use App\Models\MarketplaceAccount;
use App\Models\OvokoCarDictionaryEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvokoImportCarControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_car_posts_single_local_car_to_ovoko_and_stores_returned_id(): void
    {
        $this->actingAsAdminUser();
        $car = $this->readyCar();
        $this->ovokoAccount();

        Http::fake([
            'https://api.rrr.lt/crm/importCar' => Http::response(['status_code' => 'R200', 'car_id' => 'RRR-499', 'msg' => 'OK'], 200),
            '*' => function () { $this->fail('Only Ovoko importCar may be called.'); },
        ]);

        $this->postJson('/admin/tools/ovoko/import-car', ['car_id' => $car->id, 'confirm' => 'import-ovoko-car'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('local_car_id', $car->id)
            ->assertJsonPath('ovoko_car_id', 'RRR-499')
            ->assertJsonPath('status_code', 'R200')
            ->assertJsonPath('external_id', 'gps-car-'.$car->id)
            ->assertJsonPath('request_payload_without_auth.car_model', '2600')
            ->assertJsonPath('request_payload_without_auth.car_years', 2007)
            ->assertJsonPath('request_payload_without_auth.status', '1')
            ->assertJsonPath('request_payload_without_auth.external_id', 'gps-car-'.$car->id)
            ->assertJsonPath('marker', 'ovoko_import_car_admin_tool_v1');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.rrr.lt/crm/importCar'
            && $request->method() === 'POST'
            && $request['username'] === 'user'
            && $request['password'] === 'pass'
            && $request['user_token'] === 'token'
            && $request['car_model'] === '2600'
            && (int) $request['car_years'] === 2007
            && $request['status'] === '1'
            && $request['external_id'] === 'gps-car-'.$car->id
            && ! isset($request['part_id']));

        $car->refresh();
        $this->assertSame('RRR-499', data_get($car->legacy_payload, 'ovoko_car_id'));
        $this->assertSame('2600', data_get($car->legacy_payload, 'import_car_request_payload.car_model'));
        $this->assertSame('1', data_get($car->legacy_payload, 'import_car_request_payload.status'));
        $this->assertSame('R200', data_get($car->legacy_payload, 'status_code'));
    }

    public function test_import_car_is_blocked_when_local_car_already_has_ovoko_id(): void
    {
        $this->actingAsAdminUser();
        $car = $this->readyCar(['ovoko_car_id' => 'EXISTING-1']);
        $this->ovokoAccount();
        Http::fake(function (): void { $this->fail('Already-imported cars must not call Ovoko.'); });

        $this->postJson('/admin/tools/ovoko/import-car', ['car_id' => $car->id, 'confirm' => 'import-ovoko-car'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('reason', 'local_car_already_has_ovoko_car_id')
            ->assertJsonPath('ovoko_car_id', 'EXISTING-1');
    }

    public function test_readiness_after_imported_car_marks_payload_as_already_imported(): void
    {
        $this->actingAsAdminUser();
        $car = $this->readyCar(['ovoko_car_id' => 'RRR-499']);

        $this->getJson('/admin/tools/ovoko/local-car-ovoko-readiness?car_id='.$car->id.'&json=1')
            ->assertOk()
            ->assertJsonPath('ovoko_car_id_set', true)
            ->assertJsonPath('ovoko_car_id', 'RRR-499')
            ->assertJsonPath('ready_for_future_import_car', false)
            ->assertJsonPath('planned_import_car_payload.already_imported', true)
            ->assertJsonPath('safety_flags.read_only', true);
    }

    private function readyCar(array $legacyOverrides = []): Car
    {
        OvokoCarDictionaryEntry::query()->firstOrCreate(['dictionary' => 'brands', 'ovoko_id' => '1', 'ovoko_brand_id' => ''], ['name' => 'BMW', 'synced_at' => now()]);
        OvokoCarDictionaryEntry::query()->firstOrCreate(['dictionary' => 'models', 'ovoko_id' => '2600', 'ovoko_brand_id' => '1'], ['name' => '5 E60 E61', 'year_from' => 2004, 'year_to' => 2010, 'synced_at' => now()]);
        OvokoCarDictionaryEntry::query()->firstOrCreate(['dictionary' => 'car_status', 'ovoko_id' => '1', 'ovoko_brand_id' => ''], ['name' => 'Kupiony', 'synced_at' => now()]);

        return Car::query()->create([
            'make' => 'BMW',
            'model' => 'Series 5',
            'production_year' => 2007,
            'status' => 'kupiony',
            'legacy_payload' => array_merge([
                'ovoko_brand_id' => '1',
                'ovoko_model_group_label' => 'Series 5',
                'ovoko_car_model_id' => '2600',
                'ovoko_status_id' => '1',
            ], $legacyOverrides),
        ]);
    }

    private function ovokoAccount(): MarketplaceAccount
    {
        return MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'name' => 'Ovoko main',
            'code' => 'ovoko_main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.rrr.lt',
            'api_mode' => 'live',
            'api_credentials' => ['username' => 'user', 'password' => 'pass', 'user_token' => 'token'],
        ]);
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
