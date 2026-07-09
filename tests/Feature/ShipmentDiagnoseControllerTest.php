<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentDiagnoseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_tables_section_returns_shared_candidate_list(): void
    {
        $expected = [
            'shipments',
            'shipment_labels',
            'shipping_labels',
            'order_shipments',
            'orders',
            'marketplace_sync_logs',
            'api_integration_logs',
            'integration_logs',
            'order_logs',
            'labels',
        ];

        $response = $this->withoutMiddleware()->getJson('/admin/tools/shipments/diagnose?order_id=153&json=1&section=candidate_tables');

        $response->assertOk()
            ->assertJsonPath('code_marker', 'shipment_module_crash_diagnostics_safe_v4_candidate_direct')
            ->assertJsonPath('section_only', 'candidate_tables')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('section_result.candidate_tables_checked', $expected)
            ->assertJsonPath('section_result.count', 10)
            ->assertJsonPath('errors', []);
    }

    public function test_app_section_returns_direct_app_payload(): void
    {
        $response = $this->withoutMiddleware()->getJson('/admin/tools/shipments/diagnose?order_id=153&json=1&section=app');

        $response->assertOk()
            ->assertJsonPath('code_marker', 'shipment_module_crash_diagnostics_safe_v4')
            ->assertJsonPath('section_only', 'app')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('errors', [])
            ->assertJsonPath('diagnostics_health.sections_completed', ['app'])
            ->assertJsonStructure(['section_result' => ['environment', 'php_version', 'laravel_version']]);
    }

    public function test_input_section_returns_direct_input_payload(): void
    {
        $response = $this->withoutMiddleware()->getJson('/admin/tools/shipments/diagnose?order_id=153&json=1&safe=1&section=input&until=app');

        $response->assertOk()
            ->assertJsonPath('code_marker', 'shipment_module_crash_diagnostics_safe_v4')
            ->assertJsonPath('section_only', 'input')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('section_result.order_id', 153)
            ->assertJsonPath('section_result.safe', true)
            ->assertJsonPath('section_result.section', 'input')
            ->assertJsonPath('section_result.until', 'app')
            ->assertJsonPath('errors', [])
            ->assertJsonPath('diagnostics_health.sections_completed', ['input']);
    }

    public function test_safe_diagnostics_uses_same_candidate_table_list_for_build_and_discovery(): void
    {
        $expected = [
            'shipments',
            'shipment_labels',
            'shipping_labels',
            'order_shipments',
            'orders',
            'marketplace_sync_logs',
            'api_integration_logs',
            'integration_logs',
            'order_logs',
            'labels',
        ];

        $response = $this->withoutMiddleware()->getJson('/admin/tools/shipments/diagnose?order_id=153&json=1&safe=1');

        $response->assertOk()
            ->assertJsonPath('code_marker', 'shipment_module_crash_diagnostics_safe_v4')
            ->assertJsonPath('table_discovery.candidate_tables_checked', $expected)
            ->assertJsonPath('candidate_tables.candidate_tables_checked', $expected)
            ->assertJsonPath('candidate_tables.count', 10)
            ->assertJsonPath('diagnostics_build.candidate_table_list_count', 10);
    }
}
