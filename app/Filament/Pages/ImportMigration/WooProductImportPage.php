<?php

namespace App\Filament\Pages\ImportMigration;

use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Filament\Pages\Page;

class WooProductImportPage extends Page
{

    protected static ?string $slug = 'import-migracyjny/produkty-woo';
    protected static ?string $navigationGroup = 'Ustawienia i integracje';
    protected static ?string $navigationLabel = 'Import produktów Woo';
    protected static ?string $title = 'Import migracyjny — produkty Woo';
    protected static ?string $navigationIcon = null;
    protected static ?int $navigationSort = 121;
    protected static string $view = 'filament.pages.import-migration.woo-product-import';

    public array $defaults = [
        'products_filename' => 'products.csv',
        'categories_filename' => 'product_categories.csv',
        'meta_filename' => 'product_meta.csv',
        'attributes_filename' => 'product_attributes.csv',
        'summary_filename' => 'export_summary.json',
        'images_filename' => '',
        'mode' => WooProductImport::MODE_DRY_RUN,
        'batch_size' => WooProductImportRunRepository::BATCH_SIZE,
    ];
    public array $submittedValues = [];
    public array $availableWooFiles = [];
    public ?array $report = null;
    public ?string $importError = null;
    public array $importDebug = [];
    public bool $isImportRunning = false;
    public int $totalRows = 0;
    public int $processedRows = 0;
    public int $currentOffset = 0;
    public int $batchSize = 25;
    public int $lastBatchProcessed = 0;
    public ?string $lastBatchStartedAt = null;
    public ?string $lastBatchFinishedAt = null;
    public ?string $lastError = null;
    public ?string $runImportStartedAt = null;
    public ?string $firstBatchStartedAt = null;
    public int $pollTickCount = 0;
    public ?array $importRun = null;
    public array $routeDiagnostics = [];

    public function mount(): void
    {
        $fileResolver = app(ManualImportFileResolver::class);

        try {
            $fileResolver->ensureWooDirectoryExists();
            $this->availableWooFiles = $fileResolver->availableWooFiles();
            $this->routeDiagnostics = $this->buildRouteDiagnostics($fileResolver);
        } catch (\Throwable $exception) {
            $this->availableWooFiles = [];
            $this->routeDiagnostics = $this->buildFallbackRouteDiagnostics();
            $this->importError = 'Nie udało się przygotować folderu importu Woo: '.$exception->getMessage();
        }

        $this->submittedValues = session('woo_import_submitted', []);
        $this->importDebug = session('woo_import_debug', []);
        $this->hydrateRouteRun();
    }

    public function fieldValue(string $key): string
    {
        return (string) old($key, $this->defaults[$key] ?? '');
    }

    public function modeValue(): string
    {
        return (string) old('mode', $this->defaults['mode']);
    }

    public function datalistId(string $extension): string
    {
        return 'woo-import-'.ltrim(strtolower($extension), '.').'-files';
    }

    public function availableFilesForExtension(string $extension): array
    {
        return collect($this->availableWooFiles)
            ->keys()
            ->filter(fn (string $filename): bool => strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === ltrim(strtolower($extension), '.'))
            ->values()
            ->all();
    }

