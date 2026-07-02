<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JarekGearboxResource\Pages;
use App\Models\JarekGearbox;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class JarekGearboxResource extends Resource
{
    protected static ?string $model = JarekGearbox::class;
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationLabel = 'Skrzynie Jarka';
    protected static ?string $modelLabel = 'Skrzynia Jarka';
    protected static ?string $pluralModelLabel = 'Skrzynie Jarka';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dane eBay')->schema([
                Forms\Components\TextInput::make('title')->label('Tytuł')->required()->maxLength(255),
                Forms\Components\Textarea::make('description')->label('Opis HTML / opis do eBay')->rows(8)->columnSpanFull(),
                Forms\Components\Textarea::make('plain_description')->label('Opis tekstowy')->rows(5)->columnSpanFull(),
                Forms\Components\TextInput::make('price')->label('Cena źródłowa')->numeric()->prefix('PLN'),
                Forms\Components\TextInput::make('quantity')->label('Ilość')->numeric()->minValue(0),
            ])->columns(2),
            Forms\Components\Section::make('Allegro Jarka')->schema([
                Forms\Components\TextInput::make('source_account')->disabled(),
                Forms\Components\TextInput::make('allegro_offer_id')->disabled(),
                Forms\Components\TextInput::make('allegro_offer_url')->disabled(),
                Forms\Components\TextInput::make('allegro_status')->disabled(),
                Forms\Components\TextInput::make('category_id')->label('Category ID'),
                Forms\Components\TextInput::make('category_name')->label('Category name'),
            ])->columns(2),
            Forms\Components\Section::make('eBay preview / status')->schema([
                Forms\Components\TextInput::make('ebay_status')->disabled(),
                Forms\Components\TextInput::make('ebay_listing_id')->disabled(),
                Forms\Components\TextInput::make('ebay_offer_id')->disabled(),
                Forms\Components\TextInput::make('ebay_inventory_sku')->label('SKU eBay'),
                Forms\Components\KeyValue::make('ebay_payload_snapshot')->disabled()->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\ImageColumn::make('main_image_url')->label('Foto')->square(),
            Tables\Columns\TextColumn::make('title')->label('Tytuł')->searchable()->wrap()->sortable(),
            Tables\Columns\TextColumn::make('price')->label('Cena')->money('PLN')->sortable(),
            Tables\Columns\TextColumn::make('allegro_status')->label('Allegro')->badge()->sortable(),
            Tables\Columns\TextColumn::make('quantity')->label('Ilość')->sortable(),
            Tables\Columns\TextColumn::make('ebay_status')->label('eBay')->badge()->sortable(),
            Tables\Columns\TextColumn::make('updated_from_allegro_at')->label('Akt. Allegro')->dateTime('Y-m-d H:i')->sortable(),
            Tables\Columns\TextColumn::make('imported_at')->label('Import')->dateTime('Y-m-d H:i')->sortable(),
        ])->actions([
            Tables\Actions\ViewAction::make()->label('Podgląd'),
            Tables\Actions\EditAction::make()->label('Edycja'),
            Tables\Actions\Action::make('ebay_preview')->label('eBay preview/dry-run')->url(fn (JarekGearbox $record): string => route('admin.tools.jarek-gearboxes.ebay-preview', $record))->openUrlInNewTab(),
        ])->defaultSort('updated_from_allegro_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListJarekGearboxes::route('/'), 'view' => Pages\ViewJarekGearbox::route('/{record}'), 'edit' => Pages\EditJarekGearbox::route('/{record}/edit')];
    }
}
