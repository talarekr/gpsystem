<?php

namespace App\Filament\Pages\ImportMigration;

use App\Services\ImportMigration\WooProductImport;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
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

    public function mount(): void
    {
        $this->form->fill(['mode' => WooProductImport::MODE_DRY_RUN]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        $file = fn (string $name, string $label, bool $required = false) => Forms\Components\FileUpload::make($name)
            ->label($label)
            ->disk('local')
            ->directory('migration-imports/woo')
            ->required($required);

        return $form
            ->schema([
                Forms\Components\Section::make('Import migracyjny')
                    ->description('Tymczasowe narzędzie izolowane od codziennego workflow Części. Nie łączy się z Woo API.')
                    ->schema([
                        $file('products', 'products.csv', true),
                        $file('images', 'product_images.csv'),
                        $file('categories', 'product_categories.csv'),
                        $file('meta', 'product_meta.csv'),
                        $file('attributes', 'product_attributes.csv'),
                        $file('summary', 'export_summary.json'),
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

    public function runImport(WooProductImport $import): void
    {
        $this->importError = null;
        $this->report = null;

        try {
            $state = $this->form->getState();
            $path = fn (string $key) => $this->optionalLocalPath($state[$key] ?? null);
            $productsPath = $this->localPath($state['products'] ?? null, 'products.csv');

            $this->report = $import
                ->import($productsPath, [
                    'images' => $path('images'),
                    'categories' => $path('categories'),
                    'meta' => $path('meta'),
                    'attributes' => $path('attributes'),
                    'summary' => $path('summary'),
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

    private function optionalLocalPath(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $this->localPath($value, 'plik opcjonalny');
    }

    private function localPath(mixed $value, string $label): string
    {
        $path = is_array($value) ? reset($value) : $value;

        if (blank($path) || ! is_string($path)) {
            throw new \RuntimeException("Wymagany plik {$label} nie został przesłany.");
        }

        $fullPath = Storage::disk('local')->path($path);

        if (! is_file($fullPath)) {
            throw new \RuntimeException("Nie znaleziono przesłanego pliku {$label}.");
        }

        return $fullPath;
    }
}
