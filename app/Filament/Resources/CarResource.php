<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\CarResource\Pages;
use App\Models\Car;
use App\Models\OvokoCarDictionaryEntry;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Illuminate\Support\HtmlString;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CarResource extends Resource
{
    protected static ?string $model = Car::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationGroup = 'Samochody';

    // The Samochody navigation group owns the sidebar icon; child resource items must stay iconless.
    protected static ?string $navigationIcon = null;

    protected static ?string $navigationLabel = 'Wszystkie samochody';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'samochód';

    protected static ?string $pluralModelLabel = 'samochody';

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->extraAttributes(['class' => 'gps-car-form gps-admin-compact-form'])
            ->schema([
                Section::make('Informacje o samochodzie')
                    ->extraAttributes(['class' => 'gps-car-form-section gps-car-form-section--vehicle gps-admin-compact-section gps-admin-floating-label-section'])
                    ->columns(['default' => 1, 'md' => 12])
                    ->schema([
                        Forms\Components\Select::make('legacy_payload.ovoko_brand_id')
                            ->label('Marka')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 12])
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->options(static fn (): array => OvokoCarDictionaryEntry::query()
                                ->where('dictionary', 'brands')
                                ->orderBy('name')
                                ->pluck('name', 'ovoko_id')
                                ->all())
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $brand = $state ? OvokoCarDictionaryEntry::query()->where('dictionary', 'brands')->where('ovoko_id', $state)->first() : null;
                                $set('make', $brand?->name);
                                $set('model', null);
                                $set('model_variant', null);
                                $set('legacy_payload.ovoko_model_group_label', null);
                                $set('legacy_payload.ovoko_car_model_id', null);
                            })
                            ->live(),
                        Forms\Components\Hidden::make('make'),
                        Forms\Components\Select::make('legacy_payload.ovoko_model_group_label')
                            ->label('Model samochodu')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 12])
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->options(static fn (Get $get): array => self::ovokoModelGroupOptions($get('legacy_payload.ovoko_brand_id')))
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $set('model', $state);
                                $set('model_variant', null);
                                $set('legacy_payload.ovoko_car_model_id', null);
                            })
                            ->live()
                            ->disabled(static fn (Get $get): bool => blank($get('legacy_payload.ovoko_brand_id'))),
                        Forms\Components\Hidden::make('model'),
                        Forms\Components\Select::make('legacy_payload.ovoko_car_model_id')
                            ->label('Modyfikacja modelu samochodu')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 12])
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->options(static fn (Get $get): array => self::ovokoModelModificationOptions($get('legacy_payload.ovoko_brand_id'), $get('legacy_payload.ovoko_model_group_label')))
                            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                $modification = self::ovokoModelModification($get('legacy_payload.ovoko_brand_id'), $state);
                                $set('model_variant', $modification ? app(OvokoCarDictionaryService::class)->modelGroupSampleModification($modification)['display_name'] : null);
                            })
                            ->live()
                            ->disabled(static fn (Get $get): bool => blank($get('legacy_payload.ovoko_brand_id')) || blank($get('legacy_payload.ovoko_model_group_label'))),
                        Forms\Components\Hidden::make('model_variant'),
                        Forms\Components\TextInput::make('production_year')
                            ->label('Rok produkcji samochodu')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->required()
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue((int) date('Y') + 1),
                        Forms\Components\TextInput::make('first_registration_year')
                            ->label('Rok pierwszej rejestracji')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue((int) date('Y') + 1),
                        Forms\Components\TextInput::make('registration_number')
                            ->label('Tablica rejestracyjna')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->maxLength(255),
                        Forms\Components\Select::make('steering_side')
                            ->label('Strona kierownicy')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->options(Car::steeringSideOptions())
                            ->native(false),
                        Forms\Components\TextInput::make('mileage_km')
                            ->label('Przebieg km')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\Select::make('legacy_payload.ovoko_fuel_id')
                            ->label('Rodzaj paliwa')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->searchable()
                            ->preload()
                            ->options(static fn (): array => self::ovokoDictionaryOptions('fuel'))
                            ->live()
                            ->afterStateUpdated(static function (?string $state, Set $set): void {
                                $set('fuel_type', self::ovokoDictionaryName('fuel', $state));
                            })
                            ->native(false)
                            ->visible(static fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation === 'create'),
                        Forms\Components\Select::make('fuel_type')
                            ->label('Rodzaj paliwa')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->options(Car::fuelTypeOptions())
                            ->native(false)
                            ->visible(static fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation !== 'create'),
                        Forms\Components\TextInput::make('engine_power_kw')
                            ->label('Moc silnika kW')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('engine_capacity_cm3')
                            ->label('Pojemność silnika cm3')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('engine_code')
                            ->label('Kod silnika')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->maxLength(255),
                        Forms\Components\Select::make('legacy_payload.ovoko_wheel_drive_id')
                            ->label('Napęd')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->searchable()
                            ->preload()
                            ->options(static fn (): array => self::ovokoDictionaryOptions('wheel_drive'))
                            ->live()
                            ->afterStateUpdated(static function (?string $state, Set $set): void {
                                $set('drivetrain', self::ovokoDictionaryName('wheel_drive', $state));
                            })
                            ->native(false)
                            ->visible(static fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation === 'create'),
                        Forms\Components\Select::make('drivetrain')
                            ->label('Napęd')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->options(Car::drivetrainOptions())
                            ->native(false)
                            ->visible(static fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation !== 'create'),
                        Forms\Components\Select::make('legacy_payload.ovoko_gearbox_type_id')
                            ->label('Typ skrzyni biegów')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->searchable()
                            ->preload()
                            ->options(static fn (): array => self::ovokoDictionaryOptions('gearbox_type'))
                            ->live()
                            ->afterStateUpdated(static function (?string $state, Set $set): void {
                                $set('gearbox_type', self::ovokoDictionaryName('gearbox_type', $state));
                            })
                            ->native(false)
                            ->visible(static fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation === 'create'),
                        Forms\Components\Select::make('gearbox_type')
                            ->label('Typ skrzyni biegów')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->options(Car::gearboxTypeOptions())
                            ->native(false)
                            ->visible(static fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation !== 'create'),
                        Forms\Components\TextInput::make('gearbox_code')
                            ->label('Kod skrzyni biegów')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->maxLength(255),
                        Forms\Components\Select::make('legacy_payload.ovoko_body_type_id')
                            ->label('Typ nadwozia')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->searchable()
                            ->preload()
                            ->options(static fn (): array => self::ovokoDictionaryOptions('body_type'))
                            ->live()
                            ->afterStateUpdated(static function (?string $state, Set $set): void {
                                $set('body_type', self::ovokoDictionaryName('body_type', $state));
                            })
                            ->native(false)
                            ->visible(static fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation === 'create'),
                        Forms\Components\TextInput::make('body_type')
                            ->label('Typ nadwozia')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->maxLength(255)
                            ->visible(static fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation !== 'create'),
                        Forms\Components\TextInput::make('color_code')
                            ->label('Kod koloru')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->maxLength(255),
                        Forms\Components\Select::make('color')
                            ->label('Kolor')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->options(static::createFormColorOptions())
                            ->native(false)
                            ->visible(static fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation === 'create'),
                        Forms\Components\TextInput::make('color')
                            ->label('Kolor')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->maxLength(255)
                            ->visible(static fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(static fn (string $operation): bool => $operation !== 'create'),
                        Forms\Components\TextInput::make('interior')
                            ->label('Wnętrze')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('purchase_price')
                            ->label('Cena samochodu')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0),
                        Forms\Components\Toggle::make('includes_vat')
                            ->label('Zawiera podatek VAT')
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->inline(false),
                        Forms\Components\Select::make('status')
                            ->label('Status samochodu / zakupu')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->required()
                            ->options(Car::statusOptions())
                            ->default('kupiony')
                            ->native(false)
                            ->live(),
                        Forms\Components\Select::make('legacy_payload.ovoko_status_id')
                            ->label('Status Ovoko')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->options(static fn (): array => self::ovokoCarStatusOptions())
                            ->required(static fn (string $operation): bool => $operation === 'create')
                            ->helperText('Wymagany do utworzenia samochodu w Ovoko.'),
                        Forms\Components\DatePicker::make('purchase_date')
                            ->label('Data zakupu')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->native(false),
                        Forms\Components\DatePicker::make('dismantled_at')
                            ->label('Data demontażu')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->columnSpan(['default' => 1, 'md' => 6])
                            ->native(false),
                        Forms\Components\Textarea::make('defects_notes')
                            ->label('Notatki dotyczące wad')
                            ->extraFieldWrapperAttributes(['class' => 'gps-car-floating-field'])
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Dane sprzedawcy i zakupu')
                    ->visible(static fn (?Model $record): bool => $record !== null)
                    ->icon('heroicon-o-clipboard-document-list')
                    ->extraAttributes(['class' => 'gps-car-form-section gps-car-form-section--seller'])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
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

    /**
     * Normalize Ovoko dictionary selections into the legacy visible car identity fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeOvokoLocalMappingData(array $data): array
    {
        $payload = is_array($data['legacy_payload'] ?? null) ? $data['legacy_payload'] : [];
        $brandId = (string) ($payload['ovoko_brand_id'] ?? '');
        $modelId = (string) ($payload['ovoko_car_model_id'] ?? '');

        if ($brandId !== '') {
            $brand = OvokoCarDictionaryEntry::query()
                ->where('dictionary', 'brands')
                ->where('ovoko_id', $brandId)
                ->first();

            if ($brand) {
                $data['make'] = $brand->name;
            }
        }

        if (filled($payload['ovoko_model_group_label'] ?? null)) {
            $data['model'] = $payload['ovoko_model_group_label'];
        }

        if ($brandId !== '' && $modelId !== '') {
            $modification = self::ovokoModelModification($brandId, $modelId);

            if ($modification) {
                $data['model_variant'] = app(OvokoCarDictionaryService::class)->modelGroupSampleModification($modification)['display_name'];
            }
        }

        foreach ([
            'ovoko_fuel_id' => ['dictionary' => 'fuel', 'column' => 'fuel_type'],
            'ovoko_gearbox_type_id' => ['dictionary' => 'gearbox_type', 'column' => 'gearbox_type'],
            'ovoko_body_type_id' => ['dictionary' => 'body_type', 'column' => 'body_type'],
            'ovoko_wheel_drive_id' => ['dictionary' => 'wheel_drive', 'column' => 'drivetrain'],
        ] as $payloadKey => $mapping) {
            $dictionaryId = (string) ($payload[$payloadKey] ?? '');

            if ($dictionaryId === '') {
                continue;
            }

            $dictionaryName = self::ovokoDictionaryName($mapping['dictionary'], $dictionaryId);

            if ($dictionaryName !== null) {
                $data[$mapping['column']] = $dictionaryName;
            }
        }

        if (blank($payload['ovoko_status_id'] ?? null) && ($data['status'] ?? null) === 'kupiony') {
            $boughtStatus = OvokoCarDictionaryEntry::query()
                ->where('dictionary', 'car_status')
                ->where('name', 'Kupiony')
                ->where('ovoko_id', '1')
                ->first();

            if ($boughtStatus) {
                $payload['ovoko_status_id'] = (string) $boughtStatus->ovoko_id;
            }
        }

        unset($payload['ovoko_car_id']);
        $data['legacy_payload'] = $payload;

        return $data;
    }


    /**
     * @return array<string, string>
     */
    private static function ovokoCarStatusOptions(): array
    {
        return self::ovokoDictionaryOptions('car_status');
    }

    /**
     * @return array<string, string>
     */
    private static function ovokoDictionaryOptions(string $dictionary): array
    {
        return OvokoCarDictionaryEntry::query()
            ->where('dictionary', $dictionary)
            ->orderBy('ovoko_id')
            ->get(['ovoko_id', 'name'])
            ->mapWithKeys(fn (OvokoCarDictionaryEntry $entry): array => [(string) $entry->ovoko_id => $entry->name ?: (string) $entry->ovoko_id])
            ->all();
    }

    private static function ovokoDictionaryName(string $dictionary, ?string $ovokoId): ?string
    {
        if (blank($ovokoId)) {
            return null;
        }

        $ovokoId = (string) $ovokoId;
        $name = OvokoCarDictionaryEntry::query()
            ->where('dictionary', $dictionary)
            ->where('ovoko_id', $ovokoId)
            ->value('name');

        return filled($name) ? (string) $name : $ovokoId;
    }

    /**
     * @return array<string, string>
     */
    private static function createFormColorOptions(): array
    {
        return [
            'Czerwony' => 'Czerwony',
            'Pomarańczowy' => 'Pomarańczowy',
            'Żółty' => 'Żółty',
            'Zielony' => 'Zielony',
            'Niebieski' => 'Niebieski',
            'Biały' => 'Biały',
            'Fioletowy' => 'Fioletowy',
            'Brązowy' => 'Brązowy',
            'Szary' => 'Szary',
            'Czarny' => 'Czarny',
        ];
    }

    private static function ovokoModelGroupOptions(?string $brandId): array
    {
        if (blank($brandId)) {
            return [];
        }

        $service = app(OvokoCarDictionaryService::class);

        return OvokoCarDictionaryEntry::query()
            ->where('dictionary', 'models')
            ->where('ovoko_brand_id', (string) $brandId)
            ->orderBy('name')
            ->get(['name'])
            ->map(fn (OvokoCarDictionaryEntry $model): string => $service->modelGroupForName((string) $model->name)['model_group_label'])
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (string $label): array => [$label => $label])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function ovokoModelModificationOptions(?string $brandId, ?string $groupLabel): array
    {
        if (blank($brandId) || blank($groupLabel)) {
            return [];
        }

        $service = app(OvokoCarDictionaryService::class);

        return OvokoCarDictionaryEntry::query()
            ->where('dictionary', 'models')
            ->where('ovoko_brand_id', (string) $brandId)
            ->orderBy('year_from')
            ->orderBy('name')
            ->get(['ovoko_id', 'name', 'year_from', 'year_to'])
            ->filter(fn (OvokoCarDictionaryEntry $model): bool => $service->modelGroupForName((string) $model->name)['model_group_label'] === $groupLabel)
            ->mapWithKeys(fn (OvokoCarDictionaryEntry $model): array => [(string) $model->ovoko_id => $service->modelGroupSampleModification($model)['display_name']])
            ->all();
    }

    private static function ovokoModelModification(?string $brandId, ?string $modelId): ?OvokoCarDictionaryEntry
    {
        if (blank($brandId) || blank($modelId)) {
            return null;
        }

        return OvokoCarDictionaryEntry::query()
            ->where('dictionary', 'models')
            ->where('ovoko_brand_id', (string) $brandId)
            ->where('ovoko_id', (string) $modelId)
            ->first();
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
                Tables\Columns\TextColumn::make('model_summary')
                    ->label('Model samochodu')
                    ->state(static fn (Car $record): string => trim(implode(' ', array_filter([
                        $record->make,
                        $record->model,
                    ]))) ?: '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->searchPhrase($search)),
                Tables\Columns\TextColumn::make('parts_placeholder')
                    ->label('Części')
                    ->state(static fn (): HtmlString => new HtmlString('<div class="gps-car-parts-stack"><span>Pozostały: 0</span><span>Sprzedany: 0</span></div>'))
                    ->html(),
                Tables\Columns\TextColumn::make('fuel_type')
                    ->label('Paliwo')
                    ->badge(),
                Tables\Columns\TextColumn::make('gearbox_type')
                    ->label('Skrzynia')
                    ->searchable(),
                Tables\Columns\TextColumn::make('steering_side')
                    ->label('Strona'),
                Tables\Columns\TextColumn::make('engine_capacity_cm3')
                    ->label('Pojemność silnika')
                    ->suffix(' cm3')
                    ->sortable(),
                Tables\Columns\TextColumn::make('color')
                    ->label('Kolor')
                    ->searchable(),
                Tables\Columns\TextColumn::make('body_type')
                    ->label('Rodzaj / typ nadwozia')
                    ->searchable(),
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
                    ->placeholder('—')
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('make')
                    ->label('Marka')
                    ->form([
                        Forms\Components\TextInput::make('value')
                            ->label('Marka')
                            ->placeholder('np. BMW'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('make', 'like', '%' . $data['value'] . '%')
                        : $query),
                Tables\Filters\Filter::make('model')
                    ->label('Model samochodu')
                    ->form([
                        Forms\Components\TextInput::make('value')
                            ->label('Model samochodu')
                            ->placeholder('np. A4'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('model', 'like', '%' . $data['value'] . '%')
                        : $query),
                Tables\Filters\SelectFilter::make('fuel_type')
                    ->label('Paliwo')
                    ->options(Car::fuelTypeOptions()),
                Tables\Filters\SelectFilter::make('gearbox_type')
                    ->label('Skrzynia')
                    ->options(Car::gearboxTypeOptions()),
                Tables\Filters\SelectFilter::make('steering_side')
                    ->label('Strona kierownicy')
                    ->options(Car::steeringSideOptions()),
                Tables\Filters\Filter::make('engine_capacity_cm3')
                    ->label('Pojemność silnika')
                    ->form([
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('from')
                                ->label('Od cm3')
                                ->numeric(),
                            Forms\Components\TextInput::make('until')
                                ->label('Do cm3')
                                ->numeric(),
                        ]),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['from'] ?? null), static fn (Builder $query): Builder => $query->where('engine_capacity_cm3', '>=', $data['from']))
                            ->when(filled($data['until'] ?? null), static fn (Builder $query): Builder => $query->where('engine_capacity_cm3', '<=', $data['until']));
                    }),
                Tables\Filters\Filter::make('color')
                    ->label('Kolor')
                    ->form([
                        Forms\Components\TextInput::make('value')
                            ->label('Kolor')
                            ->placeholder('np. czarny'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('color', 'like', '%' . $data['value'] . '%')
                        : $query),
                Tables\Filters\Filter::make('body_type')
                    ->label('Typ nadwozia / rodzaj')
                    ->form([
                        Forms\Components\TextInput::make('value')
                            ->label('Typ nadwozia / rodzaj')
                            ->placeholder('np. kombi'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('body_type', 'like', '%' . $data['value'] . '%')
                        : $query),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(Car::statusOptions()),
                Tables\Filters\Filter::make('purchase_date')
                    ->label('Data zakupu')
                    ->form([
                        Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')
                                ->label('Od'),
                            Forms\Components\DatePicker::make('until')
                                ->label('Do'),
                        ]),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['from'] ?? null), static fn (Builder $query): Builder => $query->whereDate('purchase_date', '>=', $data['from']))
                            ->when(filled($data['until'] ?? null), static fn (Builder $query): Builder => $query->whereDate('purchase_date', '<=', $data['until']));
                    }),
                Tables\Filters\Filter::make('dismantled_at')
                    ->label('Data demontażu')
                    ->form([
                        Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')
                                ->label('Od'),
                            Forms\Components\DatePicker::make('until')
                                ->label('Do'),
                        ]),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['from'] ?? null), static fn (Builder $query): Builder => $query->whereDate('dismantled_at', '>=', $data['from']))
                            ->when(filled($data['until'] ?? null), static fn (Builder $query): Builder => $query->whereDate('dismantled_at', '<=', $data['until']));
                    }),
                Tables\Filters\Filter::make('created_by')
                    ->label('Utworzone przez')
                    ->form([
                        Forms\Components\TextInput::make('value')
                            ->label('Utworzone przez')
                            ->placeholder('Imię, nazwisko lub e-mail'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('createdBy', static fn (Builder $query): Builder => $query
                            ->where('name', 'like', '%' . $data['value'] . '%')
                            ->orWhere('email', 'like', '%' . $data['value'] . '%'))
                        : $query),
            ])
            ->filtersFormColumns(3)
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
