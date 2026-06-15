<?php

namespace App\Filament\Pages\ImportMigration;

use App\Services\ImportMigration\OvokoDonorCarImport;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OvokoDonorCarImportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'import-migracyjny/samochody-ovoko';
    protected static ?string $navigationGroup = 'Ustawienia i integracje';
    protected static ?string $navigationLabel = 'Import samochodów Ovoko';
    protected static ?string $title = 'Import migracyjny — samochody Ovoko';
    protected static ?string $navigationIcon = null;
    protected static ?int $navigationSort = 120;
    protected static string $view = 'filament.pages.import-migration.ovoko-donor-car-import';

    public ?array $data = [];
    public ?array $report = null;
    public ?string $importError = null;

    public function mount(): void
    {
        $this->form->fill(['mode' => OvokoDonorCarImport::MODE_DRY_RUN]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Import migracyjny')
                    ->description('Tymczasowe narzędzie izolowane od codziennego workflow Samochody.')
                    ->schema([
                        Forms\Components\FileUpload::make('csv')
                            ->label('ovoko_donor_cars.csv')
                            ->disk('local')
                            ->directory('migration-imports/ovoko')
                            ->required(),
                        Forms\Components\FileUpload::make('summary')
                            ->label('ovoko_donor_cars_summary.json (opcjonalnie)')
                            ->disk('local')
                            ->directory('migration-imports/ovoko'),
                        Forms\Components\Select::make('mode')
                            ->label('Tryb importu')
                            ->options([
                                OvokoDonorCarImport::MODE_DRY_RUN => 'Dry run — tylko raport',
                                OvokoDonorCarImport::MODE_CREATE_ONLY => 'Utwórz tylko brakujące',
                                OvokoDonorCarImport::MODE_UPDATE_EXISTING => 'Aktualizuj istniejące bezpieczne pola',
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

    public function runImport(OvokoDonorCarImport $import): void
    {
        $this->importError = null;
        $this->report = null;

        try {
            $state = $this->form->getState();
            $csvPath = $this->localPath($state['csv'] ?? null, 'ovoko_donor_cars.csv');
            $summaryPath = $this->optionalLocalPath($state['summary'] ?? null);

            $this->report = $import
                ->import($csvPath, $state['mode'], $summaryPath)
                ->toArray();

            Notification::make()
                ->title('Import Ovoko zakończony')
                ->body('Raport importu jest dostępny poniżej formularza.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);
            $this->importError = $exception->getMessage();

            Notification::make()
                ->title('Nie udało się uruchomić importu Ovoko')
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
