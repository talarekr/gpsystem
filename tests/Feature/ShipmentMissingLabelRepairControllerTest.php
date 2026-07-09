<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Services\Shipments\DhlShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShipmentMissingLabelRepairControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_confirm_returns_preview_without_mutation(): void
    {
        Storage::fake('local');
        $shipment = $this->createMissingLabelShipment();

        $response = $this->withoutMiddleware()->postJson('/admin/tools/shipments/repair-missing-label', [
            'shipment_id' => $shipment->id,
            'order_id' => 153,
            'mode' => 'mark_label_missing_and_keep_tracking',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code_marker', 'dhl_missing_label_local_repair_v1')
            ->assertJsonPath('preview_only', true)
            ->assertJsonPath('shipment_id', $shipment->id)
            ->assertJsonPath('order_id', 153)
            ->assertJsonPath('tracking_number', '31294120912')
            ->assertJsonPath('label_path', 'shipments/labels/dhl/31294120912.pdf')
            ->assertJsonPath('label_file_exists', false)
            ->assertJsonPath('qualifies_for_repair', true)
            ->assertJsonPath('repair_action', 'mark_label_missing_and_keep_tracking')
            ->assertJsonPath('createShipment_called', false)
            ->assertJsonPath('getLabels_called', false);

        $shipment->refresh();
        $this->assertSame('label_created', $shipment->shipment_status);
        $this->assertSame('shipments/labels/dhl/31294120912.pdf', $shipment->label_path);
    }

    public function test_confirmed_repair_marks_remote_created_label_missing_and_keeps_tracking(): void
    {
        Storage::fake('local');
        $shipment = $this->createMissingLabelShipment();

        $response = $this->withoutMiddleware()->postJson('/admin/tools/shipments/repair-missing-label', [
            'shipment_id' => $shipment->id,
            'order_id' => 153,
            'mode' => 'mark_label_missing_and_keep_tracking',
            'confirm' => 'repair-missing-dhl-label',
        ]);

        $response->assertOk()
            ->assertJsonPath('code_marker', 'dhl_missing_label_local_repair_v1')
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tracking_number', '31294120912')
            ->assertJsonPath('carrier_shipment_id', '31294120912')
            ->assertJsonPath('label_path', null)
            ->assertJsonPath('previous_missing_label_path', 'shipments/labels/dhl/31294120912.pdf')
            ->assertJsonPath('changed.shipment_status', 'remote_created_label_missing')
            ->assertJsonPath('createShipment_called', false)
            ->assertJsonPath('getLabels_called', false);

        $shipment->refresh();
        $this->assertSame('remote_created_label_missing', $shipment->shipment_status);
        $this->assertNull($shipment->label_path);
        $this->assertSame('31294120912', $shipment->tracking_number);
        $this->assertSame('31294120912', $shipment->carrier_shipment_id);
        $this->assertSame('shipments/labels/dhl/31294120912.pdf', data_get($shipment->response_payload, 'missing_label_repair.previous_missing_label_path'));
    }

    public function test_repair_refuses_when_local_label_file_exists(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('shipments/labels/dhl/31294120912.pdf', '%PDF-1.4');
        $shipment = $this->createMissingLabelShipment();

        $response = $this->withoutMiddleware()->postJson('/admin/tools/shipments/repair-missing-label', [
            'shipment_id' => $shipment->id,
            'order_id' => 153,
            'mode' => 'mark_label_missing_and_keep_tracking',
            'confirm' => 'repair-missing-dhl-label',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('label_file_exists', true)
            ->assertJsonPath('qualifies_for_repair', false)
            ->assertJsonPath('blocking_reason', 'Local label file exists; repair is not needed and label_path must not be cleared.');

        $this->assertSame('shipments/labels/dhl/31294120912.pdf', $shipment->refresh()->label_path);
    }

    public function test_create_shipment_guard_blocks_existing_dhl_tracking_after_repair_even_without_label_path(): void
    {
        $shipment = $this->createMissingLabelShipment(['shipment_status' => 'remote_created_label_missing', 'label_path' => null]);

        $guard = app(DhlShipmentService::class)->duplicateCreateShipmentGuard(153);

        $this->assertTrue($guard['would_create_duplicate_if_clicked_again']);
        $this->assertSame('DHL shipment already exists for this order. Do not create a duplicate. Repair or upload the missing label instead.', $guard['message']);
    }

    private function createMissingLabelShipment(array $overrides = []): Shipment
    {
        DB::table('orders')->insert([
            'id' => 153,
            'order_number' => 'ORD-153',
            'status' => 'new',
            'currency' => 'PLN',
            'subtotal' => 10,
            'shipping_total' => 0,
            'total' => 10,
            'customer_name' => 'Test Customer',
            'email' => 'test@example.test',
            'phone' => '123456789',
            'address_line1' => 'Test 1',
            'postal_code' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
            'marketplace' => 'allegro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Shipment::query()->create(array_merge([
            'id' => 1,
            'order_id' => 153,
            'carrier' => 'dhl',
            'service_code' => 'AH',
            'shipment_status' => 'label_created',
            'tracking_number' => '31294120912',
            'carrier_shipment_id' => '31294120912',
            'label_path' => 'shipments/labels/dhl/31294120912.pdf',
            'label_format' => 'application/pdf',
        ], $overrides));
    }
}
