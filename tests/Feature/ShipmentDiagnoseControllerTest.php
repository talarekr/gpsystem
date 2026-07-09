<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_table_discovery_section_returns_candidate_table_details(): void
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

        $response = $this->withoutMiddleware()->getJson('/admin/tools/shipments/diagnose?order_id=153&json=1&section=table_discovery');

        $response->assertOk()
            ->assertJsonPath('code_marker', 'shipment_module_crash_diagnostics_safe_v4')
            ->assertJsonPath('section_only', 'table_discovery')
            ->assertJsonPath('section_result.candidate_tables_checked', $expected)
            ->assertJsonStructure(['section_result' => ['tables' => ['shipments' => ['exists', 'columns', 'relevant_columns_present', 'error']]]]);
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
            ->assertJsonPath('diagnostics_build.candidate_table_list_count', 10)
            ->assertJsonPath('safe_flow_debug.used_direct_app_builder', true)
            ->assertJsonPath('safe_flow_debug.used_direct_table_discovery_builder', true)
            ->assertJsonPath('safe_flow_debug.table_discovery_tables_count', 10)
            ->assertJsonMissingPath('candidate_tables')
            ->assertJsonMissing(['sections_failed' => ['candidate_tables']])
            ->assertJsonStructure(['app' => ['environment', 'php_version', 'laravel_version']])
            ->assertJsonStructure(['table_discovery' => ['tables' => ['shipments' => ['exists', 'columns', 'relevant_columns_present', 'error']]]]);
    }

    public function test_safe_diagnostics_reuses_direct_table_discovery_builder(): void
    {
        $direct = $this->withoutMiddleware()->getJson('/admin/tools/shipments/diagnose?order_id=153&json=1&section=table_discovery');
        $safe = $this->withoutMiddleware()->getJson('/admin/tools/shipments/diagnose?order_id=153&json=1&safe=1');

        $direct->assertOk()
            ->assertJsonPath('section_result.tables.shipments.exists', true);

        $safe->assertOk()
            ->assertJsonPath('table_discovery.tables.shipments.exists', true)
            ->assertJsonPath('safe_flow_debug.used_direct_table_discovery_builder', true);

        $this->assertSame(
            $direct->json('section_result.tables.shipments.exists'),
            $safe->json('table_discovery.tables.shipments.exists')
        );
    }

    public function test_shipments_table_audit_section_uses_real_builder_and_counts_shipments(): void
    {
        DB::table('shipments')->insert([
            'order_id' => null,
            'carrier' => 'DHL',
            'service_code' => 'AH',
            'shipment_status' => 'created',
            'tracking_number' => 'TRACK123',
            'carrier_shipment_id' => 'SHIP123',
            'label_path' => '',
            'label_format' => 'pdf',
            'created_at' => '2026-07-09 12:00:00',
            'updated_at' => '2026-07-09 12:00:00',
        ]);

        $response = $this->withoutMiddleware()->getJson('/admin/tools/shipments/diagnose?order_id=153&json=1&section=shipments_table_audit');

        $response->assertOk()
            ->assertJsonPath('section_only', 'shipments_table_audit')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('section_result.total_count', 1)
            ->assertJsonPath('section_result.audit_debug.used_real_builder', true)
            ->assertJsonPath('section_result.audit_debug.shipments_table_exists', true)
            ->assertJsonPath('section_result.audit_debug.selected_columns', [
                'id',
                'order_id',
                'carrier',
                'service_code',
                'shipment_status',
                'tracking_number',
                'carrier_shipment_id',
                'label_path',
                'label_format',
                'created_at',
                'updated_at',
            ])
            ->assertJsonPath('section_result.audit_debug.count_query_attempted', true)
            ->assertJsonPath('section_result.audit_debug.recent_query_attempted', true)
            ->assertJsonPath('section_result.errors', [])
            ->assertJsonCount(1, 'section_result.recent_records');
    }

}
