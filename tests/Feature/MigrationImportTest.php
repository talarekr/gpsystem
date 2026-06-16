<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportMigration\OvokoDonorCarImportPage;
use App\Filament\Pages\ImportMigration\WooProductImportPage;
use App\Filament\Resources\CarResource;
use App\Filament\Resources\PartResource;
use App\Models\Car;
use App\Models\Part;
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