    private function hydrateRouteRun(): void
    {
        $this->importError = session('errors')?->first('woo_import');

        try {
            $run = app(WooProductImportRunRepository::class)->find(request()->query('run_id'));
        } catch (\Throwable $exception) {
            $this->importError = trim(($this->importError ? $this->importError.' ' : '').'Nie udało się odczytać stanu uruchomienia importu Woo: '.$exception->getMessage());

            return;
        }

        if (! $run) {
            return;
        }

        $this->importRun = $run;
        $this->report = $run['report'] ?? null;
        $this->isImportRunning = in_array(($run['status'] ?? 'pending'), ['pending', 'running'], true);
        $this->totalRows = (int) ($run['totalRows'] ?? 0);
        $this->processedRows = (int) ($run['processed_rows'] ?? $run['currentOffset'] ?? 0);
        $this->currentOffset = (int) ($run['current_row'] ?? $this->processedRows);
        $this->batchSize = (int) ($run['batch_size'] ?? $run['batchSize'] ?? WooProductImportRunRepository::BATCH_SIZE);
        $this->lastBatchProcessed = (int) ($run['lastBatchProcessed'] ?? 0);
        $this->lastBatchStartedAt = $run['lastBatchStartedAt'] ?? null;
        $this->lastBatchFinishedAt = $run['lastBatchFinishedAt'] ?? null;
        $this->lastError = $run['last_error'] ?? $run['lastError'] ?? null;
        $this->runImportStartedAt = $run['startedAtText'] ?? null;
        $this->firstBatchStartedAt = $this->lastBatchStartedAt;
        $this->importError ??= $this->lastError;
    }

    private function buildRouteDiagnostics(ManualImportFileResolver $fileResolver): array
    {
        $directory = $fileResolver->wooDirectoryPath();
        $productsPath = $directory.DIRECTORY_SEPARATOR.'products.csv';

        return [
            'route_exists' => true,
            'start_route' => route('admin.import-migration.woo-products.start'),
            'diagnostics_route' => route('admin.import-migration.woo-products.diagnostics'),
            'start_ping_route' => route('admin.import-migration.woo-products.start-ping'),
            'post_ping_route' => route('admin.import-migration.woo-products.post-ping'),
            'category_tree_audit_route' => route('admin.import-migration.woo-products.category-tree.audit'),
            'storage_public_diagnostics_route' => route('admin.import-migration.woo-products.storage-public.diagnostics'),
            'controller_class_exists' => class_exists(\App\Http\Controllers\Admin\ImportMigration\WooProductImportRunController::class),
            'manual_folder_path' => $directory,
            'manual_folder_exists' => is_dir($directory),
            'manual_folder_writable' => is_writable($directory),
            'products_csv_path' => $productsPath,
            'products_csv_exists' => is_file($productsPath),
            'products_csv_readable' => is_file($productsPath) && is_readable($productsPath),
            'last_error_log_path' => $directory.DIRECTORY_SEPARATOR.'last_error.log',
            'start_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'start_ping.log',
            'get_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'get_ping.log',
            'post_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'post_ping.log',
        ];
    }

    private function buildFallbackRouteDiagnostics(): array
    {
        $directory = storage_path('app/imports/manual/woo');
        $productsPath = $directory.DIRECTORY_SEPARATOR.'products.csv';

        return [
            'route_exists' => false,
            'start_route' => '/admin/import-migracyjny/produkty-woo/start',
            'diagnostics_route' => '/admin/import-migracyjny/produkty-woo/diagnostyka',
            'start_ping_route' => '/admin/import-migracyjny/produkty-woo/start-ping',
            'post_ping_route' => '/admin/import-migracyjny/produkty-woo/post-ping',
            'category_tree_audit_route' => '/admin/import-migracyjny/produkty-woo/category-tree/audit',
            'storage_public_diagnostics_route' => '/admin/import-migracyjny/produkty-woo/storage-public/diagnostyka',
            'controller_class_exists' => class_exists(\App\Http\Controllers\Admin\ImportMigration\WooProductImportRunController::class),
            'manual_folder_path' => $directory,
            'manual_folder_exists' => is_dir($directory),
            'manual_folder_writable' => is_dir($directory) && is_writable($directory),
            'products_csv_path' => $productsPath,
            'products_csv_exists' => is_file($productsPath),
            'products_csv_readable' => is_file($productsPath) && is_readable($productsPath),
            'last_error_log_path' => $directory.DIRECTORY_SEPARATOR.'last_error.log',
            'start_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'start_ping.log',
            'get_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'get_ping.log',
            'post_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'post_ping.log',
        ];
    }

}
