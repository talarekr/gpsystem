<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\PartResource\Pages;
use App\Models\Car;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Models\StorageLocation;
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
                    ->columns(4)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--part-info'])
                    ->schema([
                        Forms\Components\TextInput::make('sku')->label('Główny kod części / SKU')->hiddenLabel()->placeholder('Główny kod części / SKU')->unique(ignoreRecord: true)->maxLength(255)->columnSpan(2),
                        Forms\Components\TextInput::make('part_number')->label('Numer części')->hiddenLabel()->placeholder('Numer części')->maxLength(255),
                        Forms\Components\TextInput::make('manufacturer_code')->label('Kod producenta')->hiddenLabel()->placeholder('Kod producenta')->maxLength(255),
                        Forms\Components\TextInput::make('name')->label('Nazwa części')->hiddenLabel()->placeholder('Nazwa części')->required()->maxLength(255)->columnSpan(2),
                        Forms\Components\TextInput::make('oem_number')->label('Numer OEM')->hiddenLabel()->placeholder('Numer OEM')->maxLength(255),
                        Forms\Components\Select::make('category_id')->label('Kategoria')->hiddenLabel()->placeholder('Kategoria')->relationship('category', 'name')->searchable()->preload()->native(false),
                        Forms\Components\Select::make('suggested_category_id')->label('Sugerowana kategoria')->hiddenLabel()->placeholder('Sugerowana kategoria')->relationship('suggestedCategory', 'name')->searchable()->preload()->native(false)->columnSpan(2),
                        Forms\Components\TextInput::make('category_confidence')->label('Pewność sugestii')->hiddenLabel()->placeholder('Pewność sugestii')->numeric()->suffix('%'),
                        Forms\Components\Toggle::make('category_needs_review')->label('Wymaga sprawdzenia')->inline(false),
                        Forms\Components\Textarea::make('category_suggestion_reason')->label('Powód sugestii')->hiddenLabel()->placeholder('Powód sugestii')->rows(2)->columnSpanFull(),
                        Forms\Components\Textarea::make('condition_notes')->label('Uwagi na etykiecie / stan')->hiddenLabel()->placeholder('Uwagi na etykiecie / stan')->rows(2)->columnSpanFull(),
                    ]),

                Section::make('Informacje o samochodzie')
                    ->collapsible()
                    ->collapsed()
                    ->columns(3)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--vehicle'])
                    ->schema([
                        Forms\Components\Select::make('car_id')->label('Auto dawca')->hiddenLabel()->placeholder('Auto dawca')->options(fn () => Car::query()->orderByDesc('id')->get()->mapWithKeys(fn (Car $car) => [$car->id => self::carLabel($car)])->all())->searchable()->live()->native(false)->columnSpanFull(),
                        Forms\Components\Placeholder::make('vehicle_context')->label('Dane pojazdu')->content(fn (?Part $record, Forms\Get $get): HtmlString => new HtmlString(self::vehicleContextHtml($record, $get('car_id'))))->columnSpanFull(),
                    ]),

                Section::make('Magazyn')
                    ->collapsible()
                    ->columns(4)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--stock'])
                    ->schema([
                        Forms\Components\Select::make('storage_location_id')->label('Miejsce składowania')->hiddenLabel()->placeholder('Miejsce składowania')->options(fn () => StorageLocation::query()->orderBy('name')->get()->mapWithKeys(fn (StorageLocation $location) => [$location->id => trim($location->name.' — '.($location->description ?? ''))])->all())->searchable()->native(false)->columnSpan(2),
                        Forms\Components\TextInput::make('quantity')->label('Ilość')->hiddenLabel()->placeholder('Ilość')->numeric()->default(1)->minValue(0),
                        Forms\Components\Select::make('status')->label('Status')->hiddenLabel()->placeholder('Status')->options(Part::statusOptions())->default('draft')->native(false),
                        Forms\Components\Toggle::make('is_visible_storefront')->label('Widoczna w sklepie')->default(false)->inline(false),
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

                Section::make('Sklep')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--store'])
                    ->schema([
                        Forms\Components\TextInput::make('slug')->label('Slug')->hiddenLabel()->placeholder('Slug')->unique(ignoreRecord: true)->maxLength(255),
                        Forms\Components\Placeholder::make('store_visibility_note')->label('Widoczność')->content('Widoczność w sklepie ustawisz w sekcji Magazyn.'),
                    ]),

                Section::make('Opisy')
                    ->collapsible()
                    ->collapsed()
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--descriptions'])
                    ->schema([
                        Forms\Components\Textarea::make('short_description')->label('Krótki opis')->hiddenLabel()->placeholder('Krótki opis')->rows(2),
                        Forms\Components\RichEditor::make('description')->label('Opis')->hiddenLabel()->placeholder('Opis')->columnSpanFull(),
                    ]),
            ]);
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
        return '#'.$car->id.' — '.trim(implode(' ', array_filter([$car->make, $car->model, $car->production_year]))) ?: '#'.$car->id;
    }

    public static function vehicleContextHtml(?Part $record, mixed $carId): string
    {
        $snapshot = $carId ? Car::query()->find($carId)?->only(['make','model','model_variant','production_year','fuel_type','gearbox_type','engine_capacity_cm3','engine_code','color','steering_side']) : ($record?->vehicle_snapshot ?? []);
        if (! $snapshot) { return '<span>Wybierz samochód, aby zobaczyć kontekst pojazdu.</span>'; }
        $labels = ['make'=>'Marka','model'=>'Model','model_variant'=>'Modyfikacja / wersja','production_year'=>'Rok produkcji','fuel_type'=>'Paliwo','gearbox_type'=>'Skrzynia','engine_capacity_cm3'=>'Pojemność silnika','engine_code'=>'Kod silnika','color'=>'Kolor','steering_side'=>'Strona kierownicy'];
        $rows = collect($labels)->map(fn ($label, $key) => '<div><strong>'.$label.':</strong> '.e($snapshot[$key] ?? '—').'</div>')->implode('');
        return '<div class="grid gap-1 text-sm md:grid-cols-3">'.$rows.'</div>';
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
