<?php

namespace Tests\Unit;

use App\Support\ImportMigration\ManualImportFileResolver;
use RuntimeException;
use Tests\TestCase;

class ManualImportFileResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        $directory = storage_path('app/'.ManualImportFileResolver::WOO_DIRECTORY);

        if (is_dir($directory)) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                if (is_file($file) || is_link($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    public function test_it_resolves_woo_files_from_laravel_storage_path(): void
    {
        $resolver = app(ManualImportFileResolver::class);
        $resolver->ensureWooDirectoryExists();

        $expectedDirectory = storage_path('app/imports/manual/woo');
        file_put_contents($expectedDirectory.'/products.csv', "id,name\n1,Lampa\n");

        $this->assertSame($expectedDirectory, $resolver->wooDirectoryPath());
        $this->assertSame(
            realpath($expectedDirectory.'/products.csv'),
            $resolver->resolveRequiredWooFile('products.csv', 'products.csv', 'csv'),
        );
    }

    public function test_missing_file_message_includes_filename_and_absolute_checked_path(): void
    {
        $resolver = app(ManualImportFileResolver::class);
        $expectedPath = storage_path('app/imports/manual/woo/products.csv');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Brak pliku products.csv. Oczekiwany plik: products.csv. Sprawdzona ścieżka: {$expectedPath}.");

        $resolver->resolveRequiredWooFile('products.csv', 'products.csv', 'csv');
    }

    public function test_it_rejects_path_traversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nieprawidłowa nazwa pliku products.csv. Podaj tylko nazwę pliku bez katalogów.');

        app(ManualImportFileResolver::class)->resolveRequiredWooFile('../products.csv', 'products.csv', 'csv');
    }
}
