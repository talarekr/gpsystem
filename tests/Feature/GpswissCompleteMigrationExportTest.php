<?php

namespace Tests\Feature;

use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\GpswissSnapshot;
use App\Services\MigrationExport\MigrationDataPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;
use ZipArchive;

/** Fixtures are synthetic ONLY for tests. The exporter never generates fixture products. */
class GpswissCompleteMigrationExportTest extends TestCase
{
    use RefreshDatabase;

    private string $temporary;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/gps-complete-export-'.Str::uuid();
        mkdir($this->temporary.'/originals/a', 0700, true);
        mkdir($this->temporary.'/originals/b', 0700, true);
        config(['migration_export.root' => $this->temporary.'/exports', 'filesystems.disks.public.root' => $this->temporary.'/originals', 'filesystems.served_public_storage_root' => null]);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('Provider access forbidden'));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    private function fixture(): void
    {
        Schema::table('parts', function ($table): void {
            $table->json('migration_business_extension')->nullable();
        });
        DB::table('users')->insert(['id' => 50, 'name' => 'Private Customer', 'email' => 'customer@example.test', 'password' => 'PASSWORD_HASH_NEVER_EXPORT']);
        DB::table('part_categories')->insert(['id' => 1, 'name' => 'Parts', 'slug' => 'parts']);
        DB::table('storage_locations')->insert(['id' => 1, 'name' => 'Warehouse A', 'is_active' => true]);
        DB::table('cars')->insert([
            ['id' => 8, 'uuid' => (string) Str::uuid(), 'source_system' => 'ovoko', 'external_id' => '0001800', 'make' => 'Brand', 'model' => 'Donor', 'vin' => 'BUSINESS-VIN-8', 'registration_number' => '000ABC', 'purchase_price' => '0.00', 'status' => 'kupiony'],
            ['id' => 9, 'uuid' => (string) Str::uuid(), 'source_system' => 'ovoko', 'external_id' => '0001900', 'make' => 'Brand', 'model' => 'No linked parts', 'vin' => 'BUSINESS-VIN-9', 'registration_number' => '000DEF', 'purchase_price' => null, 'status' => 'archived'],
        ]);
        DB::table('ovoko_car_dictionary_entries')->insert(['id' => 12, 'dictionary' => 'models', 'ovoko_id' => '000999', 'ovoko_brand_id' => '000001', 'name' => 'Model catalog', 'year_from' => 2000]);
        for ($id = 1; $id <= 7; $id++) {
            DB::table('parts')->insert(['id' => $id, 'sku' => '000SKU-'.$id, 'part_number' => '000CODE-'.$id, 'additional_part_codes' => json_encode(['000EXTRA-'.$id]),
                'name' => 'Actual fixture part '.$id, 'category_id' => 1, 'car_id' => 8, 'storage_location_id' => 1,
                'quantity' => $id === 3 ? 0 : 5, 'status' => $id === 1 ? 'sold' : ($id === 5 ? 'archived' : ($id === 4 ? 'draft' : 'ready')), 'needs_listing' => $id === 2,
                'price' => $id === 3 ? null : '0.00', 'allegro_price' => '100.01', 'ebay_price' => '200.02', 'ovoko_price' => '300.03', 'currency' => 'PLN',
                'description' => $id === 7 ? '\\N' : 'Complete original description', 'internal_note' => 'Business workshop note',
                'migration_business_extension' => json_encode(['quality_code' => '0000', 'nullable' => null, 'zero' => 0, 'attributes' => ['side' => 'left'], 'access_token' => 'PRODUCT_TOKEN_NEVER_EXPORT']),
                'legacy_payload' => json_encode(['ean' => '0001234567890', 'tax_rate' => '23.00', 'business_attribute' => 'keep-me', 'refresh_token' => 'LEGACY_TOKEN_NEVER_EXPORT'])]);
        }
        foreach ([[1, 1, 'a/Oryginał 0001.png'], [2, 1, 'b/Oryginał 0001.png'], [3, 2, 'a/Oryginał 0001.png'], [4, 6, 'missing.png']] as [$imageId, $partId, $path]) {
            DB::table('part_images')->insert(['id' => $imageId, 'part_id' => $partId, 'path' => $path, 'sort_order' => $imageId === 2 ? 1 : 0, 'is_primary' => $imageId !== 2]);
        }
        foreach (['a/Oryginał 0001.png', 'b/Oryginał 0001.png'] as $path) {
            file_put_contents($this->temporary.'/originals/'.$path, base64_decode(self::PNG));
        }
        foreach ([['ebay_de', 4, '000EBAY', 'https://www.ebay.de/itm/000EBAY?token=OFFER_URL_NEVER_EXPORT'], ['ovoko', 5, '000OVOKO', 'https://ovoko.pl/czesci/000OVOKO'], ['allegro', 1, '000ALLEGRO', 'https://allegro.pl/oferta/000ALLEGRO']] as [$channel, $part, $external, $url]) {
            DB::table('marketplace_listings')->insert(['marketplace' => $channel, 'marketplace_account_id' => null, 'part_id' => $part, 'external_offer_id' => $external, 'external_inventory_id' => '000INV-'.$external, 'sku' => '000SKU-'.$part, 'status' => 'active', 'url' => $url,
                'raw_payload' => json_encode(['market' => 'DE', 'parameters' => [['id' => '0005', 'values' => ['0000']]], 'access_token' => 'LISTING_TOKEN_NEVER_EXPORT'])]);
        }
        for ($id = 1; $id <= 4; $id++) {
            $channel = ['allegro', 'ebay_de', 'ovoko', 'payu'][$id - 1];
            DB::table('orders')->insert(['id' => $id, 'customer_id' => 50, 'order_number' => '000ORDER-'.$id, 'marketplace' => $channel, 'marketplace_order_id' => '000REMOTE-'.$id,
                'marketplace_status' => $id === 3 ? 'CANCELLED' : 'PAID', 'ordered_at' => '2026-11-01 08:30:00', 'status' => $id === 3 ? 'cancelled' : ($id === 2 ? 'completed' : 'new'), 'status_changed_at' => '2026-11-02 09:00:00',
                'currency' => $id === 2 ? 'EUR' : 'PLN', 'subtotal' => '0.00', 'shipping_total' => '10.00', 'total' => '10.00', 'payment_status' => 'paid', 'delivery_method' => 'courier',
                'customer_name' => 'Private Customer', 'email' => 'customer@example.test', 'phone' => '+48123456789', 'company_name' => 'Private Buyer Company', 'nip' => '0001234567',
                'address_line1' => 'Private street 1', 'postal_code' => '00-001', 'city' => 'Warszawa', 'country' => 'PL', 'notes' => 'Keep cancellation/refund business notes',
                'invoice_data' => json_encode(['name' => 'Invoice buyer', 'nip' => '0001234567', 'address' => 'Invoice street', 'postal_code' => '00-001']),
                'meta' => json_encode(['status_history' => [['status' => 'new', 'at' => '2026-11-01T08:30:00+00:00']], 'paid_amount' => '10.00', 'refunded_amount' => '0.00', 'discount' => '0.00', 'tax' => ['rate' => '23.00', 'amount' => '1.87'], 'client_secret' => 'PAYMENT_SECRET_NEVER_EXPORT']),
                'raw_payload' => json_encode(['buyer' => ['name' => 'Private Customer', 'email' => 'customer@example.test'], 'delivery' => ['tracking' => '000TRACK'], 'payment' => ['method' => 'card', 'card_number' => '4111111111111111', 'cvv' => 'CARD_CVV_NEVER_EXPORT'], 'refresh_token' => 'ORDER_TOKEN_NEVER_EXPORT'])]);
            DB::table('order_items')->insert(['id' => $id, 'order_id' => $id, 'part_id' => $id === 4 ? null : ($id === 2 ? 4 : $id), 'product_name' => $id === 4 ? 'Deleted historical product' : 'Stored order product '.$id,
                'sku' => '000HISTORY-'.$id, 'part_number' => '000ORDER-CODE-'.$id, 'marketplace' => $channel, 'marketplace_order_id' => '000REMOTE-'.$id, 'marketplace_item_id' => '000ITEM-'.$id,
                'offer_id' => '000OFFER-'.$id, 'quantity' => 1, 'unit_price' => '0.00', 'line_total' => '0.00', 'currency' => $id === 2 ? 'EUR' : null,
                'meta' => json_encode(['tax' => '23.00', 'coupon' => '000COUPON', 'discount' => '0.00'])]);
        }
        DB::table('shipments')->insert(['id' => 10, 'order_id' => 1, 'carrier' => 'dpd', 'shipment_status' => 'created', 'tracking_number' => '000TRACK', 'carrier_shipment_id' => '000SHIP', 'receiver_snapshot' => json_encode(['email' => 'customer@example.test', 'address' => 'Private street 1']), 'response_payload' => json_encode(['tracking' => '000TRACK', 'authorization' => 'CARRIER_SECRET_NEVER_EXPORT'])]);
        DB::table('shop_events')->insert(['id' => 11, 'source' => 'shop', 'event_type' => 'order', 'title' => 'Historical order event', 'external_reference' => '000ORDER-1', 'payload' => json_encode(['order_id' => 1, 'customer' => ['email' => 'customer@example.test']])]);
        DB::table('customer_returns')->insert(['id' => 20, 'user_id' => 50, 'order_id' => 11, 'reason' => 'Return reason', 'message' => 'Return business note', 'status' => 'approved']);
        DB::table('local_sales')->insert(['id' => 30, 'part_id' => 1, 'part_snapshot' => json_encode(['sku' => '000SKU-1', 'name' => 'Stored local sale part']), 'amount' => '0.00', 'currency' => 'PLN', 'payment_method' => 'cash', 'quantity' => 1, 'notes' => 'Local sale note']);
        DB::table('stripe_fr_attempts')->insert(['checkout_key' => (string) Str::uuid(), 'order_id' => 4, 'part_id' => 4, 'status' => 'paid', 'session_id' => 'STRIPE_SESSION_NEVER_EXPORT', 'checkout_url' => 'https://checkout.stripe.com/c/pay/STRIPE_URL_NEVER_EXPORT', 'expires_at' => '2026-11-01 10:00:00', 'request_payload' => json_encode(['customer_email' => 'customer@example.test', 'client_secret' => 'STRIPE_SECRET_NEVER_EXPORT'])]);
        DB::table('stripe_fr_events')->insert(['event_id' => '000EVENT', 'order_id' => 4, 'event_type' => 'checkout.session.completed', 'processed_at' => '2026-11-01 09:00:00']);
    }

    private function finish(array $state): array
    {
        for ($i = 0; $i < 30 && $state['state'] !== 'completed'; $i++) {
            $state = app(GpswissMigrationExport::class)->tick(1, $state['run_id']);
            $this->assertNull($state['last_error']);
        }
        $this->assertSame('completed', $state['state']);

        return $state;
    }

    private function zip(array $state, string $kind): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(app(GpswissMigrationExport::class)->download(1, $state['run_id'], $kind)));

        return $zip;
    }

    private function rows(ZipArchive $zip, string $file): array
    {
        $json = $zip->getFromName($file);
        $this->assertNotFalse($json, $file);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_full_private_package_preserves_all_cars_orders_and_business_columns_but_never_secrets(): void
    {
        $this->fixture();
        $before = [];
        foreach (['parts', 'part_images', 'cars', 'ovoko_car_dictionary_entries', 'orders', 'order_items', 'shipments', 'customer_returns', 'shop_events', 'local_sales', 'users', 'stripe_fr_attempts', 'stripe_fr_events'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $mutations = [];
        DB::listen(function ($event) use (&$mutations): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $event->sql)) {
                $mutations[] = $event->sql;
            }
        });
        $state = $this->finish(app(GpswissMigrationExport::class)->start(1));
        $zip = $this->zip($state, 'full');
        $products = $this->rows($zip, 'products.json');
        $this->assertCount(7, $products);
        $this->assertSame('1', $products[0]['id']);
        $this->assertSame('000SKU-1', $products[0]['sku']);
        $this->assertSame('Business workshop note', $products[0]['internal_note']);
        $this->assertSame('0000', $products[0]['migration_business_extension']['quality_code']);
        $this->assertSame(0, $products[0]['migration_business_extension']['zero']);
        $this->assertNull($products[0]['migration_business_extension']['nullable']);
        $this->assertSame('keep-me', $products[0]['legacy_payload']['business_attribute']);
        $this->assertNull($products[2]['price']);
        $this->assertSame('0.00', $products[0]['price']);
        $cars = $this->rows($zip, 'cars.json');
        $this->assertCount(2, $cars); // Includes the vehicle with no assigned parts.
        $this->assertSame('9', $cars[1]['id']);
        $this->assertSame('0001900', $cars[1]['ovoko_car_id']);
        $this->assertSame('BUSINESS-VIN-9', $cars[1]['vin']);
        $this->assertSame('000ABC', $cars[0]['registration_number']);
        $models = $this->rows($zip, 'datasets/ovoko_car_dictionary_entries.json');
        $this->assertSame('000999', $models[0]['ovoko_id']);
        $orders = $this->rows($zip, 'datasets/orders.json');
        $this->assertCount(4, $orders);
        $this->assertSame(['allegro', 'ebay_de', 'ovoko', 'payu'], array_column($orders, 'marketplace'));
        $this->assertSame('000REMOTE-1', $orders[0]['marketplace_order_id']);
        $this->assertSame('customer@example.test', $orders[0]['email']);
        $this->assertSame('00-001', $orders[0]['postal_code']);
        $this->assertSame('0001234567', $orders[0]['invoice_data']['nip']);
        $this->assertSame('0.00', $orders[0]['meta']['refunded_amount']);
        $this->assertSame('new', $orders[0]['meta']['status_history'][0]['status']);
        $items = $this->rows($zip, 'datasets/order_items.json');
        $this->assertSame('Deleted historical product', $items[3]['product_name']);
        $this->assertSame('000HISTORY-4', $items[3]['sku']);
        $this->assertNull($items[3]['part_id']);
        $this->assertNull($items[3]['currency']);
        $this->assertSame('0.00', $items[3]['unit_price']);
        $shipments = $this->rows($zip, 'datasets/shipments.json');
        $this->assertSame('000TRACK', $shipments[0]['tracking_number']);
        $this->assertSame('customer@example.test', $shipments[0]['receiver_snapshot']['email']);
        $returns = $this->rows($zip, 'datasets/customer_returns.json');
        $this->assertSame('11', $returns[0]['order_id']); // This legacy FK belongs to shop_events, NOT orders.
        $this->assertSame('11', $this->rows($zip, 'datasets/shop_events.json')[0]['id']);
        $this->assertCount(1, $this->rows($zip, 'datasets/local_sales.json'));
        $this->assertCount(1, $this->rows($zip, 'datasets/stripe_fr_events.json'));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = $zip->getFromIndex($i);
            foreach (['PASSWORD_HASH_NEVER_EXPORT', 'PRODUCT_TOKEN_NEVER_EXPORT', 'LEGACY_TOKEN_NEVER_EXPORT', 'LISTING_TOKEN_NEVER_EXPORT', 'OFFER_URL_NEVER_EXPORT', 'PAYMENT_SECRET_NEVER_EXPORT', 'ORDER_TOKEN_NEVER_EXPORT', '4111111111111111', 'CARD_CVV_NEVER_EXPORT', 'CARRIER_SECRET_NEVER_EXPORT', 'STRIPE_SESSION_NEVER_EXPORT', 'STRIPE_URL_NEVER_EXPORT', 'STRIPE_SECRET_NEVER_EXPORT'] as $secret) {
                $this->assertStringNotContainsString($secret, $content, $zip->getNameIndex($i));
            }
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame([], $mutations);
        $zip->close();
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_sample_contains_real_sold_pending_and_existing_ebay_ovoko_parts_and_related_orders(): void
    {
        $this->fixture();
        $state = $this->finish(app(GpswissMigrationExport::class)->start(1));
        $sample = $this->zip($state, 'sample');
        $full = $this->zip($state, 'full');
        $rows = $this->rows($sample, 'products.json');
        $this->assertCount(5, $rows);
        $ids = array_column($rows, 'id');
        foreach (['1', '2', '4', '5'] as $required) {
            $this->assertContains($required, $ids);
        }
        $this->assertSame(['1'], $this->rows($full, 'sold-part-ids.json'));
        $this->assertSame(['2'], $this->rows($full, 'parts-to-list-ids.json'));
        $this->assertNotContains('3', $this->rows($full, 'parts-to-list-ids.json')); // Zero stock is not the panel's rule.
        $orders = $this->rows($sample, 'datasets/orders.json');
        $this->assertNotEmpty($orders);
        $this->assertContains('1', array_column($orders, 'id'));
        $this->assertSame($this->rows($full, 'manifest.json')['snapshot_at'], $this->rows($sample, 'manifest.json')['snapshot_at']);
        $sample->close();
        $full->close();
    }

    public function test_original_filenames_and_null_zero_csv_contract_survive_export(): void
    {
        $this->fixture();
        $state = $this->finish(app(GpswissMigrationExport::class)->start(1));
        $zip = $this->zip($state, 'full');
        $photos = $this->rows($zip, 'photos.json');
        $this->assertSame('Oryginał 0001.png', $photos[0]['original_filename']);
        $this->assertSame('Oryginał 0001.png', basename($photos[0]['package_path']));
        $this->assertSame('Oryginał 0001.png', basename($photos[1]['package_path']));
        $this->assertNotSame($photos[0]['package_path'], $photos[1]['package_path']);
        $this->assertStringStartsWith('images/1/', $photos[0]['package_path']);
        $this->assertStringStartsWith('images/1/', $photos[1]['package_path']);
        $this->assertStringStartsWith('images/2/', $photos[2]['package_path']);
        foreach ($photos as $photo) {
            if ($photo['status'] === 'COPIED_VERIFIED') {
                $this->assertSame($photo['sha256'], hash('sha256', $zip->getFromName($photo['package_path'])));
            }
        }
        $csv = $zip->getFromName('products.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('000SKU-1', $csv);
        $this->assertStringContainsString('\\N', $csv);
        $this->assertStringContainsString('0.00', $csv);
        $this->assertStringContainsString('\\\\N', $csv); // Literal backslash-N differs from null marker.
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $headers = fgetcsv($stream, null, ';', '"', '');
        $parsed = [];
        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $parsed[] = array_combine($headers, $row);
        }
        fclose($stream);
        $byId = array_column($parsed, null, 'id');
        $this->assertSame('\\N', $byId['3']['price']);
        $this->assertSame('0.00', $byId['1']['price']);
        $this->assertSame('\\\\N', $byId['7']['description']);
        $this->assertIsArray($this->rows($zip, 'fields.generated.json'));
        $this->assertIsArray($this->rows($zip, 'statuses.json'));
        $this->assertSame(base64_decode(self::PNG), file_get_contents($this->temporary.'/originals/a/Oryginał 0001.png'));
        $zip->close();
    }

    public function test_sanitizer_handles_quoted_malformed_json_and_large_identifiers_without_redacting_private_customer_fields(): void
    {
        $clean = MigrationDataPolicy::clean('{"email":"buyer@example.test","card_number":"4111111111111111","access_token":"MALFORMED_SECRET" BROKEN');
        $this->assertStringNotContainsString('4111111111111111', $clean);
        $this->assertStringNotContainsString('MALFORMED_SECRET', $clean);
        $this->assertStringContainsString('buyer@example.test', $clean);
        $clean = MigrationDataPolicy::clean(['id' => '0001', 'ean' => '0001234567890', 'value' => null, 'zero' => 0, 'raw_payload' => '{"external_id":9223372036854775808,"customer":{"email":"buyer@example.test"},"apiToken":"NESTED_SECRET"}']);
        $this->assertSame('0001', $clean['id']);
        $this->assertSame('0001234567890', $clean['ean']);
        $this->assertNull($clean['value']);
        $this->assertSame(0, $clean['zero']);
        $this->assertSame('9223372036854775808', $clean['raw_payload']['external_id']);
        $this->assertSame('buyer@example.test', $clean['raw_payload']['customer']['email']);
        $this->assertArrayNotHasKey('apiToken', $clean['raw_payload']);
    }

    public function test_identifier_fields_cannot_bypass_secret_or_payment_card_cleanup(): void
    {
        $clean = MigrationDataPolicy::clean([
            'id' => '000001',
            'gtin' => '00012345601095',
            'barcode' => '00012345601095',
            'additional_part_codes' => ['00012345601095'],
            'tracking' => '4111111111111111',
            'external_id' => '{"token":"IDENTIFIER_SECRET_LEAK","reference":"0001"}',
            'code' => 'access_token=CODE_SECRET_LEAK',
            'payment' => ['creditCardData' => ['number' => '4111111111111111', 'brand' => 'VISA'], 'unmaskedCardNumber' => '5555555555554444'],
            'notes' => 'Paid by card 4111 1111 1111 1111. Preserve business notes.',
        ]);
        $encoded = json_encode($clean, JSON_THROW_ON_ERROR);
        foreach (['IDENTIFIER_SECRET_LEAK', 'CODE_SECRET_LEAK', '5555555555554444', '4111 1111 1111 1111'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertSame('000001', $clean['id']);
        $this->assertSame('00012345601095', $clean['gtin']);
        $this->assertSame('00012345601095', $clean['barcode']);
        $this->assertSame(['00012345601095'], $clean['additional_part_codes']);
        $this->assertSame('4111111111111111', $clean['tracking']);
        $this->assertArrayNotHasKey('creditCardData', $clean['payment']);
        $this->assertStringContainsString('Preserve business notes', $clean['notes']);
    }

    public function test_numeric_original_names_are_not_mistaken_for_payment_cards(): void
    {
        $this->fixture();
        $name = '4111111111111111.jpg';
        file_put_contents($this->temporary.'/originals/'.$name, base64_decode(self::PNG));
        DB::table('part_images')->insert(['part_id' => 1, 'path' => $name, 'sort_order' => 9, 'is_primary' => false]);
        $state = $this->finish(app(GpswissMigrationExport::class)->start(1));
        $zip = $this->zip($state, 'full');
        $photos = $this->rows($zip, 'photos.json');
        $photo = collect($photos)->firstWhere('original_filename', $name);
        $this->assertNotNull($photo);
        $this->assertSame('images/1/'.$name, $photo['package_path']);
        $this->assertSame('COPIED_VERIFIED', $photo['status']);
        $this->assertSame(base64_decode(self::PNG), $zip->getFromName($photo['package_path']));
        $zip->close();
    }

    public function test_ebay_variant_identifiers_survive_safe_link_export(): void
    {
        $url = GpswissSnapshot::offerUrl('https://user:password@www.ebay.de/itm/000123?var=000987&item=000123&access_token=DO_NOT_EXPORT#private');
        $this->assertSame('https://www.ebay.de/itm/000123?var=000987&item=000123', $url);
        $this->assertStringNotContainsString('DO_NOT_EXPORT', $url);
    }

    public function test_orders_remain_in_the_same_frozen_snapshot_after_later_source_edits(): void
    {
        $this->fixture();
        $service = app(GpswissMigrationExport::class);
        $state = $service->start(1);
        DB::table('orders')->where('id', 1)->update(['customer_name' => 'CHANGED AFTER SNAPSHOT', 'total' => '999.99']);
        DB::table('cars')->where('id', 9)->update(['status' => 'CHANGED AFTER SNAPSHOT']);
        $state = $this->finish($state);
        $zip = $this->zip($state, 'full');
        $orders = $this->rows($zip, 'datasets/orders.json');
        $cars = $this->rows($zip, 'cars.json');
        $this->assertSame('Private Customer', $orders[0]['customer_name']);
        $this->assertSame('10.00', $orders[0]['total']);
        $this->assertSame('archived', $cars[1]['status']);
        $zip->close();
    }
}
