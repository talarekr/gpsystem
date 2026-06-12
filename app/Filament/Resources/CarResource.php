<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\CarResource\Pages;
use App\Models\Car;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CarResource extends Resource
{
    protected static ?string $model = Car::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationGroup = 'Samochody';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Wszystkie samochody';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'samochód';

    protected static ?string $pluralModelLabel = 'samochody';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Zdjęcia')
                    ->description('Dodaj główne zdjęcie samochodu. Galeria wielu zdjęć zostanie rozszerzona w kolejnym etapie, jeżeli będzie potrzebna.')
                    ->schema([
                        Forms\Components\FileUpload::make('main_photo_path')
                            ->label('Główne zdjęcie')
                            ->image()
                            ->imageEditor()
                            ->directory('cars/main-photos')
                            ->visibility('public')
                            ->maxSize(8192)
                            ->columnSpanFull(),
                    ]),

                Section::make('VIN')
                    ->description('Pole przygotowane pod przyszłe uzupełnianie danych po VIN. Zewnętrzne API VIN nie jest jeszcze podłączone.')
                    ->schema([
                        Forms\Components\TextInput::make('vin')
                            ->label('VIN')
                            ->placeholder('Wpisz VIN — automatyczne uzupełnianie zostanie dodane później')
                            ->maxLength(32)
                            ->alphaDash()
                            ->dehydrateStateUsing(static fn (?string $state): ?string => filled($state) ? strtoupper($state) : null),
                    ]),

                Section::make('Informacje o samochodzie')
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('make')
                            ->label('Marka')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('model')
                            ->label('Model samochodu')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('model_variant')
                            ->label('Modyfikacja modelu samochodu')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('production_year')
                            ->label('Rok produkcji samochodu')
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue((int) date('Y') + 1),
                        Forms\Components\TextInput::make('first_registration_year')
                            ->label('Rok pierwszej rejestracji')
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue((int) date('Y') + 1),
                        Forms\Components\TextInput::make('registration_number')
                            ->label('Tablica rejestracyjna')
                            ->maxLength(255),
                        Forms\Components\Select::make('steering_side')
                            ->label('Strona kierownicy')
                            ->options(Car::steeringSideOptions())
                            ->native(false),
                        Forms\Components\TextInput::make('mileage_km')
                            ->label('Przebieg km')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\Select::make('fuel_type')
                            ->label('Rodzaj paliwa')
                            ->options(Car::fuelTypeOptions())
                            ->native(false),
                        Forms\Components\TextInput::make('engine_power_kw')
                            ->label('Moc silnika kW')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('engine_capacity_cm3')
                            ->label('Pojemność silnika cm3')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('engine_code')
                            ->label('Kod silnika')
                            ->maxLength(255),
                        Forms\Components\Select::make('drivetrain')
                            ->label('Napęd')
                            ->options(Car::drivetrainOptions())
                            ->native(false),
                        Forms\Components\Select::make('gearbox_type')
                            ->label('Typ skrzyni biegów')
                            ->options(Car::gearboxTypeOptions())
                            ->native(false),
                        Forms\Components\TextInput::make('gearbox_code')
                            ->label('Kod skrzyni biegów')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('body_type')
                            ->label('Typ nadwozia')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('color_code')
                            ->label('Kod koloru')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('color')
                            ->label('Kolor')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('interior')
                            ->label('Wnętrze')
                            ->maxLength(255),
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('purchase_price')
                                    ->label('Cena samochodu')
                                    ->numeric()
                                    ->prefix('PLN')
                                    ->minValue(0),
                                Forms\Components\Toggle::make('includes_vat')
                                    ->label('Zawiera podatek VAT')
                                    ->inline(false),
                            ]),
                        Forms\Components\Select::make('status')
                            ->label('Status samochodu / zakupu')
                            ->options(Car::statusOptions())
                            ->default('kupiony')
                            ->native(false),
                        Forms\Components\DatePicker::make('purchase_date')
                            ->label('Data zakupu')
                            ->native(false),
                        Forms\Components\DatePicker::make('dismantled_at')
                            ->label('Data demontażu')
                            ->native(false),
                        Forms\Components\Textarea::make('defects_notes')
                            ->label('Notatki dotyczące wad')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Dane sprzedawcy i zakupu')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('seller_name')
                            ->label('Imię i nazwisko / nazwa firmy sprzedawcy')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('seller_identifier')
                            ->label('Numer identyfikacyjny / identyfikator firmy')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('payment_method')
                            ->label('Metoda płatności')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('seller_address')
                            ->label('Adres sprzedawcy')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('deregistration_responsibility')
                            ->label('Odpowiedzialny za wyrejestrowanie')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('documents_storage')
                            ->label('Przechowuje dokumenty')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('purchase_place')
                            ->label('Miejsce zakupu')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('vehicle_location')
                            ->label('Lokalizacja samochodu')
                            ->maxLength(255),
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
                Tables\Columns\ImageColumn::make('main_photo_path')
                    ->label('Zdjęcie')
                    ->square()
                    ->defaultImageUrl(url('/images/car-placeholder.svg')),
                Tables\Columns\TextColumn::make('model_summary')
                    ->label('Model samochodu')
                    ->state(static fn (Car $record): string => trim(implode(' ', array_filter([
                        $record->make,
                        $record->model,
                        $record->model_variant,
                    ]))) ?: '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('make', 'like', "%{$search}%")
                            ->orWhere('model', 'like', "%{$search}%")
                            ->orWhere('model_variant', 'like', "%{$search}%")
                            ->orWhere('vin', 'like', "%{$search}%")
                            ->orWhere('registration_number', 'like', "%{$search}%");
                    }),
                Tables\Columns\TextColumn::make('parts_placeholder')
                    ->label('Części')
                    ->state(static fn (): string => 'Pozostały: 0 / Sprzedany: 0'),
                Tables\Columns\TextColumn::make('fuel_type')
                    ->label('Paliwo')
                    ->badge(),
                Tables\Columns\TextColumn::make('gearbox_type')
                    ->label('Skrzynia'),
                Tables\Columns\TextColumn::make('steering_side')
                    ->label('Strona'),
                Tables\Columns\TextColumn::make('engine_capacity_cm3')
                    ->label('Pojemność silnika')
                    ->suffix(' cm3')
                    ->sortable(),
                Tables\Columns\TextColumn::make('color')
                    ->label('Kolor'),
                Tables\Columns\TextColumn::make('body_type')
                    ->label('Rodzaj / typ nadwozia'),
                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Data zakupu')
                    ->date('Y-m-d')
                    ->sortable(),
                Tables\Columns\TextColumn::make('dismantled_at')
                    ->label('Data demontażu')
                    ->date('Y-m-d')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Utworzone przez')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(Car::statusOptions()),
                Tables\Filters\SelectFilter::make('fuel_type')
                    ->label('Paliwo')
                    ->options(Car::fuelTypeOptions()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edytuj'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Usuń zaznaczone'),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<int, NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! static::shouldRegisterNavigation()) {
            return [];
        }

        $items = [];

        if (static::canCreate()) {
            $items[] = NavigationItem::make('Dodaj samochód')
                ->group(static::getNavigationGroup())
                ->sort(static::getNavigationSort())
                ->url(static::getUrl('create'))
                ->isActiveWhen(static fn (): bool => request()->routeIs('filament.admin.resources.cars.create'));
        }

        $items[] = NavigationItem::make('Wszystkie samochody')
            ->group(static::getNavigationGroup())
            ->sort((static::getNavigationSort() ?? 30) + 1)
            ->url(static::getUrl('index'))
            ->isActiveWhen(static fn (): bool => request()->routeIs('filament.admin.resources.cars.index'));

        return $items;
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
            'index' => Pages\ListCars::route('/'),
            'create' => Pages\CreateCar::route('/create'),
            'edit' => Pages\EditCar::route('/{record}/edit'),
        ];
    }
}
