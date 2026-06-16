<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportMigration\OvokoDonorCarImportPage;
use App\Filament\Pages\ImportMigration\WooProductImportPage;
use App\Filament\Resources\CarResource;
use App\Filament\Resources\PartResource;
use App\Models\Car;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\ImportMigration\OvokoDonorCarImport;
use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_ovoko_import_preserves_id_is_repeatable_and_allows_missing_readable_fields(): void
    {
        $csv = $this->tmp('ovoko.csv', "ovoko_car_id,vehicle_make,vehicle_model,vehicle_year,car_engine_cubic_capacity,car_fuel_raw_id,car_gearbox_type_raw_id,car_body_type_raw_id,vehicle_color,car_mileage\n495,,,2011,1995,2,1,4,,123456\n");
        $report = app(OvokoDonorCarImport::class)->import($csv, OvokoDonorCarImport::MODE_CREATE_ONLY);
        $car = Car::query()->findOrFail(495);
        $this->assertSame(495, $car->id);
        $this->assertSame('ovoko', $car->source_system);
        $this->assertSame('495', $car->external_id);
        $this->assertSame('Ovoko pojazd 495', $car->model);
        $this->assertSame(1, $report->counters['created']);
        $this->assertSame(1, $report->counters['diagnostic_total_imported_ovoko_cars']);
        $this->assertSame(495, $report->counters['diagnostic_max_local_car_id']);
        $this->assertSame(495, $report->counters['diagnostic_max_external_id']);
        $this->assertSame(1, $report->counters['diagnostic_ovoko_source_count']);
        $this->assertSame(0, $report->counters['diagnostic_id_mismatch_count']);

        $again = app(OvokoDonorCarImport::class)->import($csv, OvokoDonorCarImport::MODE_CREATE_ONLY);
        $this->assertSame(1, Car::query()->count());
        $this->assertSame(1, $again->counters['skipped_existing']);

        $manual = Car::query()->create(['make' => 'Manual']);
        $this->assertGreaterThan(495, $manual->id);
    }


    public function test_ovoko_cleanup_deletes_only_unlinked_ovoko_cars_and_reports_mismatches(): void
    {
        Car::query()->forceCreate(['id' => 1478, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'source_system' => 'ovoko', 'external_id' => '495', 'model' => 'Wrong import']);
        Car::query()->forceCreate(['id' => 2000, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'source_system' => 'manual', 'external_id' => '495', 'model' => 'Manual']);

        $dryRunReport = app(OvokoDonorCarImport::class)->import(
            $this->tmp('ovoko-diagnostics.csv', "ovoko_car_id,vehicle_make,vehicle_model\n495,,\n"),
            OvokoDonorCarImport::MODE_DRY_RUN,
        );

        $this->assertSame(1, $dryRunReport->counters['diagnostic_id_mismatch_count']);
        $this->assertStringContainsString('Import nie zachował zgodności ID', $dryRunReport->warnings[0]);

        $cleanup = app(OvokoDonorCarImport::class)->cleanupBadImport();

        $this->assertSame(1, $cleanup->counters['deleted']);
        $this->assertNull(Car::query()->find(1478));
        $this->assertNotNull(Car::query()->find(2000));
    }

    public function test_ovoko_cleanup_is_blocked_when_parts_are_linked(): void
    {
        $car = Car::query()->forceCreate(['id' => 1478, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'source_system' => 'ovoko', 'external_id' => '495', 'model' => 'Wrong import']);
        Part::query()->create(['name' => 'Linked part', 'car_id' => $car->id]);

        $cleanup = app(OvokoDonorCarImport::class)->cleanupBadImport();

        $this->assertSame(0, $cleanup->counters['deleted']);
        $this->assertStringContainsString('części jest przypiętych', $cleanup->warnings[0]);
        $this->assertNotNull(Car::query()->find(1478));
    }

    public function test_woo_import_reads_package_links_car_images_categories_and_prevents_duplicates(): void
    {
        Car::query()->create(['id'=>7, 'uuid'=>(string) \Illuminate\Support\Str::uuid(), 'source_system'=>'ovoko', 'external_id'=>'7', 'model'=>'Ovoko pojazd 7']);
        $products = $this->tmp('products.csv', "woo_product_id,sku,name,slug,permalink,short_description,description,price,currency,quantity,status,published,part_number,oem_number,manufacturer_code,condition,brand,manufacturer,storage_location_name,ovoko_car_id,legacy_payload_json\n100,SKU1,Lampa,lampa,http://old/lampa,krotki,opis,99.90,PLN,2,publish,1,P1,OEM1,M1,używana,Brand,Maker,,7,{bad json\n101,SKU2,Szkic,szkic,,,10,PLN,1,draft,0,,,,,,,,999,{}\n");
        $images = $this->tmp('images.csv', "woo_product_id,sku,image_id,image_url,alt_text,position,is_primary\n100,SKU1,501,https://img/1.jpg,Alt,0,1\n");
        $cats = $this->tmp('cats.csv', "woo_product_id,category_id,category_path,category_name,slug\n100,9,Oświetlenie > Lampy,Lampy,lampy\n");
        $meta = $this->tmp('meta.csv', "woo_product_id,meta_key,meta_value\n100,_allegro_offer_id,A1\n");
        $attrs = $this->tmp('attrs.csv', "woo_product_id,name,value\n100,Kolor,Czarny\n");

        $report = app(WooProductImport::class)->import($products, ['images'=>$images,'categories'=>$cats,'meta'=>$meta,'attributes'=>$attrs], WooProductImport::MODE_CREATE_ONLY);
        $part = Part::query()->where('external_id','100')->firstOrFail();
        $this->assertSame('woo', $part->source_system);
        $this->assertSame(7, $part->car_id);
        $this->assertSame('ready', $part->status);
        $this->assertFalse($part->is_visible_storefront);
        $this->assertSame('Lampy', $part->category->name);
        $this->assertTrue($part->images()->first()->is_primary);
        $this->assertSame(1, $report->counters['products_with_missing_car_reference']);
        $this->assertSame('draft', Part::query()->where('external_id','101')->firstOrFail()->status);

        app(WooProductImport::class)->import($products, ['images'=>$images,'categories'=>$cats], WooProductImport::MODE_CREATE_ONLY);
        $this->assertSame(2, Part::query()->count());
        $this->assertSame(1, $part->images()->count());

        $conflict = $this->tmp('conflict.csv', "woo_product_id,sku,name,status,published\n102,SKU1,Duplikat,publish,1\n");
        $conflictReport = app(WooProductImport::class)->import($conflict, [], WooProductImport::MODE_CREATE_ONLY);
        $this->assertSame(1, $conflictReport->counters['skipped_duplicates']);
        $this->assertNull(Part::query()->where('external_id','102')->first());
    }



    public function test_woo_category_duplicate_name_uses_fallback_and_logs_warning(): void
    {
        $warningLog = storage_path('app/imports/manual/woo/category_warning.log');
        if (is_file($warningLog)) {
            unlink($warningLog);
        }

        $existing = PartCategory::query()->create([
            'name' => 'Zamek drzwi przednich',
            'slug' => 'zamek-drzwi-przednich',
        ]);

        $products = $this->tmp('products-category-conflict.csv', "woo_product_id,sku,name,status,published\n516500,LOCK1,Produkt z konfliktem,publish,1\n");
        $cats = $this->tmp('cats-category-conflict.csv', "woo_product_id,category_id,category_path,category_name,slug\n516500,5165,Drzwi i inne elementy > Drzwi przednie / 4-drzwiowy / Kombi > Zamek drzwi przednich,Zamek drzwi przednich,zamek-drzwi-przednich-drzwi-przednie-4-drzwiowy-kombi\n");

        $report = app(WooProductImport::class)->import($products, ['categories' => $cats], WooProductImport::MODE_CREATE_ONLY);

        $part = Part::query()->where('external_id', '516500')->firstOrFail();
        $this->assertSame($existing->id, $part->category_id);
        $this->assertSame(1, PartCategory::query()->where('name', 'Zamek drzwi przednich')->count());
        $this->assertSame(1, $report->counters['category_warning_count']);
        $this->assertFileExists($warningLog);
        $this->assertStringContainsString('fallback_existing_name_before_insert', file_get_contents($warningLog));
    }

    public function test_woo_category_duplicate_name_dry_run_reports_fallback_without_creating_product(): void
    {
        PartCategory::query()->create([
            'name' => 'Zamek drzwi przednich',
            'slug' => 'zamek-drzwi-przednich',
        ]);

        $products = $this->tmp('products-category-conflict-dry.csv', "woo_product_id,sku,name,status,published\n516501,LOCK2,Produkt dry,publish,1\n");
        $cats = $this->tmp('cats-category-conflict-dry.csv', "woo_product_id,category_id,category_path,category_name,slug\n516501,5166,Drzwi i inne elementy > Inna gałąź > Zamek drzwi przednich,Zamek drzwi przednich,zamek-drzwi-przednich-inna-galaz\n");

        $report = app(WooProductImport::class)->import($products, ['categories' => $cats], WooProductImport::MODE_DRY_RUN);

        $this->assertNull(Part::query()->where('external_id', '516501')->first());
        $this->assertSame(1, $report->counters['category_warning_count']);
        $this->assertStringContainsString('matched_existing_name', implode("\n", $report->warnings));
    }

    public function test_woo_import_normalizes_invalid_quantities_and_logs_warnings(): void
    {
        $directory = storage_path('app/imports/manual/woo');
        if (! is_dir($directory)) mkdir($directory, 0777, true);
        $quantityLog = $directory.'/quantity_warning.log';
        if (is_file($quantityLog)) {
            unlink($quantityLog);
        }

        $products = $this->tmp('products-quantity.csv', "woo_product_id,sku,name,quantity,stock_status,manage_stock,status,published,price\n3951,QNEG,AUDI A4 B6 B7 RAMIONA WYCIERACZEK PRZÓD KOMPLET 8E1955408C 8E1955407C,-1,outofstock,yes,publish,1,120\n3952,QBAD,Nieliczbowa,abc,instock,yes,publish,1,130\n3953,QMISS,Brak ilości,,instock,no,publish,1,140\n");

        $report = app(WooProductImport::class)->import($products, [], WooProductImport::MODE_CREATE_ONLY);

        $this->assertSame(3, $report->counters['created']);
        $this->assertSame(3, $report->counters['quantity_warning_count']);
        $this->assertSame(0, Part::query()->where('external_id', '3951')->value('quantity'));
        $this->assertSame(1, Part::query()->where('external_id', '3952')->value('quantity'));
        $this->assertSame(1, Part::query()->where('external_id', '3953')->value('quantity'));
        $this->assertFileExists($quantityLog);
        $log = file_get_contents($quantityLog);
        $this->assertStringContainsString('outofstock_forced_zero', $log);
        $this->assertStringContainsString('invalid_quantity_defaulted', $log);
        $this->assertStringContainsString('missing_quantity_defaulted', $log);
    }

    public function test_woo_dry_run_reports_normalized_quantity_without_failing(): void
    {
        $products = $this->tmp('products-quantity-dry.csv', "woo_product_id,sku,name,quantity,stock_status,manage_stock,status,published,price\n4951,QDRY,Dry quantity,-1,outofstock,yes,publish,1,120\n");

        $report = app(WooProductImport::class)->import($products, [], WooProductImport::MODE_DRY_RUN);

        $this->assertSame(1, $report->counters['quantity_warning_count']);
        $this->assertSame(1, $report->counters['would_create']);
        $this->assertNull(Part::query()->where('external_id', '4951')->first());
        $this->assertStringContainsString('normalized_quantity=0', implode("\n", $report->warnings));
        $this->assertStringContainsString('quantity_warning=outofstock_forced_zero', implode("\n", $report->warnings));
    }


    public function test_woo_import_logs_skipped_existing_products_with_run_id(): void
    {
        $directory = storage_path('app/imports/manual/woo');
        if (! is_dir($directory)) mkdir($directory, 0777, true);
        $skippedLog = $directory.'/skipped_products.log';
        if (is_file($skippedLog)) {
            unlink($skippedLog);
        }

        Part::query()->create([
            'source_system' => 'woo',
            'external_id' => '777',
            'sku' => 'EXIST777',
            'name' => 'Już zaimportowany',
            'status' => 'ready',
        ]);
        file_put_contents($directory.'/products-skipped.csv', "woo_product_id,sku,name,status,published,quantity,price\n777,EXIST777,Już zaimportowany,publish,1,2,199.99\n");

        $runs = app(WooProductImportRunRepository::class);
        $import = app(WooProductImport::class);
        $run = $runs->start([
            'products_filename' => 'products-skipped.csv',
            'categories_filename' => '',
            'meta_filename' => '',
            'attributes_filename' => '',
            'summary_filename' => '',
            'images_filename' => '',
            'mode' => WooProductImport::MODE_CREATE_ONLY,
        ], $import, app(ManualImportFileResolver::class));

        $processed = $runs->processNextBatch($run['id'], $import);
        $this->assertSame(1, $processed['skipped_count']);

        $this->assertFileExists($skippedLog);
        $entry = json_decode(trim((string) file_get_contents($skippedLog)), true);
        $this->assertSame($run['id'], $entry['run_id']);
        $this->assertSame(2, $entry['csv_row_number']);
        $this->assertSame('777', $entry['woo_product_id']);
        $this->assertSame('EXIST777', $entry['sku']);
        $this->assertSame('Już zaimportowany', $entry['name']);
        $this->assertSame('already_exists', $entry['reason']);
        $this->assertSame('publish', $entry['diagnostics']['woo_status']);
        $this->assertSame('2', $entry['diagnostics']['quantity']);
        $this->assertSame('199.99', $entry['diagnostics']['price']);
        $this->assertNotEmpty($entry['diagnostics']['existing_part_id']);
    }

    public function test_woo_import_route_repository_processes_large_files_in_batches(): void
    {
        $directory = storage_path('app/imports/manual/woo');
        if (! is_dir($directory)) mkdir($directory, 0777, true);

        $rows = ["woo_product_id,sku,name,status,published"];
        for ($i = 1; $i <= 260; $i++) {
            $rows[] = "{$i},SKU{$i},Produkt {$i},publish,1";
        }
        file_put_contents($directory.'/products.csv', implode("\n", $rows)."\n");

        $runs = app(WooProductImportRunRepository::class);
        $import = app(WooProductImport::class);
        $run = $runs->start([
            'products_filename' => 'products.csv',
            'categories_filename' => '',
            'meta_filename' => '',
            'attributes_filename' => '',
            'summary_filename' => '',
            'images_filename' => '',
            'mode' => WooProductImport::MODE_DRY_RUN,
        ], $import, app(ManualImportFileResolver::class));

        $run = $runs->processNextBatch($run['id'], $import);
        $this->assertTrue($run['isRunning']);
        $this->assertSame(260, $run['totalRows']);
        $this->assertSame(250, $run['currentOffset']);
        $this->assertSame(250, $run['lastBatchProcessed']);
        $this->assertNull($run['lastError']);

        $run = $runs->processNextBatch($run['id'], $import);
        $this->assertFalse($run['isRunning']);
        $this->assertSame(260, $run['currentOffset']);
        $this->assertSame(10, $run['lastBatchProcessed']);
    }

    public function test_woo_import_page_uses_plain_inline_submit_without_filament_action_modal(): void
    {
        $view = file_get_contents(resource_path('views/filament/pages/import-migration/woo-product-import.blade.php'));
        $page = file_get_contents(app_path('Filament/Pages/ImportMigration/WooProductImportPage.php'));

        $this->assertStringContainsString('<form method="POST" action="{{ route(', $view);
        $this->assertStringContainsString('admin.import-migration.woo-products.start', $view);
        foreach ([
            'products_filename',
            'categories_filename',
            'meta_filename',
            'attributes_filename',
            'summary_filename',
            'images_filename',
        ] as $fieldName) {
            $this->assertStringContainsString("'{$fieldName}'", $view);
        }
        $this->assertStringContainsString('name="{{ $name }}"', $view);
        $this->assertStringContainsString('name="mode"', $view);
        $this->assertStringContainsString("'products_filename' => 'products.csv'", $page);
        $this->assertStringContainsString("'images_filename' => ''", $page);
        $this->assertStringNotContainsString('{{ $this->form }}', $view);
        $this->assertStringNotContainsString("request->input('data'", file_get_contents(app_path('Http/Controllers/Admin/ImportMigration/WooProductImportRunController.php')));
        $this->assertStringNotContainsString('wire:submit', $view);
        $this->assertStringNotContainsString('wire:poll', $view);
        $this->assertSame(1, substr_count($view, 'Uruchom import'));
        $this->assertStringNotContainsString('<x-filament-panels::form', $view);
        $this->assertStringNotContainsString('<x-filament-actions::modals', $view);
        $this->assertStringNotContainsString('Actions\\Action::make', $page);
        $this->assertStringNotContainsString("->submit('runImport')", $page);
        $this->assertStringNotContainsString('getFormActions', $page);
        $this->assertStringNotContainsString('runImport(', $page);
        $this->assertStringNotContainsString('processNextBatch(', $page);
        $this->assertStringNotContainsString('requiresConfirmation', $page);
        $this->assertStringNotContainsString('modal', $page);
    }

    public function test_import_navigation_is_isolated_and_daily_resources_remain_clean(): void
    {
        $this->assertSame('Ustawienia i integracje', OvokoDonorCarImportPage::getNavigationGroup());
        $this->assertSame('Ustawienia i integracje', WooProductImportPage::getNavigationGroup());
        $this->assertSame('Samochody', CarResource::getNavigationGroup());
        $this->assertSame('Wszystkie samochody', CarResource::getNavigationLabel());
        $this->assertSame('Części', PartResource::getNavigationGroup());
        $this->assertSame('Wszystkie części', PartResource::getNavigationLabel());
    }

    private function tmp(string $name, string $contents): string
    {
        $path = storage_path('framework/testing/'.$name);
        if (! is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
        return $path;
    }
}
