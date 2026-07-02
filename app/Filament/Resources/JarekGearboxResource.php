<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JarekGearboxResource\Pages;
use App\Models\JarekGearbox;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class JarekGearboxResource extends Resource
{
    protected static ?string $model = JarekGearbox::class;
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Skrzynie Jarka';
    protected static ?string $modelLabel = 'Skrzynia Jarka';
    protected static ?string $pluralModelLabel = 'Skrzynie Jarka';
    protected static ?int $navigationSort = 80;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Dane pod eBay')->columns(2)->schema([
                Forms\Components\TextInput::make('title')->label('Tytuł')->required()->maxLength(255)->columnSpanFull(),
                Forms\Components\Textarea::make('description')->label('Opis')->rows(6)->columnSpanFull(),
                Forms\Components\Textarea::make('plain_description')->label('Opis prosty')->rows(5)->columnSpanFull(),
                Forms\Components\TextInput::make('price')->label('Cena źródłowa')->numeric()->prefix('PLN'),
                Forms\Components\TextInput::make('quantity')->label('Ilość')->numeric()->minValue(0),
                Forms\Components\Select::make('ebay_status')->label('Status eBay')->options(['not_ready' => 'not_ready', 'previewed' => 'previewed', 'ready' => 'ready', 'published' => 'published'])->default('not_ready'),
                Forms\Components\Placeholder::make('ebay_preview')->label('eBay preview')->content(fn (?JarekGearbox $record): string => $record ? route('admin.tools.jarek-gearboxes.ebay-preview', $record) : 'Zapisz rekord, aby zobaczyć URL preview.'),
            ]),
            Section::make('Źródło Allegro Jarka')->columns(2)->schema([
                Forms\Components\TextInput::make('allegro_offer_id')->disabled()->dehydrated(false),
                Forms\Components\TextInput::make('allegro_status')->disabled()->dehydrated(false),
                Forms\Components\TextInput::make('category_name')->disabled()->dehydrated(false),
                Forms\Components\Textarea::make('ebay_payload_snapshot')->label('eBay payload snapshot')->disabled()->dehydrated(false)->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\ImageColumn::make('main_image_url')->label('Miniatura')->size(48),
            Tables\Columns\TextColumn::make('title')->label('Tytuł')->searchable()->wrap()->limit(80),
            Tables\Columns\TextColumn::make('price')->label('Cena')->money('PLN')->sortable(),
            Tables\Columns\TextColumn::make('allegro_status')->label('Allegro')->badge()->sortable(),
            Tables\Columns\TextColumn::make('quantity')->label('Ilość')->sortable(),
            Tables\Columns\TextColumn::make('ebay_status')->label('eBay')->badge()->sortable(),
            Tables\Columns\TextColumn::make('imported_at')->label('Import')->dateTime('Y-m-d H:i')->sortable(),
            Tables\Columns\TextColumn::make('updated_from_allegro_at')->label('Akt. Allegro')->dateTime('Y-m-d H:i')->sortable(),
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

    public static function canCreate(): bool { return false; }
    public static function canViewAny(): bool { return auth()->check(); }
    public static function canView(Model $record): bool { return auth()->check(); }
    public static function canEdit(Model $record): bool { return auth()->check(); }
    public static function canDelete(Model $record): bool { return false; }
}
