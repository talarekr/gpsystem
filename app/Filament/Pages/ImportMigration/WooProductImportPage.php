<?php

namespace App\Filament\Pages\ImportMigration;

use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

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
    public ?string $runImportStartedAt = null;
    public ?string $firstBatchStartedAt = null;
    public int $pollTickCount = 0;
    public ?array $importRun = null;

    public function mount(): void
    {
        app(ManualImportFileResolver::class)->ensureWooDirectoryExists();

        $defaults = [
            'products_filename' => 'products.csv',
            'categories_filename' => 'product_categories.csv',
            'meta_filename' => 'product_meta.csv',
            'attributes_filename' => 'product_attributes.csv',
            'summary_filename' => 'export_summary.json',
            'mode' => WooProductImport::MODE_DRY_RUN,
        ];

        $this->form->fill(old('data', $defaults));
        $this->hydrateRouteRun();
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
