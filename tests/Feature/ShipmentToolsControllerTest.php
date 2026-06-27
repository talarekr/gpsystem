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


    public function test_allegro_shipment_preview_is_read_only_and_builds_create_command_payload(): void
    {
        config([
            'services.shipments.sender.name' => 'GPS Sender',
            'services.shipments.sender.address' => 'Nadawcza 1',
            'services.shipments.sender.postal_code' => '08-460',
            'services.shipments.sender.city' => 'Sobolew',
            'services.shipments.sender.country' => 'PL',
            'services.shipments.sender.phone' => '+48123123123',
            'services.shipments.sender.email' => 'sender@example.test',
        ]);

        $order = Order::query()->create($this->orderAttributes([
            'id' => 50,
            'marketplace' => 'allegro',
            'marketplace_order_id' => 'checkout-123',
            'customer_name' => 'Jan Kupujący',
            'email' => 'masked@allegromail.pl',
            'phone' => '500600700',
            'address_line1' => 'Odbiorcza 2',
            'postal_code' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
            'currency' => 'PLN',
            'total' => 123.45,
            'raw_payload' => [
                'buyer' => ['email' => 'abc+123@allegromail.pl'],
                'delivery' => [
                    'method' => ['id' => 'method-1', 'name' => 'Allegro Paczkomaty InPost'],
                    'shippingCarrierCode' => 'INPOST',
                    'cost' => ['amount' => '12.34', 'currency' => 'PLN'],
                    'address' => ['firstName' => 'Jan', 'lastName' => 'Kupujący', 'street' => 'Odbiorcza 2', 'zipCode' => '00-001', 'city' => 'Warszawa', 'countryCode' => 'PL', 'phoneNumber' => '500600700'],
                    'pickupPoint' => ['id' => 'ADA01N', 'name' => 'Paczkomat ADA01N', 'address' => ['street' => 'Punktowa 3']],
                ],
                'payment' => ['type' => 'CASH_ON_DELIVERY'],
                'summary' => ['totalToPay' => ['amount' => '123.45', 'currency' => 'PLN']],
            ],
        ]));

        $response = $this->getJson('/tools/debug-allegro-shipment-preview?order_id='.$order->id);

        $response->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('shipment_created', false)
            ->assertJsonPath('label_created', false)
            ->assertJsonPath('pickup_ordered', false)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('audit.order.marketplace_order_id', 'checkout-123')
            ->assertJsonPath('audit.pickup_point.id', 'ADA01N')
            ->assertJsonPath('audit.delivery_method.is_pickup_point', true)
            ->assertJsonPath('payload_preview.endpoint', 'POST /shipment-management/shipments/create-commands')
            ->assertJsonPath('payload_preview.will_send', false)
            ->assertJsonPath('payload_preview.body.input.receiver.point', 'ADA01N');
    }


    public function test_allegro_shipment_preview_rejects_non_allegro_orders_without_payload(): void
    {
        $order = Order::query()->create($this->orderAttributes([
            'id' => 51,
            'marketplace' => 'ovoko',
            'marketplace_order_id' => 'ovoko-123',
        ]));

        $response = $this->getJson('/tools/debug-allegro-shipment-preview?order_id='.$order->id);

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'order_not_allegro')
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('shipment_created', false)
            ->assertJsonPath('label_created', false)
            ->assertJsonPath('pickup_ordered', false)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonMissingPath('payload_preview');
    }


    public function test_allegro_shipment_preview_applies_inpost_size_code_dimensions_label_limit_and_weight_warning(): void
    {
        config([
            'services.shipments.sender.name' => 'GPS Sender',
            'services.shipments.sender.address' => 'Nadawcza 1',
            'services.shipments.sender.postal_code' => '08-460',
            'services.shipments.sender.city' => 'Sobolew',
            'services.shipments.sender.country' => 'PL',
            'services.shipments.sender.phone' => '+48123123123',
            'services.shipments.sender.email' => 'sender@example.test',
        ]);

        $order = Order::query()->create($this->orderAttributes([
            'id' => 53,
            'marketplace' => 'allegro',
            'marketplace_order_id' => 'checkout-inpost',
            'raw_payload' => [
                'buyer' => ['id' => '104446741', 'email' => 'abc+123@allegromail.pl'],
                'delivery' => [
                    'method' => ['id' => 'method-inpost', 'name' => 'Allegro Paczkomaty InPost'],
                    'shippingCarrierCode' => 'INPOST',
                    'address' => ['firstName' => 'Jan', 'lastName' => 'Kupujący', 'street' => 'Odbiorcza 2', 'zipCode' => '00-001', 'city' => 'Warszawa', 'countryCode' => 'PL', 'phoneNumber' => '500600700'],
                    'pickupPoint' => ['id' => 'ADA01N'],
                ],
                'payment' => ['type' => 'CASH_ON_DELIVERY'],
                'summary' => ['totalToPay' => ['amount' => '60.00', 'currency' => 'PLN']],
            ],
        ]));

        $response = $this->getJson('/tools/debug-allegro-shipment-preview?order_id='.$order->id.'&size_code=A&weight=26&label_reference=Client:104446741-extra');

        $response->assertOk()
            ->assertJsonPath('audit.parcel_size_options.family', 'inpost')
            ->assertJsonPath('audit.parcel_size_options.options.0.code', 'A')
            ->assertJsonPath('audit.parcel_size_options.weight_limit_kg', 25)
            ->assertJsonPath('payload_preview.body.input.packages.0.length.value', 64)
            ->assertJsonPath('payload_preview.body.input.packages.0.width.value', 38)
            ->assertJsonPath('payload_preview.body.input.packages.0.height.value', 8)
            ->assertJsonPath('payload_preview.body.input.packages.0.weight.value', 26)
            ->assertJsonPath('payload_preview.body.input.packages.0.textOnLabel', 'Client:104446741-e')
            ->assertJsonPath('warnings.0', 'Waga przekracza limit 25 kg dla InPost/Paczkomat.');
    }

    public function test_allegro_shipment_preview_resolves_orlen_sizes_and_manual_fallback(): void
    {
        $orlenOrder = Order::query()->create($this->orderAttributes([
            'id' => 54,
            'marketplace' => 'allegro',
            'raw_payload' => ['delivery' => ['method' => ['name' => 'Allegro Automat ORLEN Paczka']]],
        ]));
        $manualOrder = Order::query()->create($this->orderAttributes([
            'id' => 55,
            'marketplace' => 'allegro',
            'raw_payload' => ['delivery' => ['method' => ['name' => 'Kurier lokalny']]],
        ]));

        $this->getJson('/tools/debug-allegro-shipment-preview?order_id='.$orlenOrder->id.'&size_code=S&weight=2')
            ->assertOk()
            ->assertJsonPath('audit.parcel_size_options.family', 'orlen_allegro_one')
            ->assertJsonPath('audit.parcel_size_options.options.0.code', 'S')
            ->assertJsonPath('payload_preview.body.input.packages.0.height.value', 8);

        $this->getJson('/tools/debug-allegro-shipment-preview?order_id='.$manualOrder->id.'&length=10&width=20&height=30&weight=2.5')
            ->assertOk()
            ->assertJsonPath('audit.parcel_size_options.mode', 'manual')
            ->assertJsonPath('payload_preview.body.input.packages.0.length.value', 10)
            ->assertJsonPath('payload_preview.body.input.packages.0.width.value', 20)
            ->assertJsonPath('payload_preview.body.input.packages.0.height.value', 30)
            ->assertJsonPath('payload_preview.body.input.packages.0.weight.value', 2.5);
    }

    public function test_ovoko_shipment_preview_is_read_only_and_builds_import_post_data_payload(): void
    {
        $order = Order::query()->create($this->orderAttributes([
            'id' => 52,
            'marketplace' => 'ovoko',
            'marketplace_order_id' => 'RRR-555',
            'customer_name' => 'Ona Kupująca',
            'email' => 'buyer@example.test',
            'phone' => '+37060000000',
            'address_line1' => 'Street 9',
            'postal_code' => 'LT-01001',
            'city' => 'Vilnius',
            'country' => 'LT',
            'currency' => 'EUR',
            'payment_status' => 'paid',
            'delivery_method' => 'DPD Courier',
            'raw_payload' => [
                'shipping_provider' => 'DPD',
                'delivery_type' => 'courier',
                'payment_type' => 'prepaid',
                'payment_method' => 'card',
            ],
        ]));

        $response = $this->getJson('/tools/debug-ovoko-shipment-preview?order_id='.$order->id.'&weight=2.5&length=40&width=30&height=20&package_type=box&label_reference=REF-555');

        $response->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('package_data_sent', false)
            ->assertJsonPath('label_downloaded', false)
            ->assertJsonPath('pickup_ordered', false)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('will_send', false)
            ->assertJsonPath('capabilities.flow', 'ovoko_package_data_then_label')
            ->assertJsonPath('audit.delivery_type', 'courier')
            ->assertJsonPath('audit.shipping_provider', 'DPD')
            ->assertJsonPath('audit.payment_type', 'prepaid')
            ->assertJsonPath('audit.payment_method', 'card')
            ->assertJsonPath('payload_preview.endpoint', 'crm/importPostData')
            ->assertJsonPath('payload_preview.will_send', false)
            ->assertJsonPath('payload_preview.body.package.weight', '2.5')
            ->assertJsonPath('label_preview.endpoint', 'get/print_shipping_label/{order_id}')
            ->assertJsonPath('label_preview.will_download', false);
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
