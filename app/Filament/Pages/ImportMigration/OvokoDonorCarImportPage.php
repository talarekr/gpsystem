<?php

namespace App\Filament\Pages\ImportMigration;

use App\Services\ImportMigration\OvokoDonorCarImport;
use App\Support\ImportMigration\UploadedImportFileResolver;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
    public ?array $cleanupReport = null;

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
                            Forms\Components\Actions\Action::make('cleanupBadImport')
                                ->label('Usuń błędny import samochodów Ovoko')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalHeading('Usunąć błędny import samochodów Ovoko?')
                                ->modalDescription('Usunięte zostaną wyłącznie samochody z source_system = ovoko. Jeżeli są do nich przypięte części, cleanup zostanie zablokowany.')
                                ->action('cleanupBadImport'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function cleanupBadImport(OvokoDonorCarImport $import): void
    {
        $this->importError = null;
        $this->cleanupReport = null;

        try {
            $this->cleanupReport = $import->cleanupBadImport()->toArray();

            $deleted = $this->cleanupReport['counters']['deleted'] ?? 0;

            Notification::make()
                ->title('Cleanup importu Ovoko zakończony')
                ->body("Usunięto samochody Ovoko: {$deleted}.")
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);
            $this->importError = $exception->getMessage();

            Notification::make()
                ->title('Nie udało się wykonać cleanup importu Ovoko')
                ->body($this->importError)
                ->danger()
                ->send();
        }
    }

    public function runImport(OvokoDonorCarImport $import, UploadedImportFileResolver $fileResolver): void
    {
        $this->importError = null;
        $this->report = null;

        try {
            $state = $this->form->getState();
            $batchDirectory = now()->format('Ymd-His').'-'.str()->random(8);
            $csvPath = $fileResolver->resolveRequired($state['csv'] ?? null, 'ovoko_donor_cars.csv', 'ovoko', $batchDirectory);
            $summaryPath = $fileResolver->resolveOptional($state['summary'] ?? null, 'ovoko_donor_cars_summary.json', 'ovoko', $batchDirectory);

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

}
