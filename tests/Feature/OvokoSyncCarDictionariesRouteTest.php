<?php

namespace Tests\Feature;

use App\Http\Controllers\Tools\OvokoSyncCarDictionariesController;
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

    public function test_sync_car_dictionaries_endpoint_accepts_options_preflight(): void
    {
        $route = Route::getRoutes()->getByName('admin.tools.ovoko.sync-car-dictionaries.options');

        $this->assertNotNull($route);
        $this->assertSame('admin/tools/ovoko/sync-car-dictionaries', $route->uri());
        $this->assertContains('OPTIONS', $route->methods());
    }
}
