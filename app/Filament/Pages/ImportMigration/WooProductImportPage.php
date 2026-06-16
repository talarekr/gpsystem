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
    public int $batchSize = 250;
    public int $lastBatchProcessed = 0;
    public ?string $lastBatchStartedAt = null;
    public ?string $lastBatchFinishedAt = null;
    public ?string $lastError = null;
    public ?string $runImportStartedAt = null;
    public ?string $firstBatchStartedAt = null;
    public int $pollTickCount = 0;
    public ?array $importRun = null;

    public function mount(): void
    {
        $fileResolver = app(ManualImportFileResolver::class);

        try {
            $fileResolver->ensureWooDirectoryExists();
            $this->availableWooFiles = $fileResolver->availableWooFiles();
        } catch (\Throwable $exception) {
            $this->availableWooFiles = [];
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
        $run = app(WooProductImportRunRepository::class)->find(request()->query('run_id'));

        if (! $run) {
            return;
        }

        $this->importRun = $run;
        $this->report = $run['report'] ?? null;
        $this->isImportRunning = (bool) ($run['isRunning'] ?? false);
        $this->totalRows = (int) ($run['totalRows'] ?? 0);
        $this->processedRows = (int) ($run['currentOffset'] ?? 0);
        $this->currentOffset = $this->processedRows;
        $this->batchSize = (int) ($run['batchSize'] ?? WooProductImportRunRepository::BATCH_SIZE);
        $this->lastBatchProcessed = (int) ($run['lastBatchProcessed'] ?? 0);
        $this->lastBatchStartedAt = $run['lastBatchStartedAt'] ?? null;
        $this->lastBatchFinishedAt = $run['lastBatchFinishedAt'] ?? null;
        $this->lastError = $run['lastError'] ?? null;
        $this->runImportStartedAt = $run['startedAtText'] ?? null;
        $this->firstBatchStartedAt = $this->lastBatchStartedAt;
        $this->importError ??= $this->lastError;
    }

}
