<?php

namespace App\Filament\Pages\ImportMigration;

use App\Services\ImportMigration\ImportReport;
use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

class WooProductImportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'import-migracyjny/produkty-woo';
    protected static ?string $navigationGroup = 'Ustawienia i integracje';
    protected static ?string $navigationLabel = 'Import produktów Woo';
    protected static ?string $title = 'Import migracyjny — produkty Woo';
    protected static ?string $navigationIcon = null;
    protected static ?int $navigationSort = 121;
    protected static string $view = 'filament.pages.import-migration.woo-product-import';

    public ?array $data = [];
    public ?array $report = null;
    public ?string $importError = null;
    public bool $isImportRunning = false;
    public int $totalRows = 0;
    public int $processedRows = 0;
    public int $currentOffset = 0;
    public int $batchSize = 250;
    public int $lastBatchProcessed = 0;
    public ?string $lastBatchStartedAt = null;
    public ?string $lastBatchFinishedAt = null;
    public ?string $lastError = null;
    public int $pollTickCount = 0;
    public ?array $importRun = null;

    public function mount(): void
    {
        app(ManualImportFileResolver::class)->ensureWooDirectoryExists();

        $this->form->fill([
            'products_filename' => 'products.csv',
            'categories_filename' => 'product_categories.csv',
            'meta_filename' => 'product_meta.csv',
            'attributes_filename' => 'product_attributes.csv',
            'summary_filename' => 'export_summary.json',
            'mode' => WooProductImport::MODE_DRY_RUN,
        ]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        $filenameField = fn (string $name, string $label, string $extension, string $helperText, bool $required = false) => Forms\Components\TextInput::make($name)
            ->label($label)
            ->placeholder($label)
            ->helperText($helperText)
            ->datalist(fn (): array => collect(app(ManualImportFileResolver::class)->availableWooFiles())
                ->keys()
                ->filter(fn (string $filename): bool => strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === ltrim(strtolower($extension), '.'))
                ->values()
                ->all())
            ->required($required)
            ->maxLength(255);

        return $form
            ->schema([
                Forms\Components\Section::make('Import migracyjny')
                    ->description('Tymczasowe narzędzie izolowane od codziennego workflow Części. Nie łączy się z Woo API.')
                    ->schema([
                        Forms\Components\Placeholder::make('manual_upload_instructions')
                            ->label('Instrukcja wgrywania plików')
                            ->content(fn (): string => 'Wgraj pliki CSV/JSON przez DirectAdmin lub File Manager do folderu storage/app/imports/manual/woo/, a następnie wpisz albo wybierz poniżej same nazwy plików. products.csv jest wymagany. Pola opcjonalne możesz wyczyścić, jeśli nie chcesz używać danego pliku. Oczekiwany folder na serwerze: '.app(ManualImportFileResolver::class)->wooDirectoryPath()),
                        $filenameField('products_filename', 'products.csv', 'csv', 'Wymagany plik produktów. Musi już istnieć na serwerze w storage/app/imports/manual/woo/.', true),
                        $filenameField('categories_filename', 'product_categories.csv', 'csv', 'Opcjonalny plik kategorii z folderu storage/app/imports/manual/woo/.'),
                        $filenameField('meta_filename', 'product_meta.csv', 'csv', 'Opcjonalny plik metadanych z folderu storage/app/imports/manual/woo/.'),
                        $filenameField('attributes_filename', 'product_attributes.csv', 'csv', 'Opcjonalny plik atrybutów z folderu storage/app/imports/manual/woo/.'),
                        $filenameField('summary_filename', 'export_summary.json', 'json', 'Opcjonalny plik podsumowania z folderu storage/app/imports/manual/woo/.'),
                        $filenameField('images_filename', 'product_images.csv', 'csv', 'Opcjonalny plik obrazów z folderu storage/app/imports/manual/woo/.'),
                        Forms\Components\Select::make('mode')
                            ->label('Tryb importu')
                            ->options([
                                WooProductImport::MODE_DRY_RUN => 'Dry run — tylko raport',
                                WooProductImport::MODE_CREATE_ONLY => 'Utwórz tylko brakujące',
                                WooProductImport::MODE_UPDATE_EXISTING => 'Aktualizuj istniejące bezpieczne pola',
                            ])
                            ->required()
                            ->native(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function runImport(WooProductImport $import, ManualImportFileResolver $fileResolver): void
    {
        $this->importError = null;
        $this->report = $import->makeReport()->toArray();
        $this->isImportRunning = false;
        $this->totalRows = 0;
        $this->processedRows = 0;
        $this->currentOffset = 0;
        $this->lastBatchProcessed = 0;
        $this->lastBatchStartedAt = null;
        $this->lastBatchFinishedAt = null;
        $this->lastError = null;
        $this->pollTickCount = 0;
        $this->importRun = null;

        try {
            $state = $this->form->getState();
            $path = fn (string $key, string $label, string $extension = 'csv') => $fileResolver->resolveOptionalWooFile($state[$key] ?? null, $label, $extension);
            $productsPath = $fileResolver->resolveRequiredWooFile($state['products_filename'] ?? null, 'products.csv', 'csv');

            $this->totalRows = $import->countProductRows($productsPath);
            $this->importRun = [
                'productsPath' => $productsPath,
                'paths' => [
                    'images' => $path('images_filename', 'product_images.csv'),
                    'categories' => $path('categories_filename', 'product_categories.csv'),
                    'meta' => $path('meta_filename', 'product_meta.csv'),
                    'attributes' => $path('attributes_filename', 'product_attributes.csv'),
                    'summary' => $path('summary_filename', 'export_summary.json', 'json'),
                ],
                'mode' => $state['mode'],
                'startedAt' => microtime(true),
                'currentOffset' => 0,
                'totalRows' => $this->totalRows,
                'report' => $this->report,
            ];
            $this->isImportRunning = true;

            if ($this->totalRows === 0) {
                $this->isImportRunning = false;
                $this->importError = 'Plik products.csv nie zawiera wierszy produktów.';
                $this->lastError = $this->importError;

                return;
            }

            $this->processNextBatch($import, false);
        } catch (Throwable $exception) {
            $this->failImport($exception);
        }
    }

    public function processNextBatch(WooProductImport $import, bool $fromPoll = true): void
    {
        if ($fromPoll) {
            $this->pollTickCount++;
        }

        if (! $this->isImportRunning || ! $this->importRun) {
            return;
        }

        try {
            $this->assertImportRunIsUsable();
            $this->lastBatchStartedAt = now()->toDateTimeString();
            $this->lastBatchFinishedAt = null;
            $this->lastBatchProcessed = 0;
            $report = new ImportReport(
                $this->report['counters'] ?? []
            );
            $report->warnings = $this->report['warnings'] ?? [];
            $report->errors = $this->report['errors'] ?? [];

            $report = $import->importBatch(
                $this->importRun['productsPath'],
                $this->importRun['paths'],
                $this->importRun['mode'],
                $this->currentOffset,
                $this->batchSize,
                $report,
                $this->importRun['startedAt'],
            );

            $this->report = $report->toArray();
            $this->lastBatchProcessed = (int) ($this->report['counters']['last_batch_rows'] ?? 0);
            $this->currentOffset += $this->lastBatchProcessed;
            $this->processedRows = $this->currentOffset;
            $this->lastBatchFinishedAt = now()->toDateTimeString();
            $this->lastError = null;
            $this->importRun['currentOffset'] = $this->currentOffset;
            $this->importRun['totalRows'] = $this->totalRows;
            $this->importRun['report'] = $this->report;

            if ($this->lastBatchProcessed === 0 || $this->processedRows >= $this->totalRows) {
                $this->isImportRunning = false;

                Notification::make()
                    ->title('Import Woo zakończony')
                    ->body('Raport importu jest dostępny poniżej formularza.')
                    ->success()
                    ->send();
            }
        } catch (Throwable $exception) {
            $this->failImport($exception);
        }
    }

    private function assertImportRunIsUsable(): void
    {
        $productsPath = $this->importRun['productsPath'] ?? null;

        if (! is_string($productsPath) || ! is_file($productsPath)) {
            throw new \RuntimeException('Nie można kontynuować importu Woo: products.csv nie jest dostępny między partiami.');
        }

        foreach (($this->importRun['paths'] ?? []) as $label => $path) {
            if ($path !== null && (! is_string($path) || ! is_file($path))) {
                throw new \RuntimeException("Nie można kontynuować importu Woo: plik {$label} nie jest dostępny między partiami.");
            }
        }

        if (($this->importRun['mode'] ?? null) === null) {
            throw new \RuntimeException('Nie można kontynuować importu Woo: utracono tryb importu między partiami.');
        }
    }

    private function failImport(Throwable $exception): void
    {
        report($exception);

        $this->isImportRunning = false;
        $this->importError = $exception->getMessage();
        $this->lastError = $this->importError;
        $this->lastBatchFinishedAt = now()->toDateTimeString();
        $this->report ??= ['counters' => [], 'warnings' => [], 'errors' => []];
        $this->report['errors'][] = $this->importError;
        $this->report['counters']['failed_rows'] = ($this->report['counters']['failed_rows'] ?? 0) + 1;

        Notification::make()
            ->title('Nie udało się uruchomić importu Woo')
            ->body($this->importError)
            ->danger()
            ->send();
    }

}
