<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\StorageLocationResource\Pages;
use App\Filament\Resources\StorageLocationResource\RelationManagers\PartsRelationManager;
use App\Models\StorageLocation;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StorageLocationResource extends Resource
{
    protected static ?string $model = StorageLocation::class;

    protected static ?string $navigationGroup = 'Magazynowanie';

    // The Magazynowanie navigation group owns the sidebar icon; child resource items stay iconless.
    protected static ?string $navigationIcon = null;

    protected static ?string $navigationLabel = 'Miejsca składowania';

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'Miejsce składowania';

    protected static ?string $pluralModelLabel = 'Miejsca składowania';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Dane miejsca składowania')
                    ->icon('heroicon-o-building-office-2')
                    ->extraAttributes(['class' => 'gps-car-form-section'])
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nazwa')
                            ->placeholder('np. 1K3-1, 8KNS-1, GTR8')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktywne')
                            ->default(true)
                            ->inline(false),
                        Forms\Components\Textarea::make('description')
                            ->label('Opis')
                            ->placeholder('np. KASTRA 1K3, KONTENER 8KNS')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable()
                    ->weight('bold')
                    ->color('primary'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Opis')
                    ->state(fn (StorageLocation $record): ?string => $record->publicDescription())
                    ->searchable()
                    ->wrap()
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktywne')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Zaktualizowano')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktywne')
                    ->placeholder('Wszystkie')
                    ->trueLabel('Aktywne')
                    ->falseLabel('Nieaktywne'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Podgląd'),
                Tables\Actions\EditAction::make()
                    ->label('Edytuj'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Usuń zaznaczone'),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Szczegóły miejsca składowania')
                    ->icon('heroicon-o-building-office-2')
                    ->extraAttributes(['class' => 'gps-car-form-section'])
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('id')
                                    ->label('ID'),
                                Infolists\Components\TextEntry::make('name')
                                    ->label('Nazwa')
                                    ->weight('bold')
                                    ->color('primary'),
                                Infolists\Components\IconEntry::make('is_active')
                                    ->label('Aktywne')
                                    ->boolean(),
                            ]),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PartsRelationManager::class,
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(self::rolesWithViewAccess()) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasAnyRole(self::rolesWithWriteAccess()) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasAnyRole(self::rolesWithWriteAccess()) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasAnyRole(self::rolesWithFullAccess()) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasAnyRole(self::rolesWithFullAccess()) ?? false;
    }

    /**
     * @return array<int, string>
     */
    public static function rolesWithViewAccess(): array
    {
        return array_map(static fn (UserRole $role): string => $role->value, UserRole::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function rolesWithWriteAccess(): array
    {
        return [
            UserRole::OwnerAdmin->value,
            UserRole::Manager->value,
            UserRole::WarehouseProductStaff->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function rolesWithFullAccess(): array
    {
        return [
            UserRole::OwnerAdmin->value,
            UserRole::Manager->value,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStorageLocations::route('/'),
            'create' => Pages\CreateStorageLocation::route('/create'),
            'view' => Pages\ViewStorageLocation::route('/{record}'),
            'edit' => Pages\EditStorageLocation::route('/{record}/edit'),
        ];
    }
}
