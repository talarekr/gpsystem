<?php

namespace App\Filament\Pages\ImportMigration;

use App\Services\ImportMigration\OvokoDonorCarImport;
use Filament\Forms; use Filament\Forms\Concerns\InteractsWithForms; use Filament\Forms\Contracts\HasForms; use Filament\Notifications\Notification; use Filament\Pages\Page;

class OvokoDonorCarImportPage extends Page implements HasForms
{
    use InteractsWithForms;
    protected static ?string $slug = 'import-migracyjny/samochody-ovoko';
    protected static ?string $navigationGroup='Ustawienia i integracje'; protected static ?string $navigationLabel='Import samochodów Ovoko'; protected static ?string $title='Import migracyjny — samochody Ovoko'; protected static ?string $navigationIcon=null; protected static ?int $navigationSort=120;
    protected static string $view='filament.pages.import-migration.ovoko-donor-car-import';
    public ?array $data=[]; public ?array $report=null;
    public function mount(): void { $this->form->fill(['mode'=>OvokoDonorCarImport::MODE_DRY_RUN]); }
    public function form(Forms\Form $form): Forms\Form { return $form->schema([Forms\Components\Section::make('Import migracyjny')->description('Tymczasowe narzędzie izolowane od codziennego workflow Samochody.')->schema([Forms\Components\FileUpload::make('csv')->label('ovoko_donor_cars.csv')->disk('local')->directory('migration-imports/ovoko')->required(),Forms\Components\FileUpload::make('summary')->label('ovoko_donor_cars_summary.json (opcjonalnie)')->disk('local')->directory('migration-imports/ovoko'),Forms\Components\Select::make('mode')->label('Tryb importu')->options([OvokoDonorCarImport::MODE_DRY_RUN=>'Dry run — tylko raport',OvokoDonorCarImport::MODE_CREATE_ONLY=>'Utwórz tylko brakujące',OvokoDonorCarImport::MODE_UPDATE_EXISTING=>'Aktualizuj istniejące bezpieczne pola'])->required()->native(false),Forms\Components\Actions::make([Forms\Components\Actions\Action::make('run')->label('Uruchom import')->submit('runImport')])])])->statePath('data'); }
    public function runImport(OvokoDonorCarImport $import): void { $state=$this->form->getState(); $this->report=$import->import(storage_path('app/'.$state['csv']),$state['mode'],isset($state['summary'])?storage_path('app/'.$state['summary']):null)->toArray(); Notification::make()->title('Import Ovoko zakończony')->success()->send(); }
}
