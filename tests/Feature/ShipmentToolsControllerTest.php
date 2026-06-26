<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentToolsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_shipment_uses_requested_order_id(): void
    {
        Order::query()->create($this->orderAttributes(['id' => 12, 'order_number' => 'ORDER-12']));
        Order::query()->create($this->orderAttributes(['id' => 93, 'order_number' => 'ORDER-93']));

        $response = $this->getJson('/tools/create-order-shipment?carrier=dhl&dry_run=1&confirm=0&order_id=12');

        $response->assertOk()
            ->assertJsonPath('request_preview.references.order_id', 12)
            ->assertJsonPath('request_preview.references.order_number', 'ORDER-12');
    }

    public function test_create_order_shipment_returns_error_for_missing_requested_order(): void
    {
        Order::query()->create($this->orderAttributes(['id' => 93, 'order_number' => 'ORDER-93']));

        $response = $this->getJson('/tools/create-order-shipment?carrier=dhl&dry_run=1&confirm=0&order_id=12');

        $response->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('requested_order_id', 12)
            ->assertJsonPath('safety_flags.read_only', true)
            ->assertJsonPath('safety_flags.shipment_created', false);
    }

    public function test_dry_run_debug_maps_dhl24_env_and_parses_imported_ovoko_address(): void
    {
        config([
            'services.shipments.sender.name' => 'GPS',
            'services.shipments.sender.address' => 'Sender Street 1',
            'services.shipments.sender.postal_code' => '08-460',
            'services.shipments.sender.city' => 'Sobolew',
            'services.shipments.sender.country' => 'PL',
            'services.shipments.sender.phone' => '+48123123123',
            'services.shipments.sender.email' => 'sender@example.test',
            'services.dhl.login' => 'login',
            'services.dhl.password' => 'secret',
            'services.dhl.account_number' => '2520734',
            'services.dhl.endpoint' => 'https://dhl24.com.pl/webapi2',
            'services.dhl.label_type' => 'LBLP',
            'services.dhl.drop_off_type' => 'REGULAR_PICKUP',
        ]);

        Order::query()->create($this->orderAttributes([
            'id' => 12,
            'address_line1' => 'Sokół, 24, Sobolew, PL-08-460',
            'postal_code' => '-',
            'city' => '-',
            'country' => 'PL',
        ]));

        $response = $this->getJson('/tools/create-order-shipment?carrier=dhl&dry_run=1&confirm=0&order_id=12&include_debug=1');

        $response->assertOk()
            ->assertJsonPath('validation.ok', true)
            ->assertJsonPath('configuration.ok', true)
            ->assertJsonPath('receiver_snapshot.address', 'Sokół 24')
            ->assertJsonPath('receiver_snapshot.city', 'Sobolew')
            ->assertJsonPath('receiver_snapshot.postal_code', '08-460')
            ->assertJsonPath('receiver_snapshot.country', 'PL')
            ->assertJsonPath('debug.sender_config_present', true)
            ->assertJsonPath('debug.dhl_login_present', true)
            ->assertJsonPath('debug.dhl_password_present', true)
            ->assertJsonPath('debug.dhl_account_number_present', true)
            ->assertJsonPath('debug.dhl_wsdl_present', true)
            ->assertJsonPath('debug.dhl_label_type', 'LBLP')
            ->assertJsonPath('debug.dhl_drop_off_type', 'REGULAR_PICKUP')
            ->assertJsonPath('debug.receiver_address_parse_used', true);
    }


    public function test_dry_run_rejects_placeholder_sender_phone_and_parses_foreign_imported_address(): void
    {
        $this->configureShipmentServices(['services.shipments.sender.phone' => 'TELEFON']);

        Order::query()->create($this->orderAttributes([
            'id' => 12,
            'customer_name' => 'Hahn Manuel',
            'address_line1' => 'Oberkalmberg 39/2, Bad Kreuzen, AT-4362',
            'postal_code' => '-',
            'city' => '-',
            'country' => 'PL',
        ]));

        $response = $this->getJson('/tools/create-order-shipment?carrier=dhl&dry_run=1&confirm=0&order_id=12&include_debug=1');

        $response->assertOk()
            ->assertJsonPath('validation.ok', false)
            ->assertJsonPath('validation.invalid.0', 'sender.phone')
            ->assertJsonPath('receiver_snapshot.address', 'Oberkalmberg 39/2')
            ->assertJsonPath('receiver_snapshot.city', 'Bad Kreuzen')
            ->assertJsonPath('receiver_snapshot.postal_code', '4362')
            ->assertJsonPath('receiver_snapshot.country', 'AT')
            ->assertJsonPath('debug.receiver_address_parse_used', true)
            ->assertJsonPath('debug.receiver_address_parse_pattern', 'address_city_country_postal')
            ->assertJsonPath('debug.receiver_country_detected_from_address', true)
            ->assertJsonPath('safety_flags.read_only', true)
            ->assertJsonPath('safety_flags.shipment_created', false)
            ->assertJsonPath('safety_flags.label_created', false)
            ->assertJsonPath('safety_flags.pickup_ordered', false);
    }

    public function test_dry_run_accepts_real_sender_phone_with_foreign_imported_address(): void
    {
        $this->configureShipmentServices(['services.shipments.sender.phone' => '+48123123123']);

        Order::query()->create($this->orderAttributes([
            'id' => 12,
            'address_line1' => 'Oberkalmberg 39/2, Bad Kreuzen, AT-4362',
            'postal_code' => '-',
            'city' => '-',
            'country' => 'PL',
        ]));

        $response = $this->getJson('/tools/create-order-shipment?carrier=dhl&dry_run=1&confirm=0&order_id=12&include_debug=1');

        $response->assertOk()
            ->assertJsonPath('validation.ok', true)
            ->assertJsonPath('receiver_snapshot.country', 'AT')
            ->assertJsonPath('receiver_snapshot.postal_code', '4362');
    }


    private function configureShipmentServices(array $overrides = []): void
    {
        config(array_merge([
            'services.shipments.sender.name' => 'GPS',
            'services.shipments.sender.address' => 'Sender Street 1',
            'services.shipments.sender.postal_code' => '08-460',
            'services.shipments.sender.city' => 'Sobolew',
            'services.shipments.sender.country' => 'PL',
            'services.shipments.sender.phone' => '+48123123123',
            'services.shipments.sender.email' => 'sender@example.test',
            'services.dhl.login' => 'login',
            'services.dhl.password' => 'secret',
            'services.dhl.account_number' => '2520734',
            'services.dhl.endpoint' => 'https://dhl24.com.pl/webapi2',
            'services.dhl.label_type' => 'LBLP',
            'services.dhl.drop_off_type' => 'REGULAR_PICKUP',
        ], $overrides));
    }

    private function orderAttributes(array $overrides = []): array
    {
        return array_merge([
            'order_number' => 'ORDER-'.($overrides['id'] ?? '1'),
            'status' => 'new',
            'currency' => 'PLN',
            'subtotal' => 100,
            'shipping_total' => 20,
            'total' => 120,
            'customer_name' => 'Jan Kowalski',
            'email' => 'jan@example.test',
            'phone' => '+48123123123',
            'address_line1' => 'Street 1',
            'postal_code' => '08-460',
            'city' => 'Sobolew',
            'country' => 'PL',
        ], $overrides);
    }
}
