<?php

namespace App\Filament\Pages\ImportMigration;

use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\UploadedImportFileResolver;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

class WooProductImportPage extends Page implements HasForms
{
    use InteractsWithForms;

    private const PRODUCTS_MAX_SIZE_KB = 102400;
    private const IMAGES_MAX_SIZE_KB = 102400;
    private const CATEGORIES_MAX_SIZE_KB = 51200;
    private const META_MAX_SIZE_KB = 102400;
    private const ATTRIBUTES_MAX_SIZE_KB = 51200;
    private const SUMMARY_MAX_SIZE_KB = 5120;

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

    public function mount(): void
    {
        $this->form->fill(['mode' => WooProductImport::MODE_DRY_RUN]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        $file = fn (string $name, string $label, int $maxSizeKb, string $helperText, bool $required = false) => Forms\Components\FileUpload::make($name)
            ->label($label)
            ->disk('local')
            ->directory('migration-imports/woo')
            ->maxSize($maxSizeKb)
            ->helperText($helperText)
            ->required($required);

        return $form
            ->schema([
                Forms\Components\Section::make('Import migracyjny')
                    ->description('Tymczasowe narzędzie izolowane od codziennego workflow Części. Nie łączy się z Woo API.')
                    ->schema([
                        $file('products', 'products.csv', self::PRODUCTS_MAX_SIZE_KB, 'products.csv może mieć do 100 MB. Duże pliki CSV są dozwolone tylko w tymczasowym imporcie Woo.', true),
                        $file('images', 'product_images.csv', self::IMAGES_MAX_SIZE_KB, 'product_images.csv może mieć do 100 MB. Duże pliki CSV są dozwolone tylko w tymczasowym imporcie Woo.'),
                        $file('categories', 'product_categories.csv', self::CATEGORIES_MAX_SIZE_KB, 'product_categories.csv może mieć do 50 MB. Duże pliki CSV są dozwolone tylko w tymczasowym imporcie Woo.'),
                        $file('meta', 'product_meta.csv', self::META_MAX_SIZE_KB, 'product_meta.csv może mieć do 100 MB. Duże pliki CSV są dozwolone tylko w tymczasowym imporcie Woo.'),
                        $file('attributes', 'product_attributes.csv', self::ATTRIBUTES_MAX_SIZE_KB, 'product_attributes.csv może mieć do 50 MB. Duże pliki CSV są dozwolone tylko w tymczasowym imporcie Woo.'),
                        $file('summary', 'export_summary.json', self::SUMMARY_MAX_SIZE_KB, 'export_summary.json może mieć do 5 MB.'),
                        Forms\Components\Select::make('mode')
                            ->label('Tryb importu')
                            ->options([
                                WooProductImport::MODE_DRY_RUN => 'Dry run — tylko raport',
                                WooProductImport::MODE_CREATE_ONLY => 'Utwórz tylko brakujące',
                                WooProductImport::MODE_UPDATE_EXISTING => 'Aktualizuj istniejące bezpieczne pola',
                            ])
                            ->required()
                            ->native(false),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('run')
                                ->label('Uruchom import')
                                ->submit('runImport'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function runImport(WooProductImport $import, UploadedImportFileResolver $fileResolver): void
    {
        $this->importError = null;
        $this->report = null;

        try {
            $state = $this->form->getState();
            $batchDirectory = now()->format('Ymd-His').'-'.str()->random(8);
            $path = fn (string $key, string $label) => $fileResolver->resolveOptional($state[$key] ?? null, $label, 'woo', $batchDirectory);
            $productsPath = $fileResolver->resolveRequired($state['products'] ?? null, 'products.csv', 'woo', $batchDirectory);

            $this->report = $import
                ->import($productsPath, [
                    'images' => $path('images', 'product_images.csv'),
                    'categories' => $path('categories', 'product_categories.csv'),
                    'meta' => $path('meta', 'product_meta.csv'),
                    'attributes' => $path('attributes', 'product_attributes.csv'),
                    'summary' => $path('summary', 'export_summary.json'),
                ], $state['mode'])
                ->toArray();

            Notification::make()
                ->title('Import Woo zakończony')
                ->body('Raport importu jest dostępny poniżej formularza.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);
            $this->importError = $exception->getMessage();

            Notification::make()
                ->title('Nie udało się uruchomić importu Woo')
                ->body($this->importError)
                ->danger()
                ->send();
        }
    }

}
