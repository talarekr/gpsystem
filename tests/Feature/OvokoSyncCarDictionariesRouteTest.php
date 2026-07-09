<?php

namespace Tests\Feature;

use App\Http\Controllers\Tools\OvokoSyncCarDictionariesController;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OvokoSyncCarDictionariesRouteTest extends TestCase
{
    public function test_sync_car_dictionaries_endpoint_is_registered_for_post(): void
    {
        $route = Route::getRoutes()->getByName('admin.tools.ovoko.sync-car-dictionaries');

        $this->assertNotNull($route);
        $this->assertSame('admin/tools/ovoko/sync-car-dictionaries', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertSame(OvokoSyncCarDictionariesController::class, $route->getAction('uses'));
    }

    public function test_sync_car_dictionaries_endpoint_accepts_json_payload_used_by_browser_fetch(): void
    {
        $this->app->instance(OvokoCarDictionaryService::class, new class extends OvokoCarDictionaryService
        {
            public function sync(string $scope, ?string $brandId = null): array
            {
                return [
                    'scope' => $scope,
                    'brand_id' => $brandId,
                    'synced' => ['models' => [$brandId => 2]],
                    'errors' => [],
                    'models_mode' => 'per_brand',
                    'safety_flags' => ['no_import_car' => true, 'no_import_part' => true],
                ];
            }
        });

        $this->postJson('/admin/tools/ovoko/sync-car-dictionaries', [
            'scope' => 'models',
            'brand_id' => '1',
            'confirm' => OvokoCarDictionaryService::CONFIRM,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('scope', 'models')
            ->assertJsonPath('brand_id', '1')
            ->assertJsonPath('synced.models.1', 2)
            ->assertJsonPath('safety_flags.no_import_car', true)
            ->assertJsonPath('safety_flags.no_import_part', true);
    }

    public function test_sync_car_dictionaries_endpoint_blocks_json_payload_without_confirm_token(): void
    {
        $this->postJson('/admin/tools/ovoko/sync-car-dictionaries', [
            'scope' => 'models',
            'brand_id' => '1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('reason', 'missing_confirm_token')
            ->assertJsonPath('expected_confirm', OvokoCarDictionaryService::CONFIRM);
    }

    public function test_sync_car_dictionaries_endpoint_accepts_options_preflight(): void
    {
        $route = Route::getRoutes()->getByName('admin.tools.ovoko.sync-car-dictionaries.options');

        $this->assertNotNull($route);
        $this->assertSame('admin/tools/ovoko/sync-car-dictionaries', $route->uri());
        $this->assertContains('OPTIONS', $route->methods());
    }
}
