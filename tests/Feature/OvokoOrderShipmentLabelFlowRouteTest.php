<?php

namespace Tests\Feature;

use App\Http\Controllers\Tools\OvokoOrderShipmentDiagnoseController;
use App\Http\Controllers\Tools\OvokoOrderShipmentFetchLabelController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OvokoOrderShipmentLabelFlowRouteTest extends TestCase
{
    public function test_ovoko_fetch_label_endpoint_is_registered_for_explicit_post(): void
    {
        $route = Route::getRoutes()->getByName('admin.tools.ovoko.order-shipment-fetch-label');

        $this->assertNotNull($route);
        $this->assertSame('admin/tools/ovoko/order-shipment-fetch-label', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertSame(OvokoOrderShipmentFetchLabelController::class, $route->getAction('uses'));
    }

    public function test_ovoko_label_diagnose_endpoint_reuses_read_only_diagnostics(): void
    {
        $route = Route::getRoutes()->getByName('admin.tools.ovoko.order-shipment-label-diagnose');

        $this->assertNotNull($route);
        $this->assertSame('admin/tools/ovoko/order-shipment-label-diagnose', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertSame(OvokoOrderShipmentDiagnoseController::class, $route->getAction('uses'));
    }
}
