<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\FreshProductExport;
use App\Services\MigrationExport\MigrationExportException;
use App\Services\MigrationExport\ProductExportProjection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FreshProductExportTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/fresh-products-'.Str::uuid();
        config(['migration_export.product_root' => $this->root, 'app.url' => 'https://gpswiss.pl']);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Provider forbidden'));
        DB::table('part_categories')->insert(['id' => 1, 'name' => 'Skrzynie', 'slug' => 'skrzynie', 'category_path' => 'Części / Skrzynie']);
        DB::table('storage_locations')->insert(['id' => 1, 'name' => 'A-01', 'description' => 'Górna półka']);
        DB::table('cars')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'source_system' => 'ovoko', 'external_id' => '00042', 'make' => 'BMW', 'model' => '3', 'model_variant' => 'E90', 'production_year' => 2008, 'vin' => 'VIN123']);
        foreach (['ebay_de', 'ebay_fr', 'ovoko', 'allegro_main'] as $n => $code) {
            DB::table('marketplace_accounts')->insert(['id' => $n + 1, 'marketplace' => explode('_', $code)[0], 'code' => $code, 'name' => $code, 'api_settings' => json_encode(['marketplace_id' => $code === 'ebay_de' ? 'EBAY_DE' : ($code === 'ebay_fr' ? 'EBAY_FR' : null)])]);
        }
        for ($id = 1; $id <= 15; $id++) {
            DB::table('parts')->insert(['id' => $id, 'sku' => '000SKU'.$id, 'name' => $id === 15 ? '=formula' : 'Część '.$id, 'slug' => 'czesc-'.$id, 'status' => $id <= 7 ? 'sold' : 'ready', 'needs_listing' => $id >= 8, 'category_id' => 1, 'car_id' => 1, 'storage_location_id' => 1, 'price' => '123.45', 'allegro_price' => '120.00', 'ovoko_price' => '100.00', 'ebay_price' => '154.31', 'currency' => 'PLN', 'description' => "<p>Pełny; opis\nŁódź</p>", 'legacy_url' => 'https://gpswiss.pl/stary/'.$id, 'part_number' => '000123', 'vehicle_snapshot' => json_encode(['steering_side' => 'Prawa strona']), 'review_metadata' => json_encode(['part_position' => 'Tył']), 'additional_part_codes' => '["00001","00002"]', 'allegro_shipping_rate_name' => 'KURIER DPD']);
            DB::table('part_images')->insert(['part_id' => $id, 'path' => 'parts/'.$id.'/a.jpg', 'is_primary' => true, 'sort_order' => 1]);
            foreach (['ebay_de', 'ebay_fr', 'ovoko'] as $n => $market) {
                DB::table('marketplace_listings')->insert(['part_id' => $id, 'marketplace' => $market, 'marketplace_account_id' => $n + 1, 'external_listing_id' => '000LIST'.$market.$id, 'external_offer_id' => '000OFFER'.$id, 'price' => $n === 0 ? '40.50' : '50.60', 'currency' => 'EUR', 'url' => $market === 'ovoko' ? 'https://ovoko.pl/produkt/'.$id : 'https://www.ebay.de/itm/'.$id, 'status' => 'active']);
            }
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function start(string $type = 'czesci', string $mode = 'sample'): array
    {
        $s = app(FreshProductExport::class)->prepare(7, $type, $mode);

        return app(FreshProductExport::class)->action(7, $s['export_id'], 'capture');
    }

    private function finish(array $s): array
    {
        for ($i = 0; $i < 100 && $s['state'] !== 'done'; $i++) {
            $s = app(FreshProductExport::class)->action(7, $s['export_id'], 'tick');
        }
        $this->assertSame('done', $s['state']);

        return $s;
    }

    private function csv(array $s, string $file): array
    {
        $f = fopen(app(FreshProductExport::class)->download(7, $s['export_id'], $file), 'rb');
        $this->assertSame("\xEF\xBB\xBF", fread($f, 3));
        $columns = fgetcsv($f, 0, ';', '"', '');
        $rows = [];
        while (($r = fgetcsv($f, 0, ';', '"', '')) !== false) {
            $rows[] = array_combine($columns, $r);
        }
        fclose($f);

        return $rows;
    }

    public function test_sample_and_full_each_type_share_contract_and_only_selected_relations(): void
    {
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        foreach (['czesci' => 15, 'sprzedane' => 7, 'do_wystawienia' => 8] as $type => $total) {
            foreach (['sample', 'full'] as $mode) {
                $s = $this->finish($this->start($type, $mode));
                $rows = $this->csv($s, $type.'.csv');
                $this->assertCount($mode === 'sample' ? 5 : $total, $rows);
                $this->assertSame(ProductExportProjection::columns(), array_keys($rows[0]));
                $this->assertSame([$type.'.csv', 'manifest.json'], array_keys($s['files']));
                $this->assertSame([$type.'.csv'], array_map('basename', glob($this->root.'/'.$s['export_id'].'/*.csv')));
                foreach ($rows as $r) {
                    foreach (['images_json', 'marketplace_listings_json', 'issues_json'] as $field) {
                        $values = json_decode($r[$field], true, 512, JSON_THROW_ON_ERROR);
                        foreach ($values as $value) {
                            $this->assertSame($r['part_id'], $value['part_id']);
                        }
                    }
                    $this->assertCount((int) $r['image_count'], json_decode($r['images_json'], true));
                    $this->assertCount((int) $r['issues_count'], json_decode($r['issues_json'], true));
                    $this->assertSame($r['car_id'], json_decode($r['car_json'], true)['id']);
                    $this->assertSame($r['category_id'], json_decode($r['category_json'], true)['id']);
                    $this->assertSame($r['storage_location_id'], json_decode($r['storage_location_json'], true)['id']);
                }
                $this->assertSame($s['total'], $s['images']);
                $this->assertSame($s['total'] * 3, $s['listings']);
                foreach ($rows as $row) {
                    if ($type === 'sprzedane') {
                        $this->assertSame('sold', $row['status']);
                    }
                    if ($type === 'do_wystawienia') {
                        $this->assertSame('1', $row['needs_listing']);
                    }
                    $this->assertSame('unique', $row['ebay_de_resolution_status']);
                    $this->assertSame('ebay_de', $row['ebay_de_account_code']);
                    $this->assertArrayNotHasKey('ebay_fr_url', $row);
                }
            }
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\b/i', $sql);
        }
        Http::assertNothingSent();
    }

    public function test_to_list_sample_five_and_full_match_parts_contract_without_extra_filters(): void
    {
        // All five must qualify despite stock/status/visibility and existing DE/Ovoko offers.
        DB::table('parts')->update(['needs_listing' => false]);
        foreach (['sold', 'draft', 'archived', 'ready', 'published'] as $index => $status) {
            DB::table('parts')->where('id', $index + 1)->update([
                'needs_listing' => true, 'status' => $status,
                'quantity' => $index % 2 === 0 ? 0 : 3, 'is_visible_storefront' => false,
            ]);
        }
        DB::table('part_images')->where('part_id', 1)->update([
            'path' => 'parts/photos/imported/1/original.jpg',
            'legacy_payload' => '{"presentation":{"product_path":"parts/photos/presentation/product/example.jpg"}}',
        ]);
        DB::table('part_images')->insert(['part_id' => 1, 'path' => 'parts/photos/admin/1/second.jpg', 'sort_order' => 2]);
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $headers = [];
        foreach (['sample', 'full'] as $mode) {
            $parts = $this->finish($this->start('czesci', $mode));
            $toList = $this->finish($this->start('do_wystawienia', $mode));
            $partsRows = $this->csv($parts, 'czesci.csv');
            $toListRows = $this->csv($toList, 'do_wystawienia.csv');
            $this->assertCount($mode === 'sample' ? 5 : 15, $partsRows);
            $this->assertCount(5, $toListRows);
            $this->assertSame(['1', '2', '3', '4', '5'], array_column($toListRows, 'part_id'));
            $this->assertCount(108, array_keys($toListRows[0]));
            $this->assertSame(array_keys($partsRows[0]), array_keys($toListRows[0]));
            $partsById = array_column($partsRows, null, 'part_id');
            foreach ($toListRows as $row) {
                $this->assertSame($partsById[$row['part_id']], $row);
            }
            foreach ([$parts, $toList] as $export) {
                $file = $export['type'].'.csv';
                $this->assertSame([$file, 'manifest.json'], array_keys($export['files']));
                $this->assertSame('gpswiss-products-2', $export['schema_version']);
                $manifest = json_decode(file_get_contents($this->root.'/'.$export['export_id'].'/manifest.json'), true);
                $this->assertSame('gpswiss-products-2', $manifest['schema_version']);
                $this->assertSame(ProductExportProjection::columns(), $manifest['columns'][$file]);
                $stream = fopen($this->root.'/'.$export['export_id'].'/'.$file, 'rb');
                $headers[] = fgets($stream);
                fclose($stream);
            }
            $images = json_decode($toListRows[0]['images_json'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(2, $images);
            $this->assertSame('parts/photos/imported/1/original.jpg', $images[0]['source_path']);
            $this->assertSame('parts/photos/admin/1/second.jpg', $images[1]['source_path']);
            $this->assertSame('parts/photos/presentation/product/example.jpg', $images[0]['source_metadata']['legacy_payload']['presentation']['product_path']);
            $this->assertSame([1, 2], array_column($images, 'import_order'));
            foreach (['image_id', 'original_name', 'source_path', 'sort_order', 'is_primary', 'role', 'source_type', 'mime_type', 'extension', 'import_order'] as $key) {
                $this->assertArrayHasKey($key, $images[0]);
            }
        }
        $this->assertCount(1, array_unique($headers), 'Sample/full headers must be byte-identical across both datasets.');
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\b/i', $sql);
        }
        Http::assertNothingSent();
    }

    public function test_joins_prices_urls_photos_and_csv_roundtrip(): void
    {
        $s = $this->finish($this->start());
        $r = $this->csv($s, 'czesci.csv')[0];
        $this->assertSame('Skrzynie', $r['category_name']);
        $this->assertSame('A-01', $r['storage_location_name']);
        $this->assertSame('BMW', $r['car_make']);
        $this->assertSame('E90', $r['car_generation']);
        $this->assertSame('00042', $r['ovoko_car_id']);
        $this->assertSame('Prawa strona', $r['steering_side']);
        $this->assertSame('Tył', $r['part_position']);
        $this->assertSame('123.45', $r['store_price']);
        $this->assertSame('PLN', $r['store_currency']);
        $this->assertSame('40.50', $r['ebay_de_listing_price']);
        $this->assertSame('EUR', $r['ebay_de_listing_currency']);
        $this->assertStringStartsWith('https://ovoko.pl/produkt/', $r['ovoko_url']);
        $this->assertSame('https://gpswiss.pl/produkt/czesc-'.$r['part_id'], $r['store_url']);
        $this->assertSame('https://gpswiss.pl/stary/'.$r['part_id'], $r['store_legacy_url']);
        $this->assertSame(['00001', '00002'], json_decode($r['additional_part_codes'], true));
        $this->assertSame('000123', $r['part_number']);
        $this->assertSame("<p>Pełny; opis\nŁódź</p>", $r['description']);
        $this->assertSame('\\N', $r['oem_number']);
        $full = $this->finish($this->start('czesci', 'full'));
        $this->assertSame("'=formula", $this->csv($full, 'czesci.csv')[14]['name']);
    }

    public function test_local_ebay_price_pln_is_preserved_separately_from_de_listing_price(): void
    {
        DB::table('parts')->where('id', 1)->update(['price' => '50.00', 'currency' => 'PLN', 'ebay_price' => '62.50']);
        DB::table('marketplace_listings')->where('part_id', 1)->where('marketplace', 'ebay_de')->update(['price' => '14.25', 'currency' => 'EUR']);
        $s = $this->finish($this->start());
        $row = $this->csv($s, 'czesci.csv')[0];
        $this->assertSame('1', $row['part_id']);
        $this->assertSame('50.00', $row['store_price']);
        $this->assertSame('PLN', $row['store_currency']);
        $this->assertSame('62.50', $row['ebay_price']);
        $this->assertSame('14.25', $row['ebay_de_listing_price']);
        $this->assertSame('EUR', $row['ebay_de_listing_currency']);
        $this->assertSame('62.50', json_decode($row['source_part_json'], true)['ebay_price']);
        Http::assertNothingSent();
    }

    public function test_fresh_capture_stop_resume_reload_and_no_business_reads_in_projection(): void
    {
        $a = $this->start();
        DB::table('parts')->where('id', 1)->update(['name' => 'Nowsza nazwa']);
        $b = $this->start();
        $this->assertNotSame($a['export_id'], $b['export_id']);
        $service = app(FreshProductExport::class);
        $stopped = $service->action(7, $a['export_id'], 'stop');
        $this->assertSame('stopped', $service->status(7, $a['export_id'])['state']);
        $this->assertSame(0, $service->action(7, $a['export_id'], 'tick')['processed']);
        DB::connection()->beforeExecuting(fn () => throw new \LogicException('SQL forbidden during projection'));
        $service->action(7, $a['export_id'], 'resume');
        $a = $this->finish($a);
        $b = $this->finish($b);
        $this->assertSame('Część 1', $this->csv($a, 'czesci.csv')[0]['name']);
        $this->assertSame('Nowsza nazwa', $this->csv($b, 'czesci.csv')[0]['name']);
        Http::assertNothingSent();
    }

    public function test_multiple_de_conflicting_market_and_shipping_do_not_guess(): void
    {
        DB::table('marketplace_listings')->insert(['part_id' => 1, 'marketplace' => 'ebay_de', 'marketplace_account_id' => 1, 'external_listing_id' => 'duplicate']);
        DB::table('marketplace_listings')->where('part_id', 2)->where('marketplace', 'ebay_de')->update(['raw_payload' => '{"marketplaceId":"EBAY_FR"}']);
        DB::table('marketplace_listings')->insert(['part_id' => 3, 'marketplace' => 'allegro_main', 'marketplace_account_id' => 4, 'external_listing_id' => 'history']);
        DB::table('allegro_shipping_rates')->insert(['marketplace_account_id' => 4, 'shipping_rate_id' => '000RATE', 'shipping_rate_name' => 'KURIER DPD', 'active' => true, 'last_seen_at' => now(), 'synced_at' => now()]);
        $s = $this->finish($this->start('czesci', 'full'));
        $rows = $this->csv($s, 'czesci.csv');
        $this->assertSame('multiple', $rows[0]['ebay_de_resolution_status']);
        $this->assertSame('\\N', $rows[0]['ebay_de_url']);
        $this->assertSame('market_unresolved', $rows[1]['ebay_de_resolution_status']);
        $this->assertSame('\\N', $rows[1]['ebay_de_url']);
        $this->assertSame('000RATE', $rows[2]['allegro_shipping_rate_id']);
        $this->assertSame('4', $rows[2]['allegro_shipping_account_id']);
        $this->assertContains('ebay_market_conflict', array_column(json_decode($rows[1]['issues_json'], true), 'reason_code'));
        $listings = json_decode($rows[0]['marketplace_listings_json'], true);
        $this->assertCount(4, $listings);
        $this->assertCount(2, array_filter($listings, fn ($l) => $l['marketplace'] === 'ebay_de'));
        $this->assertCount(1, array_filter($listings, fn ($l) => $l['marketplace'] === 'ebay_fr'));
        $this->assertContains('multiple_ebay_de_listings', array_column(json_decode($rows[0]['issues_json'], true), 'reason_code'));
    }

    public function test_owner_and_download_integrity(): void
    {
        $s = $this->finish($this->start());
        file_put_contents($this->root.'/'.$s['export_id'].'/czesci.csv', 'corrupt');
        $this->expectException(MigrationExportException::class);
        app(FreshProductExport::class)->download(7, $s['export_id'], 'czesci.csv');
    }

    public function test_authorization_routes_and_owner_isolation(): void
    {
        $url = '/admin/tools/product-migration-export';
        $this->getJson($url)->assertUnauthorized();
        $ordinary = User::query()->create(['name' => 'Ordinary', 'email' => 'ordinary-fresh@test.invalid', 'password' => 'password']);
        $this->actingAs($ordinary)->getJson($url)->assertForbidden();
        $role = Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $ordinary->assignRole($role);
        $this->actingAs($ordinary)->get($url)->assertOk()->assertSee('Rodzaj danych');
        $prepared = $this->actingAs($ordinary)->postJson($url, ['action' => 'prepare', 'type' => 'czesci', 'mode' => 'sample'])->assertOk()->json();
        $this->getJson($url.'/'.$prepared['export_id'])->assertOk()->assertJsonPath('state', 'capturing');
        $this->postJson($url.'/'.$prepared['export_id'], ['action' => 'capture'])->assertOk()->assertJsonPath('state', 'ready');
        $this->postJson($url.'/'.$prepared['export_id'], ['action' => 'tick'])->assertOk()->assertJsonPath('state', 'done');
        $this->get($url.'/'.$prepared['export_id'].'?download=czesci.csv')->assertOk();
        foreach (['zdjecia_czesci.csv', 'marketplace_listingi_czesci.csv', 'relacje_czesci.csv', 'issues.csv'] as $file) {
            $this->getJson($url.'/'.$prepared['export_id'].'?download='.$file)->assertUnprocessable();
        }
        $this->getJson($url.'/'.$prepared['export_id'].'?download=../../.env')->assertUnprocessable();
        try {
            app(FreshProductExport::class)->status(999, $prepared['export_id']);
            $this->fail('Other owner was allowed');
        } catch (MigrationExportException $e) {
            $this->assertSame(404, $e->httpStatus);
        }
        Http::assertNothingSent();
    }

    public function test_resume_rolls_back_uncommitted_output_and_handles_empty_dataset(): void
    {
        $s = $this->start();
        $s = $this->finish($s);
        // Simulate a request dying after appending CSV but before committing the checkpoint.
        $path = $this->root.'/'.$s['export_id'];
        $checkpoint = json_decode(file_get_contents($path.'/state.json'), true);
        $checkpoint['state'] = 'ready';
        file_put_contents($path.'/state.json', json_encode($checkpoint));
        file_put_contents($path.'/czesci.csv', 'uncommitted garbage', FILE_APPEND);
        $service = app(FreshProductExport::class);
        $service->action(7, $s['export_id'], 'resume');
        $s = $service->action(7, $s['export_id'], 'tick');
        $this->assertCount(5, $this->csv($s, 'czesci.csv'));
        DB::table('parts')->update(['needs_listing' => false]);
        $empty = $this->finish($this->start('do_wystawienia', 'full'));
        $this->assertSame(0, $empty['total']);
        $this->assertSame([], $this->csv($empty, 'do_wystawienia.csv'));
    }

    public function test_multiple_ticks_and_deterministic_rich_sample(): void
    {
        DB::table('part_images')->insert(['part_id' => 15, 'path' => 'parts/15/b.jpg', 'is_primary' => false, 'sort_order' => 0]);
        $a = $this->start();
        $b = $this->start();
        $this->assertSame($a['sample_part_ids'], $b['sample_part_ids']);
        $this->assertContains('15', $a['sample_part_ids']);
        for ($id = 16; $id <= 35; $id++) {
            DB::table('parts')->insert(['id' => $id, 'name' => 'Plain '.$id, 'sku' => '000SKU'.$id, 'status' => 'ready']);
        }
        $s = $this->start('czesci', 'full');
        $service = app(FreshProductExport::class);
        $s = $service->action(7, $s['export_id'], 'tick');
        $this->assertSame(25, $s['processed']);
        $this->assertSame('ready', $s['state']);
        $service->action(7, $s['export_id'], 'stop');
        $service->action(7, $s['export_id'], 'resume');
        $s = $this->finish($s);
        $rows = $this->csv($s, 'czesci.csv');
        $this->assertCount(35, $rows);
        $this->assertCount(35, array_unique(array_column($rows, 'part_id')));
    }

    public function test_failed_capture_receipt_survives_status_without_sensitive_exception_text(): void
    {
        $service = app(FreshProductExport::class);
        $s = $service->prepare(7, 'czesci', 'sample');
        DB::connection()->beforeExecuting(function ($sql) {
            if (str_contains($sql, 'select * from "part_images"')) {
                $previous = new \PDOException('SECRET SQL customer payload credentials');
                $previous->errorInfo = ['42S22', 1054, 'SECRET'];
                throw new QueryException('test', $sql, ['SECRET'], $previous);
            }
        });
        try {
            $service->action(7, $s['export_id'], 'capture');
            $this->fail('Failure not injected');
        } catch (QueryException $e) {
        }
        $receipt = json_decode(file_get_contents($this->root.'/'.$s['export_id'].'/operation.json'), true);
        $this->assertSame('capture', $receipt['action']);
        $this->assertSame('relation_read', $receipt['phase']);
        $this->assertSame('part_images', $receipt['current_table']);
        $this->assertSame('missing_column', $receipt['safe_exception_category']);
        $this->assertSame(QueryException::class, $receipt['backend_exception_class']);
        $this->assertSame(5, $receipt['selected_products']);
        $this->assertSame(0, $receipt['capture_processed']);
        $this->assertTrue($receipt['checkpoint_present']);
        $this->assertStringNotContainsString('SECRET', json_encode($receipt));
        $this->assertStringNotContainsString('select ', strtolower(json_encode($receipt)));
        $fresh = app(FreshProductExport::class);
        // Construct a new service to represent a later status-poll request.
        $fresh = new FreshProductExport;
        $status = $fresh->status(7, $s['export_id']);
        $this->assertSame('capture_failed', $status['state']);
        $this->assertSame($receipt, $status['diagnostic']);
        $this->assertSame($receipt, json_decode(file_get_contents($this->root.'/'.$s['export_id'].'/operation.json'), true));
    }

    public function test_controller_returns_safe_diagnostic_instead_of_generic_server_error(): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'diag-owner@test.invalid', 'password' => 'password']);
        $role = Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $user->assignRole($role);
        $url = '/admin/tools/product-migration-export';
        $s = $this->actingAs($user)->postJson($url, ['action' => 'prepare', 'type' => 'czesci', 'mode' => 'sample'])->assertOk()->json();
        DB::connection()->beforeExecuting(function ($sql) {
            if (str_contains($sql, 'select * from "part_images"')) {
                throw new \RuntimeException('SECRET exception with credentials');
            }
        });
        $response = $this->postJson($url.'/'.$s['export_id'], ['action' => 'capture'])->assertStatus(500);
        $response->assertJsonPath('diagnostic.action', 'capture')->assertJsonPath('diagnostic.phase', 'relation_read')->assertJsonPath('diagnostic.current_table', 'part_images');
        $response->assertJsonPath('export_id', $s['export_id']);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_gallery_metadata_and_reference_data_roundtrip_in_one_row(): void
    {
        DB::table('part_images')->where('part_id', 1)->update(['alt_text' => 'Oryginał; "Łódź"', 'legacy_payload' => '{"presentation":{"product_path":"parts/1/product.jpg"}}']);
        DB::table('part_images')->insert(['part_id' => 1, 'path' => 'parts/1/b.jpg', 'sort_order' => 0, 'is_primary' => false]);
        DB::table('cars')->where('id', 1)->update(['gearbox_code' => '000GEAR', 'defects_notes' => 'Notatka auta']);
        $s = $this->finish($this->start('czesci', 'full'));
        $r = $this->csv($s, 'czesci.csv')[0];
        $images = json_decode($r['images_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $images);
        $this->assertSame([1, 2], array_column($images, 'import_order'));
        $this->assertTrue($images[0]['is_primary']);
        $this->assertSame('parts/1/a.jpg', $images[0]['source_path']);
        foreach (['image_id', 'original_name', 'source_path', 'sort_order', 'is_primary', 'role', 'source_type', 'mime_type', 'extension', 'import_order', 'copy_relative_path', 'source_metadata'] as $key) {
            $this->assertArrayHasKey($key, $images[0]);
        }
        $this->assertSame('Oryginał; "Łódź"', $images[0]['source_metadata']['alt_text']);
        $this->assertSame('parts/1/product.jpg', $images[0]['source_metadata']['legacy_payload']['presentation']['product_path']);
        $this->assertSame('Notatka auta', json_decode($r['car_json'], true)['defects_notes']);
        $this->assertSame('000GEAR', $r['car_gearbox_code']);
        foreach (['category_mappings_json', 'part_translations_json', 'category_translations_json'] as $field) {
            $this->assertSame([], json_decode($r[$field], true));
        }
        $manifest = json_decode(file_get_contents(app(FreshProductExport::class)->download(7, $s['export_id'], 'manifest.json')), true);
        $this->assertSame('gpswiss-products-2', $manifest['schema_version']);
        $this->assertSame(['czesci.csv'], array_keys($manifest['files']));
        $this->assertSame(hash_file('sha256', $this->root.'/'.$s['export_id'].'/czesci.csv'), $manifest['files']['czesci.csv']['sha256']);
    }

    public function test_populated_translations_and_mappings_are_embedded_per_part(): void
    {
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 1, 'channel' => 'allegro_main', 'external_category_id' => '000CAT', 'metadata' => '{"source":"local"}']);
        DB::table('part_translations')->insert(['part_id' => 1, 'locale' => 'fr', 'name' => 'Pièce; "complète"', 'description' => '<p>Texte
complet</p>']);
        DB::table('part_translations')->insert(['part_id' => 2, 'locale' => 'fr', 'name' => 'Other part']);
        DB::table('category_translations')->insert(['category_id' => 1, 'locale' => 'fr', 'name' => 'Boîtes']);
        $s = $this->finish($this->start('czesci', 'full'));
        $rows = $this->csv($s, 'czesci.csv');
        $r = $rows[0];
        $this->assertSame('000CAT', $r['allegro_category_id']);
        $this->assertSame('000CAT', json_decode($r['category_mappings_json'], true)[0]['external_category_id']);
        $this->assertSame('local', json_decode($r['category_mappings_json'], true)[0]['metadata']['source']);
        $translations = json_decode($r['part_translations_json'], true);
        $this->assertCount(1, $translations);
        $this->assertSame('1', $translations[0]['part_id']);
        $this->assertSame('Pièce; "complète"', $translations[0]['name']);
        $this->assertSame('Boîtes', json_decode($r['category_translations_json'], true)[0]['name']);
        $this->assertSame([], json_decode($rows[2]['part_translations_json'], true));
    }

    public function test_missing_relations_are_null_objects_and_empty_json_lists(): void
    {
        DB::table('parts')->where('id', 1)->update(['car_id' => null, 'category_id' => null, 'storage_location_id' => null]);
        DB::table('part_images')->where('part_id', 1)->delete();
        DB::table('marketplace_listings')->where('part_id', 1)->delete();
        $s = $this->finish($this->start('czesci', 'full'));
        $r = $this->csv($s, 'czesci.csv')[0];
        foreach (['car_json', 'category_json', 'storage_location_json', 'car_id', 'car_make', 'car_vin'] as $field) {
            $this->assertSame('\\N', $r[$field]);
        }
        foreach (['images_json', 'marketplace_listings_json', 'category_mappings_json', 'part_translations_json', 'category_translations_json'] as $field) {
            $this->assertSame([], json_decode($r[$field], true));
        }
        $issues = json_decode($r['issues_json'], true);
        $this->assertCount((int) $r['issues_count'], $issues);
        foreach (['missing_car', 'missing_category', 'missing_images'] as $reason) {
            $this->assertContains($reason, array_column($issues, 'reason_code'));
        }
    }

    public function test_old_schema_is_not_resumed_or_advertised_as_single_file(): void
    {
        $s = $this->finish($this->start());
        $path = $this->root.'/'.$s['export_id'];
        $state = json_decode(file_get_contents($path.'/state.json'), true);
        $state['schema_version'] = 'gpswiss-products-1';
        file_put_contents($path.'/state.json', json_encode($state));
        $before = file_get_contents($path.'/state.json');
        $csvBefore = file_get_contents($path.'/czesci.csv');
        $service = new FreshProductExport;
        $status = $service->status(7, $s['export_id']);
        $this->assertSame('schema_changed', $status['state']);
        $this->assertSame([], $status['files']);
        try {
            $service->action(7, $s['export_id'], 'resume');
            $this->fail('Old schema resumed');
        } catch (MigrationExportException $e) {
            $this->assertSame('export_schema_changed_prepare_new_export',$e->reasonCode);
        }
        $this->assertSame($before,file_get_contents($path.'/state.json'));
        $this->assertSame($csvBefore,file_get_contents($path.'/czesci.csv'));
    }
}
