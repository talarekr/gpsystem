<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\PartResource\Pages;
use App\Models\Car;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Models\StorageLocation;
use App\Services\PartCategorySuggestionService;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Form;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;

class PartResource extends Resource
{
    protected static ?string $model = Part::class;
    protected static ?string $navigationGroup = 'Części';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Wszystkie części';
    protected static ?int $navigationSort = 20;
    protected static ?string $modelLabel = 'część';
    protected static ?string $pluralModelLabel = 'części';

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->extraAttributes(['class' => 'gps-part-form'])
            ->schema([
                Section::make('Zdjęcie kodu części')
                    ->collapsible()
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--code-photo'])
                    ->schema([
                        Forms\Components\FileUpload::make('code_photo_path')
                            ->label('Zdjęcie kodu części')
                            ->hiddenLabel()
                            ->image()
                            ->acceptedFileTypes(['image/*'])
                            ->maxFiles(1)
                            ->disk('public')
                            ->directory('parts/code-photos')
                            ->visibility('public')
                            ->imagePreviewHeight('96')
                            ->panelLayout('integrated')
                            ->placeholder('Przeciągnij i upuść lub wybierz pliki')
                            ->extraAttributes(['class' => 'gps-part-code-photo-upload gps-part-upload-dropzone'])
                            ->columnSpanFull(),
                    ]),

                Section::make('Zdjęcia części')
                    ->collapsible()
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--photos'])
                    ->schema([
                        Forms\Components\FileUpload::make('part_photo_paths')
                            ->label('Zdjęcia części')
                            ->hiddenLabel()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->maxFiles(20)
                            ->image()
                            ->acceptedFileTypes(['image/*'])
                            ->disk('public')
                            ->directory('parts/photos')
                            ->visibility('public')
                            ->storeFiles()
                            ->dehydrated()
                            ->imagePreviewHeight('96')
                            ->placeholder('Przeciągnij i upuść lub wybierz pliki')
                            ->extraAttributes(['class' => 'gps-part-photos-upload gps-part-upload-dropzone'])
                            ->columnSpanFull(),
                    ]),

                Section::make('Informacje o części')
                    ->collapsible()
                    ->columns(12)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--part-info'])
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('Tytuł produktu')->required()->maxLength(255)->live(onBlur: true)->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, ?Part $record): null => self::refreshCategorySuggestion($get, $set, $record))->columnSpanFull(),
                        Forms\Components\TextInput::make('sku')->label('Główny kod części')->unique(ignoreRecord: true)->maxLength(255)->live(onBlur: true)->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, ?Part $record): null => self::refreshCategorySuggestion($get, $set, $record))->columnSpanFull(),
                        Forms\Components\Select::make('category_id')->label('Kategoria')->placeholder('Kategoria')->relationship('category', 'name')->searchable()->preload()->native(false)->suffixAction(self::categoryTreeAction())->columnSpanFull(),
                        Forms\Components\Select::make('condition_notes')->label('Jakość')->placeholder('Jakość')->options(['Używany' => 'Używany', 'Nowy' => 'Nowy', 'Uszkodzony' => 'Uszkodzony', 'Regenerowany' => 'Regenerowany'])->default('Używany')->native(false)->extraFieldWrapperAttributes(['class' => 'gps-part-select-with-chevron'])->columnSpan(6),
                        Forms\Components\Select::make('part_position')->label('Pozycja części (strona zabudowy)')->placeholder('Wybierz')->options(['Wszystkie' => 'Wszystkie', 'Lewa strona' => 'Lewa strona', 'Środek' => 'Środek', 'Prawa strona' => 'Prawa strona', 'Komplet' => 'Komplet', 'Tył strona lewa' => 'Tył strona lewa', 'Tył strona prawa' => 'Tył strona prawa', 'Przód strona lewa' => 'Przód strona lewa', 'Przód strona prawa' => 'Przód strona prawa', 'Przód' => 'Przód', 'Tył' => 'Tył'])->default(null)->native(false)->dehydrated(false)->extraFieldWrapperAttributes(['class' => 'gps-part-select-with-chevron'])->columnSpan(6),
                        Forms\Components\Select::make('steering_side')->label('Kierownica po stronie')->placeholder('Kierownica po stronie')->options(['Lewej' => 'Lewej', 'Prawej' => 'Prawej'])->default('Lewej')->native(false)->dehydrated(false)->extraFieldWrapperAttributes(['class' => 'gps-part-select-with-chevron'])->columnSpan(6),
                        Forms\Components\Select::make('storage_location_id')->label('Magazyn')->placeholder('Wpisz min. 2 znaki')->searchable()->searchDebounce(400)->getSearchResultsUsing(fn (string $search): array => self::storageLocationSearchResults($search))->getOptionLabelUsing(fn ($value): ?string => self::storageLocationOptionLabel($value))->native(false)->extraFieldWrapperAttributes(['class' => 'gps-part-select-with-chevron'])->columnSpan(6),
                        Forms\Components\RichEditor::make('description')->label('Opis')->placeholder('Opis')->columnSpanFull(),
                        Forms\Components\Hidden::make('suggested_category_id'),
                        Forms\Components\Hidden::make('category_confidence'),
                        Forms\Components\Hidden::make('category_suggestion_reason'),
                        Forms\Components\Hidden::make('category_needs_review'),
                    ]),

                Section::make('Informacje o samochodzie')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--vehicle'])
                    ->schema([
                        Forms\Components\Hidden::make('car_id')->live(),
                        Forms\Components\Actions::make([
                            self::chooseCarAction(),
                            self::createCarAction(),
                        ])
                            ->extraAttributes(['class' => 'gps-vehicle-actions'])
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('vehicle_context')
                            ->hiddenLabel()
                            ->content(fn (?Part $record, Forms\Get $get): HtmlString => new HtmlString(self::vehicleContextHtml($record, $get('car_id'))))
                            ->columnSpanFull(),
                    ]),

                Section::make('Ceny')
                    ->collapsible()
                    ->columns(4)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--prices'])
                    ->schema([
                        Forms\Components\TextInput::make('price')->label('Cena')->hiddenLabel()->placeholder('Cena')->numeric()->prefix('PLN')->minValue(0),
                        Forms\Components\TextInput::make('currency')->label('Waluta')->hiddenLabel()->placeholder('Waluta')->default('PLN')->maxLength(3),
                        Forms\Components\TextInput::make('ebay_price')->label('Cena eBay')->hiddenLabel()->placeholder('Cena eBay')->numeric()->prefix('PLN')->minValue(0),
                        Forms\Components\Placeholder::make('marketplace_price_note')->hiddenLabel()->content('Cena Allegro istnieje w bazie, ale sekcja Allegro i wystawianie są celowo ukryte na tym etapie.'),
                    ]),

            ]);
    }



    private static function storageLocationSearchResults(string $search): array
    {
        if (mb_strlen(trim($search)) < 2) {
            return [];
        }

        return self::activeStorageLocationQuery()
            ->where('name', 'like', '%'.trim($search).'%')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (StorageLocation $location) => [$location->id => self::storageLocationLabel($location)])
            ->all();
    }

    private static function storageLocationOptionLabel($value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $location = self::activeStorageLocationQuery()->find($value);

        return $location ? self::storageLocationLabel($location) : null;
    }

    private static function activeStorageLocationQuery(): Builder
    {
        return StorageLocation::query()
            ->when(
                Schema::hasColumn('storage_locations', 'is_active'),
                fn (Builder $query): Builder => $query->where('is_active', true),
            );
    }

    private static function storageLocationLabel(StorageLocation $location): string
    {
        return trim($location->name.' — '.($location->description ?? ''), ' —');
    }


    public static function refreshCategorySuggestion(Forms\Get $get, Forms\Set $set, ?Part $record = null): null
    {
        $suggestion = app(PartCategorySuggestionService::class)->suggestionForInput([
            'name' => $get('name'),
            'sku' => $get('sku'),
            'part_number' => $get('part_number'),
            'oem_number' => $get('oem_number'),
            'manufacturer_code' => $get('manufacturer_code'),
        ], $record?->id);

        if (! $suggestion['category_id']) {
            return null;
        }

        $set('suggested_category_id', $suggestion['category_id']);
        $set('category_confidence', $suggestion['confidence']);
        $set('category_suggestion_reason', $suggestion['reason']);
        $set('category_needs_review', ! $suggestion['auto_fill']);

        if (! $get('category_id') && $suggestion['auto_fill']) {
            $set('category_id', $suggestion['category_id']);
        }

        return null;
    }


    public static function chooseCarAction(): Action
    {
        return Action::make('chooseCar')
            ->label(fn (): string => 'Moje samochody ('.Car::query()->count().')')
            ->icon('heroicon-o-truck')
            ->color('gray')
            ->modalHeading('Moje samochody')
            ->modalSubmitActionLabel('Wybierz samochód')
            ->modalCancelActionLabel('Zamknij')
            ->extraModalWindowAttributes(['class' => 'gps-vehicle-picker-modal'])
            ->slideOver()
            ->fillForm(fn (Forms\Get $get): array => ['selected_car_id' => $get('car_id')])
            ->form([
                Forms\Components\Select::make('selected_car_id')
                    ->label('Wyszukaj samochód')
                    ->placeholder('Wyszukaj samochód')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->allowHtml()
                    ->options(fn (): array => self::carPickerOptions())
                    ->getSearchResultsUsing(fn (string $search): array => self::carPickerOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => self::carPickerOptionLabel($value))
                    ->helperText('Wyniki są ograniczone do 30 samochodów.')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, Forms\Set $set): void {
                if (! empty($data['selected_car_id'])) {
                    $set('car_id', (int) $data['selected_car_id']);
                }
            });
    }

    public static function createCarAction(): Action
    {
        return Action::make('createCar')
            ->label('Nowy samochód')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->modalHeading('Nowy samochód')
            ->modalSubmitActionLabel('Zapisz samochód')
            ->modalCancelActionLabel('Zamknij')
            ->extraModalWindowAttributes(['class' => 'gps-vehicle-picker-modal'])
            ->slideOver()
            ->form(self::quickCarFormSchema())
            ->action(function (array $data, Forms\Set $set): void {
                $car = Car::query()->create($data);
                $set('car_id', $car->id);
            });
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function quickCarFormSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('make')->label('Marka')->maxLength(255)->required(),
                Forms\Components\TextInput::make('model')->label('Model samochodu')->maxLength(255)->required(),
                Forms\Components\TextInput::make('model_variant')->label('Generacja / wersja')->maxLength(255),
                Forms\Components\TextInput::make('production_year')->label('Rok')->numeric()->minValue(1900)->maxValue((int) date('Y') + 1),
                Forms\Components\TextInput::make('body_type')->label('Nadwozie')->maxLength(255),
                Forms\Components\Select::make('fuel_type')->label('Paliwo')->options(Car::fuelTypeOptions())->native(false),
                Forms\Components\TextInput::make('engine_power_kw')->label('Moc kW')->numeric()->minValue(0),
                Forms\Components\TextInput::make('engine_capacity_cm3')->label('Pojemność cm3')->numeric()->minValue(0),
                Forms\Components\TextInput::make('color')->label('Kolor')->maxLength(255),
                Forms\Components\Select::make('drivetrain')->label('Napęd')->options(Car::drivetrainOptions())->native(false),
                Forms\Components\Select::make('steering_side')->label('Strona kierownicy')->options(Car::steeringSideOptions())->native(false),
                Forms\Components\Select::make('gearbox_type')->label('Skrzynia')->options(Car::gearboxTypeOptions())->native(false),
                Forms\Components\TextInput::make('vin')->label('VIN')->maxLength(255)->columnSpanFull(),
                Forms\Components\Select::make('status')->label('Status')->options(Car::statusOptions())->default('kupiony')->native(false)->columnSpanFull(),
            ]),
        ];
    }

    public static function carPickerOptions(?string $search = null): array
    {
        $search = trim((string) $search);

        return Car::query()
            ->when($search !== '', function (Builder $query) use ($search): Builder {
                return $query->where(function (Builder $query) use ($search): void {
                    foreach (['make', 'model', 'model_variant', 'production_year', 'first_registration_year', 'fuel_type', 'body_type', 'color', 'drivetrain', 'steering_side', 'gearbox_type', 'engine_code', 'vin', 'registration_number'] as $field) {
                        $query->orWhere($field, 'like', '%'.$search.'%');
                    }
                });
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Car $car): array => [$car->id => self::carPickerOptionHtml($car)])
            ->all();
    }

    public static function carPickerOptionLabel($value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $car = Car::query()->find($value);

        return $car ? self::carPickerOptionHtml($car) : null;
    }

    public static function carPickerOptionHtml(Car $car): string
    {
        $details = self::carDetails($car);

        return '<div class="gps-vehicle-option"><span class="gps-vehicle-option__icon">🚗</span><span><strong>'.e(self::carLabel($car)).'</strong>'.($details ? '<small>'.e(implode(' · ', $details)).'</small>' : '').'</span></div>';
    }

    public static function categoryTreeAction(): Action
    {
        return Action::make('chooseCategoryFromTree')
            ->label('')
            ->icon('heroicon-m-bars-3')
            ->tooltip('Wybierz kategorię z drzewa')
            ->modalHeading('Kategorie')
            ->modalSubmitActionLabel('Ustaw kategorię')
            ->modalCancelActionLabel('Zamknij')
            ->extraModalWindowAttributes(['class' => 'gps-category-picker-modal'])
            ->slideOver()
            ->form([
                Forms\Components\Hidden::make('selected_category_id'),
                Forms\Components\ViewField::make('category_picker')
                    ->hiddenLabel()
                    ->dehydrated(false)
                    ->view('filament.forms.category-picker')
                    ->viewData(fn (): array => ['categories' => self::categoryPickerCategories()]),
            ])
            ->action(function (array $data, Forms\Set $set): void {
                if (! empty($data['selected_category_id'])) {
                    $set('category_id', $data['selected_category_id']);
                }
            });
    }

    public static function categoryPickerCategories(): array
    {
        $categories = PartCategory::query()
            ->select(['id', 'parent_id', 'name', 'full_slug_path', 'sort_order', 'woo_product_count'])
            ->ordered()
            ->get();

        $childrenByParent = $categories->groupBy('parent_id');
        $categoriesById = $categories->keyBy('id');

        $pathFor = function (PartCategory $category) use (&$pathFor, $categoriesById): string {
            $names = [$category->name];
            $parentId = $category->parent_id;

            while ($parentId && $parent = $categoriesById->get($parentId)) {
                array_unshift($names, $parent->name);
                $parentId = $parent->parent_id;
            }

            return implode(' / ', $names);
        };

        return $categories
            ->map(fn (PartCategory $category): array => [
                'id' => $category->id,
                'parent_id' => $category->parent_id,
                'name' => $category->name,
                'path' => $pathFor($category),
                'full_slug_path' => $category->full_slug_path,
                'woo_product_count' => $category->woo_product_count,
                'has_children' => ($childrenByParent->get($category->id)?->isNotEmpty()) ?? false,
            ])
            ->values()
            ->all();
    }

    public static function categoryOptions(mixed $parentId = 'all'): array
    {
        return PartCategory::query()
            ->when($parentId === 'all', fn (Builder $query) => $query, fn (Builder $query) => filled($parentId) ? $query->where('parent_id', $parentId) : $query->whereNull('parent_id'))
            ->ordered()
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (PartCategory $category): array => [$category->id => trim($category->name.' '.($category->full_slug_path ? '('.$category->full_slug_path.')' : ''))])
            ->all();
    }

    public static function syncPartImages(Part $part, mixed $paths): void
    {
        $paths = collect($paths ?? [])
            ->map(fn (mixed $path): string => trim((string) (is_array($path) ? ($path['path'] ?? $path['file'] ?? '') : $path)))
            ->filter()
            ->unique()
            ->values();

        $existingImages = $part->images()->get()->keyBy('path');
        $keptPaths = $paths->all();

        foreach ($paths as $index => $path) {
            $image = $existingImages->get($path) ?? new PartImage(['path' => $path]);
            $image->part_id = $part->id;
            $image->sort_order = $index;
            $image->is_primary = $index === 0;
            $image->save();
        }

        $part->images()
            ->whereNotIn('path', $keptPaths ?: ['__gps_no_part_photo_paths__'])
            ->delete();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->label('ID')->sortable()->searchable()->weight('bold')->color('primary'),
            Tables\Columns\ImageColumn::make('primary_image_path')->label('Zdjęcie')->disk('public')->height(44)->width(44)->square(),
            Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable(),
            Tables\Columns\TextColumn::make('name')->label('Nazwa')->searchable()->limit(32),
            Tables\Columns\TextColumn::make('part_number')->label('Numer części')->searchable(),
            Tables\Columns\TextColumn::make('oem_number')->label('OEM')->searchable(),
            Tables\Columns\TextColumn::make('manufacturer_code')->label('Kod producenta')->searchable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('description')->label('Opis')->searchable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('condition_notes')->label('Stan')->searchable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('category.name')->label('Kategoria')->searchable(),
            Tables\Columns\TextColumn::make('price')->label('Cena')->money('PLN')->sortable(),
            Tables\Columns\TextColumn::make('allegro_price')->label('Cena Allegro')->money('PLN')->sortable(),
            Tables\Columns\TextColumn::make('ebay_price')->label('Cena eBay')->money('PLN')->sortable(),
            Tables\Columns\TextColumn::make('status')->label('Status')->formatStateUsing(fn (?string $state) => Part::statusOptions()[$state] ?? $state)->badge()->sortable(),
            Tables\Columns\TextColumn::make('car_context')->label('Samochód')->state(fn (Part $record) => $record->car ? self::carLabel($record->car) : '—')->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('car', fn (Builder $q) => $q->where('make','like',"%{$search}%")->orWhere('model','like',"%{$search}%"))),
            Tables\Columns\TextColumn::make('storageLocation.name')->label('Miejsce składowania')->searchable(),
            Tables\Columns\IconColumn::make('is_visible_storefront')->label('Widoczna w sklepie')->boolean(),
            Tables\Columns\TextColumn::make('quantity')->label('Ilość')->sortable(),
            Tables\Columns\TextColumn::make('createdBy.name')->label('Utworzył')->placeholder('—')->searchable(),
            Tables\Columns\TextColumn::make('created_at')->label('Utworzono')->dateTime('Y-m-d H:i')->sortable(),
            Tables\Columns\TextColumn::make('updated_at')->label('Zaktualizowano')->dateTime('Y-m-d H:i')->sortable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->label('Status')->options(Part::statusOptions()),
            Tables\Filters\SelectFilter::make('category_id')->label('Kategoria')->relationship('category', 'name'),
            Tables\Filters\TernaryFilter::make('category_needs_review')->label('Kategoria wymaga sprawdzenia'),
            Tables\Filters\TernaryFilter::make('is_visible_storefront')->label('Widoczna w sklepie'),
            Tables\Filters\SelectFilter::make('car_id')->label('Samochód')->options(fn () => Car::query()->get()->mapWithKeys(fn (Car $car) => [$car->id => self::carLabel($car)])->all()),
            Tables\Filters\SelectFilter::make('storage_location_id')->label('Miejsce składowania')->relationship('storageLocation', 'name'),
            Tables\Filters\Filter::make('condition_notes')->label('Stan / uwagi')->form([Forms\Components\TextInput::make('value')->label('Stan / uwagi')])->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('condition_notes', 'like', '%'.$data['value'].'%') : $query),
            self::rangeFilter('price', 'Cena'), self::rangeFilter('allegro_price', 'Cena Allegro'), self::rangeFilter('ebay_price', 'Cena eBay'),
            Tables\Filters\Filter::make('created_by')->label('Utworzył')->form([Forms\Components\TextInput::make('value')->label('Utworzył')])->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->whereHas('createdBy', fn (Builder $q) => $q->where('name', 'like', '%'.$data['value'].'%')->orWhere('email', 'like', '%'.$data['value'].'%')) : $query),
        ])->filtersFormColumns(3)->actions([Tables\Actions\ViewAction::make()->label('Podgląd'), Tables\Actions\EditAction::make()->label('Edytuj')])->bulkActions([Tables\Actions\DeleteBulkAction::make()->label('Usuń zaznaczone')])->defaultSort('id', 'desc');
    }

    public static function rangeFilter(string $field, string $label): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make($field)->label($label)->form([Grid::make(2)->schema([Forms\Components\TextInput::make('from')->label('Od')->numeric(), Forms\Components\TextInput::make('until')->label('Do')->numeric()])])->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['from'] ?? null), fn (Builder $q) => $q->where($field, '>=', $data['from']))->when(filled($data['until'] ?? null), fn (Builder $q) => $q->where($field, '<=', $data['until'])));
    }

    public static function carLabel(Car $car): string
    {
        $name = trim(implode(' ', array_filter([$car->make, $car->model, $car->model_variant])));
        $year = $car->production_year ?: $car->first_registration_year;

        return trim($name.($year ? ' ('.$year.')' : '')) ?: '#'.$car->id;
    }

    public static function carDetails(Car $car): array
    {
        return array_values(array_filter([
            $car->production_year ? 'rok '.$car->production_year : null,
            $car->body_type,
            $car->fuel_type,
            $car->engine_power_kw ? $car->engine_power_kw.' kW' : null,
            $car->engine_capacity_cm3 ? $car->engine_capacity_cm3.' cm³' : null,
            $car->color,
            $car->drivetrain,
            $car->steering_side,
            $car->gearbox_type,
        ]));
    }

    public static function vehicleContextHtml(?Part $record, mixed $carId): string
    {
        $car = $carId ? Car::query()->find($carId) : null;

        if ($car) {
            $details = self::carDetails($car);

            return '<div class="gps-selected-vehicle"><strong>Wybrano: '.e(self::carLabel($car)).'</strong>'.($details ? '<span>'.e(implode(' · ', $details)).'</span>' : '').'</div>';
        }

        if ($record?->vehicle_snapshot) {
            $snapshot = $record->vehicle_snapshot;
            $name = trim(implode(' ', array_filter([$snapshot['make'] ?? null, $snapshot['model'] ?? null, $snapshot['model_variant'] ?? null])));

            return '<div class="gps-selected-vehicle"><span>Wybrano: '.e($name ?: 'samochód z zapisanej migawki').'</span></div>';
        }

        return '<span class="gps-selected-vehicle gps-selected-vehicle--empty">Nie wybrano samochodu.</span>';
    }

    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make('Dodaj część')->group(static::getNavigationGroup())->sort(static::getNavigationSort())->url(static::getUrl('create'))->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.parts.create')),
            NavigationItem::make('Wszystkie części')->group(static::getNavigationGroup())->sort((static::getNavigationSort() ?? 20) + 1)->url(static::getUrl('index'))->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.parts.index')),
        ];
    }

    public static function canViewAny(): bool { return auth()->user()?->hasAnyRole(self::rolesWithViewAccess()) ?? false; }
    public static function canView(Model $record): bool { return self::canViewAny(); }
    public static function canCreate(): bool { return auth()->user()?->hasAnyRole(self::rolesWithWriteAccess()) ?? false; }
    public static function canEdit(Model $record): bool { return auth()->user()?->hasAnyRole(self::rolesWithWriteAccess()) ?? false; }
    public static function canDelete(Model $record): bool { return auth()->user()?->hasAnyRole(self::rolesWithFullAccess()) ?? false; }
    public static function canDeleteAny(): bool { return auth()->user()?->hasAnyRole(self::rolesWithFullAccess()) ?? false; }
    public static function rolesWithViewAccess(): array { return array_map(fn (UserRole $role) => $role->value, UserRole::cases()); }
    public static function rolesWithWriteAccess(): array { return [UserRole::OwnerAdmin->value, UserRole::Manager->value, UserRole::WarehouseProductStaff->value, UserRole::PricingStaff->value]; }
    public static function rolesWithFullAccess(): array { return [UserRole::OwnerAdmin->value, UserRole::Manager->value]; }
    public static function getPages(): array { return ['index' => Pages\ListParts::route('/'), 'create' => Pages\CreatePart::route('/create'), 'view' => Pages\ViewPart::route('/{record}'), 'edit' => Pages\EditPart::route('/{record}/edit')]; }
}
