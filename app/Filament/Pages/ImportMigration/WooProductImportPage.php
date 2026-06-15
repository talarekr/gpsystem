<?php

namespace App\Filament\Pages\ImportMigration;

use App\Services\ImportMigration\WooProductImport;
use Filament\Forms; use Filament\Forms\Concerns\InteractsWithForms; use Filament\Forms\Contracts\HasForms; use Filament\Notifications\Notification; use Filament\Pages\Page;

class WooProductImportPage extends Page implements HasForms
{
    use InteractsWithForms;
    protected static ?string $slug = 'import-migracyjny/produkty-woo';
    protected static ?string $navigationGroup='Ustawienia i integracje'; protected static ?string $navigationLabel='Import produktów Woo'; protected static ?string $title='Import migracyjny — produkty Woo'; protected static ?string $navigationIcon=null; protected static ?int $navigationSort=121;
    protected static string $view='filament.pages.import-migration.woo-product-import';
    public ?array $data=[]; public ?array $report=null;
    public function mount(): void { $this->form->fill(['mode'=>WooProductImport::MODE_DRY_RUN]); }
    public function form(Forms\Form $form): Forms\Form { $file=fn($n,$l,$req=false)=>Forms\Components\FileUpload::make($n)->label($l)->disk('local')->directory('migration-imports/woo')->required($req); return $form->schema([Forms\Components\Section::make('Import migracyjny')->description('Tymczasowe narzędzie izolowane od codziennego workflow Części. Nie łączy się z Woo API.')->schema([$file('products','products.csv',true),$file('images','product_images.csv'),$file('categories','product_categories.csv'),$file('meta','product_meta.csv'),$file('attributes','product_attributes.csv'),$file('summary','export_summary.json'),Forms\Components\Select::make('mode')->label('Tryb importu')->options([WooProductImport::MODE_DRY_RUN=>'Dry run — tylko raport',WooProductImport::MODE_CREATE_ONLY=>'Utwórz tylko brakujące',WooProductImport::MODE_UPDATE_EXISTING=>'Aktualizuj istniejące bezpieczne pola'])->required()->native(false),Forms\Components\Actions::make([Forms\Components\Actions\Action::make('run')->label('Uruchom import')->submit('runImport')])])])->statePath('data'); }
    public function runImport(WooProductImport $import): void { $s=$this->form->getState(); $path=fn($k)=>isset($s[$k])?storage_path('app/'.$s[$k]):null; $this->report=$import->import($path('products'),['images'=>$path('images'),'categories'=>$path('categories'),'meta'=>$path('meta'),'attributes'=>$path('attributes'),'summary'=>$path('summary')],$s['mode'])->toArray(); Notification::make()->title('Import Woo zakończony')->success()->send(); }
}
