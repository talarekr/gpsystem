<?php

namespace Tests\Unit;

use App\Support\ImportMigration\UploadedImportFileResolver;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class UploadedImportFileResolverTest extends TestCase
{
    public function test_resolves_livewire_temporary_path_string_to_stable_local_import_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('livewire-tmp/products.csv', "id,name\n1,Lampa\n");

        $resolved = app(UploadedImportFileResolver::class)->resolveRequired(
            'livewire-tmp/products.csv',
            'products.csv',
            'woo',
            'batch-1',
        );

        $this->assertSame(storage_path('framework/testing/disks/local/imports/woo/batch-1/products.csv'), $resolved);
        $this->assertFileExists($resolved);
        $this->assertSame("id,name\n1,Lampa\n", file_get_contents($resolved));
    }

    public function test_resolves_single_file_array_state_to_stable_local_import_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('migration-imports/ovoko/source.csv', "ovoko_car_id\n1\n");

        $resolved = app(UploadedImportFileResolver::class)->resolveRequired(
            ['upload-key' => 'migration-imports/ovoko/source.csv'],
            'ovoko_donor_cars.csv',
            'ovoko',
            'batch-2',
        );

        $this->assertSame(storage_path('framework/testing/disks/local/imports/ovoko/batch-2/ovoko_donor_cars.csv'), $resolved);
        $this->assertFileExists($resolved);
        $this->assertSame("ovoko_car_id\n1\n", file_get_contents($resolved));
    }

    public function test_missing_required_file_uses_clear_polish_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nie można odczytać przesłanego pliku products.csv. Spróbuj ponownie przesłać plik.');

        app(UploadedImportFileResolver::class)->resolveRequired(
            'livewire-tmp/missing.csv',
            'products.csv',
            'woo',
            'batch-3',
        );
    }
}
