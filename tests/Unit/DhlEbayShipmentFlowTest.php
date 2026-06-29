<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Marketplace\Api\EbayFulfillmentService;
use App\Services\Shipments\DhlShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DhlEbayShipmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dhl_payload_omits_ui_snapshot_only_fields_and_empty_values(): void
    {
        $form = app(DhlShipmentService::class)->defaults();
        data_set($form, 'parcel.dhl_option', 'standard');
        data_set($form, 'parcel.volumetric', true);
        data_set($form, 'parcel.half_pallet', true);
        data_set($form, 'receiver.short_name', 'UI');
        data_set($form, 'receiver.sap_number', 'SAP');
        data_set($form, 'parcel.comment', '');

        $payload = app(DhlShipmentService::class)->payload($form);
        $json = json_encode($payload);

        $this->assertStringNotContainsString('dhl_option', $json);
        $this->assertStringNotContainsString('volumetric', $json);
        $this->assertStringNotContainsString('half_pallet', $json);
        $this->assertStringNotContainsString('short_name', $json);
        $this->assertStringNotContainsString('sap_number', $json);
        $this->assertArrayNotHasKey('comment', $payload['shipment']);
    }

    public function test_ebay_fulfillment_payload_contains_carrier_tracking_and_line_items(): void
    {
        $order = Order::query()->create([
            'marketplace' => 'ebay_de',
            'marketplace_order_id' => '11-11111-11111',
            'raw_payload' => ['lineItems' => [['lineItemId' => 'LINE-1', 'quantity' => 2]]],
        ]);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'carrier' => 'dhl', 'tracking_number' => 'JD 123-456']);

        $payload = app(EbayFulfillmentService::class)->payload($order, $shipment);

        $this->assertSame('DHL', $payload['shippingCarrierCode']);
        $this->assertSame('JD123456', $payload['trackingNumber']);
        $this->assertSame([['lineItemId' => 'LINE-1', 'quantity' => 2]], $payload['lineItems']);
    }

    public function test_non_ebay_order_is_not_eligible_for_ebay_tracking(): void
    {
        $order = new Order(['marketplace' => 'storefront']);

        $this->assertFalse(app(EbayFulfillmentService::class)->isEbayOrder($order));
    }
}
