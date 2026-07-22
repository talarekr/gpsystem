<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\PartResource\Pages;
use App\Models\Car;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Services\Marketplace\AllegroCategoryResolver;
use App\Services\Marketplace\AllegroManualParameterSelectionService;
use App\Services\Marketplace\AllegroSalesSettingsResolver;
use App\Services\Marketplace\PreparePartMarketplaceListingService;
use App\Services\Parts\PartImageUploadService;
use App\Models\StorageLocation;
use App\Services\PartCategorySuggestionService;
use App\Support\Parts\AdditionalPartCodes;
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
    public const ADMIN_STEERING_FIELD_PATH = 'vehicle_snapshot.steering_side';
    public const ADMIN_STEERING_FORM_STATE = 'steering_side';
    public const DEFAULT_CONDITION_VALUE = 'Używany';
    public const EXPECTED_LEFT_STEERING_VALUE = 'po lewej';
    public const EXPECTED_RIGHT_STEERING_VALUE = 'po prawej';
    public const PART_TITLE_MAX_LENGTH = 75;
    public const ALLEGRO_MANUAL_PARAMETERS_FIELD = 'allegro_dynamic_parameter_values';
    public const ALLEGRO_DYNAMIC_PARAMETER_FIELDS = 'allegro_dynamic_parameter_fields';
    public const ALLEGRO_DYNAMIC_PARAMETERS_REPEATER = 'allegro_dynamic_parameters';
    public const ADMIN_STEERING_OPTIONS = [
        self::EXPECTED_LEFT_STEERING_VALUE => self::EXPECTED_LEFT_STEERING_VALUE,
        self::EXPECTED_RIGHT_STEERING_VALUE => self::EXPECTED_RIGHT_STEERING_VALUE,
    ];

    protected static ?string $model = Part::class;
    protected static ?string $navigationGroup = 'Części';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Części';
    protected static ?int $navigationSort = 20;
    protected static ?string $modelLabel = 'część';
    protected static ?string $pluralModelLabel = 'części';


    public static function dynamicAllegroParameterDefinitions(?Part $record): array
    {
        if (! $record || ! $record->exists) return [];
        $params = (array) data_get((array) ($record->review_metadata ?: []), 'marketplace_prepare_results.allegro.dynamic_allegro_parameters.fields', []);
        if ($params === []) {
            $params = (array) data_get((array) ($record->review_metadata ?: []), 'marketplace_prepare_results.allegro.missing_required_allegro_parameters', []);
        }
        $supported = [];
        foreach ($params as $param) {
            $definition = self::normalizeDynamicAllegroParameterField($param);
            if ($definition === null) continue;
            $supported[(string) $definition['id']] = $definition;
        }

        $categoryId = self::currentAllegroCategoryId($record);
        if ($categoryId !== '') {
            foreach ($record->allegroParameterSelections()->where('allegro_category_id', $categoryId)->get()->groupBy('parameter_id') as $parameterId => $rows) {
                if (isset($supported[(string) $parameterId])) continue;
                $first = $rows->first();
                $supported[(string) $parameterId] = [
                    'id' => (string) $parameterId,
                    'name' => (string) ($first->parameter_name ?? $parameterId),
                    'type' => 'dictionary',
                    'multiple_choices' => true,
                    'dictionary' => $rows->map(fn ($row): array => ['id' => (string) $row->value_id, 'label' => (string) ($row->value_label ?? $row->value_id)])->values()->all(),
                    'ui_supported' => true,
                ];
            }
        }

        return array_values($supported);
    }

    public static function normalizeDynamicAllegroParameterField(array $param): ?array
    {
        if (($param['ui_supported'] ?? false) !== true) return null;
        if (($param['type'] ?? null) !== 'dictionary') return null;

        $dictionary = (array) ($param['dictionary'] ?? $param['official_values'] ?? []);
        if ($dictionary === []) return null;

        $id = (string) ($param['id'] ?? $param['parameter_id'] ?? '');
        if ($id === '') return null;

        return [
            'id' => $id,
            'name' => (string) ($param['name'] ?? $param['parameter_name'] ?? $id),
            'type' => 'dictionary',
            'multiple_choices' => (bool) ($param['multiple_choices'] ?? false),
            'required' => (bool) ($param['required'] ?? false),
            'describes_product' => (bool) ($param['describes_product'] ?? false),
            'dictionary' => array_values(array_filter(array_map(fn (array $row): array => [
                'id' => (string) ($row['id'] ?? ''),
                'label' => (string) ($row['label'] ?? $row['value'] ?? $row['id'] ?? ''),
            ], $dictionary), fn (array $row): bool => $row['id'] !== '')),
            'saved_value_ids' => array_values((array) ($param['saved_value_ids'] ?? [])),
            'ui_supported' => true,
        ];
    }

    public static function savedAllegroManualParameterValues(?Part $record): array
    {
        if (! $record || ! $record->exists) return [];
        $categoryId = self::currentAllegroCategoryId($record);
        if ($categoryId === '') return [];
        return app(AllegroManualParameterSelectionService::class)->savedSelectionsForCategory($record, $categoryId);
    }

    public static function currentAllegroCategoryIdForDiagnostics(?Part $record): string
    {
        return self::currentAllegroCategoryId($record);
    }

    private static function currentAllegroCategoryId(?Part $record): string
    {
        if (! $record || ! $record->exists) return '';
        $resolved = app(AllegroCategoryResolver::class)->resolve($record);
        return (string) ($resolved['id'] ?? '');
    }

    public static function dynamicAllegroParameterItems(?Part $record, ?array $fields = null): array
    {
        $definitions = $fields === null
            ? self::dynamicAllegroParameterDefinitions($record)
            : array_values(array_filter(array_map(fn (array $field): ?array => self::normalizeDynamicAllegroParameterField($field), $fields)));

        $savedValues = self::savedAllegroManualParameterValues($record);

        return array_map(function (array $param) use ($savedValues): array {
            $parameterId = (string) $param['id'];
            $savedValueIds = array_values((array) ($param['saved_value_ids'] ?? $savedValues[$parameterId] ?? []));

            return [
                'parameter_id' => $parameterId,
                'parameter_name' => (string) ($param['name'] ?? $parameterId),
                'multiple_choices' => (bool) ($param['multiple_choices'] ?? false),
                'official_values' => array_values((array) ($param['dictionary'] ?? $param['official_values'] ?? [])),
                'selected_value_ids' => ($param['multiple_choices'] ?? false) ? $savedValueIds : ($savedValueIds[0] ?? null),
            ];
        }, $definitions);
    }

    public static function dynamicAllegroParameterFields(?Part $record, ?array $fields = null): array
    {
        return [self::dynamicAllegroParametersRepeater()];
    }

    public static function dynamicAllegroParametersRepeater(): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make(self::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER)
            ->hiddenLabel()
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->reorderableWithButtons(false)
            ->reorderableWithDragAndDrop(false)
            ->itemLabel(null)
            ->extraAttributes(['class' => 'gps-allegro-parameters-repeater'])
            ->dehydrated(true)
            ->default(fn (?Part $record): array => self::dynamicAllegroParameterItems($record))
            ->schema([
                Forms\Components\Hidden::make('parameter_id'),
                Forms\Components\Hidden::make('parameter_name'),
                Forms\Components\Hidden::make('multiple_choices'),
                Forms\Components\Hidden::make('official_values'),
                Forms\Components\Select::make('selected_value_ids')
                    ->key('allegro-parameter-selected-value-ids')
                    ->hiddenLabel()
                    ->placeholder(fn (Forms\Get $get): string => self::dynamicAllegroParameterPlaceholder($get))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->multiple(fn (Forms\Get $get): bool => (bool) $get('multiple_choices'))
                    ->options(fn (Forms\Get $get): array => self::dynamicAllegroParameterOptions(['official_values' => (array) ($get('official_values') ?: [])]))
                    ->dehydrated(true)
                    ->extraAttributes(fn (Forms\Get $get): array => [
                        'data-gps-allegro-dictionary-select' => (string) ($get('parameter_id') ?: ''),
                        'data-gps-allegro-dictionary-option-count' => (string) count(self::dynamicAllegroParameterOptions(['official_values' => (array) ($get('official_values') ?: [])])),
                    ])
                    ->columnSpanFull(),
            ])
            ->columnSpanFull();
    }


    private static function dynamicAllegroParameterPlaceholder(Forms\Get $get): string
    {
        $parameterName = trim((string) $get('parameter_name'));

        if ($parameterName === '' || $parameterName === 'Funkcje') {
            return 'Wybierz z listy';
        }

        return 'Wybierz: '.$parameterName;
    }

    public static function mapDynamicAllegroParameterItemsToManualValues(array $items): array
    {
        $values = [];

        foreach ($items as $item) {
            if (! is_array($item)) continue;

            $parameterId = (string) ($item['parameter_id'] ?? '');
            if ($parameterId === '') continue;

            if (! array_key_exists('selected_value_ids', $item)) {
                continue;
            }

            $selected = $item['selected_value_ids'];
            $multipleChoices = filter_var($item['multiple_choices'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $values[$parameterId] = $multipleChoices
                ? array_values(array_filter((array) $selected, fn (mixed $value): bool => filled($value)))
                : ($selected === '' ? null : $selected);
        }

        return $values;
    }

    public static function dynamicAllegroParameterOptions(array $field): array
    {
        return collect((array) ($field['official_values'] ?? $field['dictionary'] ?? []))
            ->mapWithKeys(fn ($item): array => [
                (string) ($item['id'] ?? '') => (string) ($item['label'] ?? $item['value'] ?? $item['id'] ?? ''),
            ])
            ->filter(fn (string $label, string $id): bool => $id !== '' && $label !== '')
            ->all();
    }

    public static function shouldShowAdditionalPartCodesRepeater(?Part $record): bool
    {
        if (! $record instanceof Part || ! $record->exists) {
            return false;
        }

        return self::normalizedOriginalAdditionalPartCodes($record) !== [];
    }

    /**
     * @return array<int, string>
     */
    public static function normalizedOriginalAdditionalPartCodes(?Part $record): array
    {
        if (! $record instanceof Part) {
            return [];
        }

        $codes = $record->getRawOriginal('additional_part_codes') ?? $record->additional_part_codes;

        if (is_string($codes)) {
            $decoded = json_decode($codes, true);
            $codes = is_array($decoded) ? $decoded : [];
        }

        return array_slice(array_values(array_filter(array_map(
            fn (mixed $code): string => trim((string) $code),
            (array) $codes
        ), fn (string $code): bool => $code !== '')), 0, AdditionalPartCodes::MAX_CODES);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->extraAttributes(['class' => 'gps-part-form'])
            ->schema([
                Section::make('Zdjęcie kodu części')
                    // Temporarily disabled: keep the code-photo upload configuration and stored data intact for a possible future return.
                    ->hidden()
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
                        Forms\Components\ViewField::make('part_images_gallery')
                            ->label('Zdjęcia części')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->visibleOn('view')
                            ->view('filament.resources.parts.part-images-gallery')
                            ->viewData(fn (?Part $record): array => ['part' => $record, 'editable' => false])
                            ->columnSpanFull(),
                        Forms\Components\ViewField::make('part_images_editor')
                            ->label('Zdjęcia części')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->visibleOn('edit')
                            ->view('filament.resources.parts.part-images-gallery')
                            ->viewData(fn (?Part $record): array => ['part' => $record, 'editable' => true])
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('part_photo_paths')
                            ->label('Zdjęcia części')
                            ->hiddenOn('view')
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
                    ->columns(['default' => 1, 'md' => 12])
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--part-info'])
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Tytuł produktu')
                            ->required()
                            ->maxLength(self::PART_TITLE_MAX_LENGTH)
                            ->suffix(self::partTitleCharacterCounter())
                            ->extraInputAttributes([
                                'class' => 'font-normal',
                                'maxlength' => (string) self::PART_TITLE_MAX_LENGTH,
                            ])
                            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, ?Part $record): null => self::refreshCategorySuggestion($get, $set, $record))
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('part_number')->label('Główny kod części')->maxLength(255)->live(debounce: 500)->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, ?Part $record): null => self::refreshCategorySuggestion($get, $set, $record))->columnSpanFull(),
                        Forms\Components\Repeater::make('additional_part_codes')
                            ->label('Dodatkowe kody części')
                            ->addActionLabel('+ Dodaj kod części')
                            ->maxItems(AdditionalPartCodes::MAX_CODES)
                            ->defaultItems(0)
                            ->simple(
                                Forms\Components\TextInput::make('code')
                                    ->label('Kod części')
                                    ->maxLength(AdditionalPartCodes::MAX_LENGTH)
                            )
                            ->mutateDehydratedStateUsing(fn (?array $state, Forms\Get $get): ?array => AdditionalPartCodes::normalize($state, $get('part_number')))
                            ->visible(fn (?Part $record): bool => self::shouldShowAdditionalPartCodesRepeater($record))
                            // Diagnostic marker: part_edit_additional_part_codes_conditional_visibility_v2. Do not render helper text under this repeater.
                            ->columnSpanFull(),
                        Forms\Components\Hidden::make('sku'),
                        Forms\Components\Select::make('category_id')->label('Kategoria')->placeholder('Wpisz min. 2 znaki')->required()->validationMessages(['required' => 'Kategoria jest wymagana.'])->searchable()->searchDebounce(250)->optionsLimit(30)->getSearchResultsUsing(fn (string $search): array => self::categorySearchResults($search))->getOptionLabelUsing(fn ($value): ?string => self::categoryOptionLabel($value))->native(false)->live()->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set): null => self::refreshMarketplaceMappings($get, $set))->suffixAction(self::categoryTreeAction())->columnSpanFull(),
                        Forms\Components\Select::make('condition_notes')->label('Jakość')->placeholder('Jakość')->options(['Używany' => 'Używany', 'Nowy' => 'Nowy', 'Uszkodzony' => 'Uszkodzony', 'Regenerowany' => 'Regenerowany'])->default(self::DEFAULT_CONDITION_VALUE)->native(false)->extraFieldWrapperAttributes(['class' => 'gps-part-select-with-chevron'])->columnSpan(['default' => 1, 'md' => 6]),
                        Forms\Components\Select::make('part_position')->label('Pozycja części (strona zabudowy)')->placeholder('Wybierz')->options(['Wszystkie' => 'Wszystkie', 'Lewa strona' => 'Lewa strona', 'Środek' => 'Środek', 'Prawa strona' => 'Prawa strona', 'Komplet' => 'Komplet', 'Tył strona lewa' => 'Tył strona lewa', 'Tył strona prawa' => 'Tył strona prawa', 'Przód strona lewa' => 'Przód strona lewa', 'Przód strona prawa' => 'Przód strona prawa', 'Przód' => 'Przód', 'Tył' => 'Tył'])->default(null)->native(false)->extraFieldWrapperAttributes(['class' => 'gps-part-select-with-chevron'])->columnSpan(['default' => 1, 'md' => 6]),
                        Forms\Components\Select::make(self::ADMIN_STEERING_FORM_STATE)->label('Kierownica po stronie')->placeholder('Kierownica po stronie')->options(self::ADMIN_STEERING_OPTIONS)->default(self::EXPECTED_LEFT_STEERING_VALUE)->native(false)->dehydrated(false)->extraFieldWrapperAttributes(['class' => 'gps-part-select-with-chevron'])->columnSpan(['default' => 1, 'md' => 6]),
                        Forms\Components\Select::make('storage_location_id')->label('Magazyn')->placeholder('Wpisz min. 3 znaki')->searchable()->searchDebounce(400)->getSearchResultsUsing(fn (string $search): array => self::storageLocationSearchResults($search))->getOptionLabelUsing(fn ($value): ?string => self::storageLocationOptionLabel($value))->native(false)->extraFieldWrapperAttributes(['class' => 'gps-part-select-with-chevron'])->columnSpan(['default' => 1, 'md' => 6]),
                        Forms\Components\TextInput::make('weight_kg')->label('Waga, kg')->numeric()->minValue(0)->step('0.001')->columnSpan(['default' => 1, 'md' => 3]),
                        Forms\Components\TextInput::make('length_cm')->label('Długość, cm')->numeric()->minValue(0)->step('0.01')->columnSpan(['default' => 1, 'md' => 3]),
                        Forms\Components\TextInput::make('width_cm')->label('Szerokość, cm')->numeric()->minValue(0)->step('0.01')->columnSpan(['default' => 1, 'md' => 3]),
                        Forms\Components\TextInput::make('height_cm')->label('Wysokość, cm')->numeric()->minValue(0)->step('0.01')->columnSpan(['default' => 1, 'md' => 3]),
                        Forms\Components\RichEditor::make('description')->label('Opis')->placeholder('Opis')->columnSpanFull(),
                        Forms\Components\Hidden::make('suggested_category_id'),
                        Forms\Components\Hidden::make('category_confidence'),
                        Forms\Components\Hidden::make('category_suggestion_reason'),
                        Forms\Components\Hidden::make('category_needs_review'),
                        Forms\Components\Hidden::make('category_suggestions')->dehydrated(false)->default([]),
                        Forms\Components\Hidden::make('marketplace_category_mappings_state')->dehydrated(false)->default([]),
                        Forms\Components\Hidden::make('marketplace_category_selections')->default([]),
                    ]),

                Section::make('Informacje o samochodzie')
                    ->collapsible()
                    ->columns(2)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--vehicle'])
                    ->schema([
                        Forms\Components\Hidden::make('car_id')->live(),
                        Forms\Components\Actions::make([
                            self::chooseCarAction(),
                        ])
                            ->extraAttributes(['class' => 'gps-vehicle-actions'])
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('recent_cars')
                            ->hiddenLabel()
                            ->content(fn (Forms\Get $get): HtmlString => new HtmlString(self::recentCarsHtml($get('car_id'))))
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
                        Forms\Components\TextInput::make('price')
                            ->label('Cena sklep')
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Forms\Set $set, mixed $state): void {
                                if (! is_numeric($state)) {
                                    $set('allegro_price', null);
                                    $set('ebay_price', null);

                                    return;
                                }

                                $storefrontPrice = round((float) $state, 2);

                                $set('allegro_price', number_format($storefrontPrice, 2, '.', ''));
                                $set('ebay_price', number_format(round($storefrontPrice * 1.25, 2), 2, '.', ''));
                            }),
                        Forms\Components\TextInput::make('allegro_price')
                            ->label('Cena Allegro')
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0)
                            ->readOnly(),
                        Forms\Components\TextInput::make('ovoko_price')
                            ->label('Cena Ovoko')
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0),
                        Forms\Components\TextInput::make('ebay_price')
                            ->label('Cena eBay')
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0)
                            ->readOnly(),
                        Forms\Components\Hidden::make('currency')->default('PLN'),
                        Forms\Components\Placeholder::make('marketplace_price_links')
                            ->hiddenLabel()
                            ->content(fn (Forms\Get $get): HtmlString => new HtmlString(self::marketplacePriceLinksHtml($get)))
                            ->columnSpanFull(),
                    ]),

                Section::make('Kurier Allegro')
                    ->collapsible()
                    ->columns(1)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--allegro-courier'])
                    ->schema([
                        Forms\Components\Select::make('allegro_shipping_rate_name')
                            ->hiddenLabel()
                            ->placeholder('Wybierz cennik dostawy Allegro')
                            ->options(AllegroSalesSettingsResolver::SHIPPING_RATE_OPTIONS)
                            ->native(true)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Hidden::make(self::ALLEGRO_DYNAMIC_PARAMETER_FIELDS)
                    ->dehydrated(false)
                    ->default(fn (?Part $record): array => self::dynamicAllegroParameterDefinitions($record)),

                Section::make('Parametry Allegro')
                    ->collapsible()
                    ->columns(1)
                    ->hidden(fn (Forms\Get $get): bool => count((array) ($get(self::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER) ?: [])) === 0)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--allegro-parameters'])
                    ->schema([self::dynamicAllegroParametersRepeater()]),

                Section::make('Kanały sprzedaży')
                    ->collapsible()
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--marketplace-preparation'])
                    ->schema([
                        Forms\Components\ViewField::make('marketplace_readiness_cards')
                            ->label('Status gotowości')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->visible(fn (?Part $record): bool => $record !== null && $record->exists)
                            ->view('filament.resources.parts.marketplace-readiness-cards')
                            ->viewData(fn (?Part $record, Forms\Get $get): array => ['part' => $record, 'categoryId' => $get('category_id'), 'marketplaceCategorySelections' => (array) ($get('marketplace_category_selections') ?: [])])
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('marketplace_readiness_empty')
                            ->hiddenLabel()
                            ->content('Zapisz część, aby zobaczyć podgląd gotowości Allegro / Ovoko / eBay.')
                            ->visible(fn (?Part $record): bool => $record === null || ! $record->exists)
                            ->columnSpanFull(),
                    ]),


            ]);
    }



    public static function isMissingAdminDefaultValue(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    public static function defaultConditionValue(mixed $value): string
    {
        return self::isMissingAdminDefaultValue($value) ? self::DEFAULT_CONDITION_VALUE : (string) $value;
    }


    public static function partPositionFormValue(?Part $record): ?string
    {
        foreach (['review_metadata.part_position', 'legacy_payload.part_position', 'legacy_payload.position'] as $field) {
            $value = data_get($record, $field);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    public static function applyPartPositionFormStateToData(array $data, ?Part $record = null): array
    {
        $selectedPartPosition = $data['part_position'] ?? null;
        unset($data['part_position']);

        $reviewMetadata = is_array($record?->review_metadata ?? null) ? $record->review_metadata : [];

        if (filled($selectedPartPosition)) {
            $reviewMetadata['part_position'] = (string) $selectedPartPosition;
        } else {
            unset($reviewMetadata['part_position']);
        }

        $data['review_metadata'] = $reviewMetadata === [] ? null : $reviewMetadata;

        return $data;
    }

    public static function applyAdminSteeringFormStateToData(array $data, ?Part $record = null): array
    {
        $selectedSteeringSide = $data[self::ADMIN_STEERING_FORM_STATE] ?? null;
        unset($data[self::ADMIN_STEERING_FORM_STATE]);

        $vehicleSnapshot = is_array($record?->vehicle_snapshot ?? null) ? $record->vehicle_snapshot : [];

        if (filled($selectedSteeringSide)) {
            $vehicleSnapshot['steering_side'] = (string) $selectedSteeringSide;
            $data['vehicle_snapshot'] = $vehicleSnapshot;
        } elseif ($record === null) {
            $data['vehicle_snapshot'] = array_replace($vehicleSnapshot, ['steering_side' => self::EXPECTED_LEFT_STEERING_VALUE]);
        }

        return $data;
    }

    public static function adminSteeringOptions(): array
    {
        return self::ADMIN_STEERING_OPTIONS;
    }

    public static function adminSteeringFieldPath(): string
    {
        return self::ADMIN_STEERING_FIELD_PATH;
    }

    public static function expectedLeftSteeringValue(): string
    {
        return self::EXPECTED_LEFT_STEERING_VALUE;
    }

    public static function adminSteeringFormValue(mixed $value): ?string
    {
        $normalized = mb_strtolower(trim((string) $value));

        return match ($normalized) {
            'po lewej', 'lewa strona' => self::EXPECTED_LEFT_STEERING_VALUE,
            'po prawej', 'prawa strona' => self::EXPECTED_RIGHT_STEERING_VALUE,
            default => null,
        };
    }

    public static function isAdminSteeringVisible(mixed $value): bool
    {
        return self::adminSteeringFormValue($value) !== null;
    }


    private static function storageLocationSearchResults(string $search): array
    {
        if (mb_strlen(trim($search)) < 3) {
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
        return $location->name;
    }


    public static function refreshCategorySuggestion(Forms\Get $get, Forms\Set $set, ?Part $record = null): null
    {
        $result = app(PartCategorySuggestionService::class)->suggestCategoryFromTitle((string) $get('name'), $record?->id);
        $top = $result['suggestions'][0] ?? null;

        $set('category_suggestions', $result['suggestions'] ?? []);

        if (! $top) {
            return null;
        }

        $set('suggested_category_id', $top['category_id']);
        $set('category_confidence', min(100, (int) round(((float) $top['score']) * 5)));
        $set('category_suggestion_reason', 'Sugestia z podobnych części: '.$top['matched_parts_count'].' dopasowań.');
        $set('category_needs_review', ! (bool) $result['auto_select']);

        if (! $get('category_id') && $result['auto_select']) {
            $set('category_id', $result['selected_category_id']);
            $set('marketplace_category_mappings_state', $result['marketplace_mappings'] ?? []);
        }

        return null;
    }

    public static function refreshMarketplaceMappings(Forms\Get $get, Forms\Set $set): null
    {
        $categoryId = $get('category_id');
        $set('marketplace_category_mappings_state', filled($categoryId) ? app(PartCategorySuggestionService::class)->marketplaceMappingsForCategory((int) $categoryId) : []);

        return null;
    }

    public static function marketplaceMappingStatusHtml(array $state): string
    {
        if ($state === []) {
            return '<span class="text-gray-500">Brak wybranej kategorii albo brak odczytanych mapowań.</span>';
        }

        return collect($state)->map(function (array $mapping): string {
            $label = e($mapping['label'] ?? 'Marketplace');
            if (($mapping['status'] ?? null) !== 'mapped') {
                return '<div>⚠️ '.$label.': brak mapowania dla finalnej kategorii.</div>';
            }

            $external = e(trim((string) ($mapping['external_category_path'] ?? $mapping['external_category_name'] ?? $mapping['external_category_id'] ?? '')));

            return '<div>✅ '.$label.': '.$external.'</div>';
        })->implode('');
    }


    public static function chooseCarAction(): Action
    {
        return Action::make('chooseCar')
            ->label(fn (): string => 'Moje samochody ('.Car::query()->count().')')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->extraAttributes(['class' => 'gps-choose-car-action'])
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
                    ->searchDebounce(250)
                    ->optionsLimit(30)
                    ->native(false)
                    ->allowHtml()
                    ->getSearchResultsUsing(fn (string $search): array => self::carPickerOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => self::carPickerOptionLabel($value))
                    ->extraAttributes(['class' => 'gps-vehicle-picker-select'])
                    ->helperText('Wpisz kilka słów, np. „bmw x4” albo „x4 f26”. Wyniki są ograniczone do 30 samochodów.')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, Forms\Set $set): void {
                self::selectCar($set, $data['selected_car_id'] ?? null);
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

    public static function selectCar(Forms\Set $set, mixed $carId): bool
    {
        if (blank($carId)) {
            return false;
        }

        $car = self::carPickerBaseQuery()->find($carId);

        if (! $car) {
            return false;
        }

        $set('car_id', $car->getKey());

        return true;
    }

    public static function selectCarInFormData(array &$data, mixed $carId): bool
    {
        if (blank($carId)) {
            return false;
        }

        $car = self::carPickerBaseQuery()->find($carId);

        if (! $car) {
            return false;
        }

        $data['car_id'] = $car->getKey();

        return true;
    }

    public static function recentCarsHtml(mixed $selectedCarId): string
    {
        if (filled($selectedCarId)) {
            return '';
        }

        $cars = self::carPickerBaseQuery()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(4)
            ->get();

        if ($cars->isEmpty()) {
            return '<div class="gps-recent-vehicles gps-recent-vehicles--empty">Brak ostatnio dodanych samochodów.</div>';
        }

        $tiles = $cars->map(fn (Car $car): string => self::recentCarTileHtml($car, (int) $selectedCarId === $car->getKey()))->implode('');

        return '<div class="gps-recent-vehicles">'.$tiles.'</div>';
    }

    private static function recentCarTileHtml(Car $car, bool $selected): string
    {
        $title = e(trim(implode(' ', array_filter([$car->make, $car->model]))));
        $title = $title !== '' ? $title : e(self::carLabel($car));
        $year = filled($car->production_year ?? null) ? e((string) $car->production_year) : null;
        $details = self::recentCarTileDetails($car);
        $classes = 'gps-recent-vehicle'.($selected ? ' gps-recent-vehicle--selected' : '');

        return '<button type="button" class="'.$classes.'" wire:click="setPartCarFromPicker('.$car->getKey().')">'
            .'<span class="gps-recent-vehicle__topline"><span class="gps-recent-vehicle__icon">🚗</span><span class="gps-recent-vehicle__title">'.$title.'</span></span>'
            .($year !== null ? '<span class="gps-recent-vehicle__year">Rok '.$year.'</span>' : '')
            .($details !== [] ? '<span class="gps-recent-vehicle__details">'.e(implode(' · ', $details)).'</span>' : '')
            .'</button>';
    }

    /**
     * @return array<int, string>
     */
    private static function recentCarTileDetails(Car $car): array
    {
        return collect([
            $car->body_type,
            $car->fuel_type,
            $car->color,
            $car->steering_side,
            $car->gearbox_type,
            filled($car->engine_code) ? 'Kod '.$car->engine_code : null,
            filled($car->engine_power_kw) ? $car->engine_power_kw.' kW' : null,
            filled($car->engine_capacity_cm3) ? $car->engine_capacity_cm3.' cm³' : null,
        ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->take(7)
            ->values()
            ->all();
    }

    private static function carPickerBaseQuery(): Builder
    {
        return Car::query()->select(self::carPickerColumns());
    }

    /**
     * @return array<int, string>
     */
    private static function carPickerColumns(): array
    {
        return [
            'id',
            'external_id',
            'vin',
            'make',
            'model',
            'model_variant',
            'production_year',
            'first_registration_year',
            'registration_number',
            'fuel_type',
            'engine_power_kw',
            'engine_capacity_cm3',
            'engine_code',
            'drivetrain',
            'gearbox_type',
            'gearbox_code',
            'body_type',
            'color',
            'steering_side',
            'created_at',
        ];
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

        if (mb_strlen($search) < 2) {
            return [];
        }

        $like = self::escapedLike($search);
        $prefix = $like.'%';
        $partial = '%'.$like.'%';

        return self::carPickerBaseQuery()
            ->where(fn (Builder $query): Builder => self::applyCarPickerSearch($query, $search))
            ->orderByRaw(
                self::carPickerPrioritySql(),
                [
                    $search,
                    $search,
                    $search,
                    $search,
                    $prefix,
                    $prefix,
                    $prefix,
                    $prefix,
                    $prefix,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                    $partial,
                ],
            )
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

        $car = self::carPickerBaseQuery()->find($value);

        return $car ? self::carPickerOptionHtml($car) : null;
    }

    public static function categorySearchResults(string $search): array
    {
        $search = trim($search);

        if (mb_strlen($search) < 2) {
            return [];
        }

        $like = self::escapedLike($search);
        $prefix = $like.'%';
        $partial = '%'.$like.'%';

        return PartCategory::query()
            ->select(['id', 'parent_id', 'name', 'category_path', 'full_slug_path'])
            ->where(function (Builder $query) use ($prefix, $partial): void {
                $query
                    ->where('name', 'like', $partial)
                    ->orWhere('category_path', 'like', $partial)
                    ->orWhere('full_slug_path', 'like', $partial)
                    ->orWhere('slug', 'like', $prefix);
            })
            ->orderByRaw(
                'CASE
                    WHEN name = ? THEN 0
                    WHEN name LIKE ? ESCAPE \'\\\\\' THEN 1
                    WHEN category_path LIKE ? ESCAPE \'\\\\\' THEN 2
                    WHEN full_slug_path LIKE ? ESCAPE \'\\\\\' THEN 3
                    WHEN name LIKE ? ESCAPE \'\\\\\' THEN 4
                    ELSE 5
                END',
                [$search, $prefix, $prefix, $prefix, $partial],
            )
            ->orderBy('name')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (PartCategory $category): array => [$category->id => self::categorySearchLabel($category)])
            ->all();
    }

    public static function categoryOptionLabel($value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $category = PartCategory::query()
            ->select(['id', 'parent_id', 'name', 'category_path', 'full_slug_path'])
            ->find($value);

        return $category ? self::categorySearchLabel($category) : null;
    }

    private static function categorySearchLabel(PartCategory $category): string
    {
        return trim((string) $category->name);
    }

    private static function applyCarPickerSearch(Builder $query, string $search): Builder
    {
        $tokens = collect(preg_split('/\s+/', trim($search)) ?: [])->filter()->values();

        foreach ($tokens as $token) {
            $partial = '%'.self::escapedLike((string) $token).'%';

            $query->where(function (Builder $query) use ($partial): void {
                foreach (self::carPickerSearchFields() as $field) {
                    $query->orWhere($field, 'like', $partial);
                }
            });
        }

        return $query;
    }

    private static function carPickerPrioritySql(): string
    {
        return 'CASE
            WHEN vin = ? THEN 0
            WHEN registration_number = ? THEN 0
            WHEN external_id = ? THEN 0
            WHEN model_variant = ? THEN 0
            WHEN make LIKE ? ESCAPE \'\\\\\' THEN 1
            WHEN model LIKE ? ESCAPE \'\\\\\' THEN 1
            WHEN engine_code LIKE ? ESCAPE \'\\\\\' THEN 2
            WHEN vin LIKE ? ESCAPE \'\\\\\' THEN 2
            WHEN registration_number LIKE ? ESCAPE \'\\\\\' THEN 2
            WHEN make LIKE ? ESCAPE \'\\\\\' THEN 3
            WHEN model LIKE ? ESCAPE \'\\\\\' THEN 3
            WHEN production_year LIKE ? ESCAPE \'\\\\\' THEN 4
            WHEN first_registration_year LIKE ? ESCAPE \'\\\\\' THEN 4
            WHEN engine_code LIKE ? ESCAPE \'\\\\\' THEN 4
            WHEN engine_capacity_cm3 LIKE ? ESCAPE \'\\\\\' THEN 4
            WHEN engine_power_kw LIKE ? ESCAPE \'\\\\\' THEN 4
            WHEN model_variant LIKE ? ESCAPE \'\\\\\' THEN 4
            WHEN vin LIKE ? ESCAPE \'\\\\\' THEN 4
            WHEN registration_number LIKE ? ESCAPE \'\\\\\' THEN 4
            WHEN fuel_type LIKE ? ESCAPE \'\\\\\' THEN 5
            WHEN body_type LIKE ? ESCAPE \'\\\\\' THEN 5
            WHEN drivetrain LIKE ? ESCAPE \'\\\\\' THEN 5
            WHEN gearbox_type LIKE ? ESCAPE \'\\\\\' THEN 5
            ELSE 6
        END';
    }

    private static function carPickerSearchFields(): array
    {
        return [
            'make',
            'model',
            'model_variant',
            'engine_code',
            'vin',
            'registration_number',
            'external_id',
            'production_year',
            'first_registration_year',
            'fuel_type',
            'body_type',
            'color',
            'drivetrain',
            'steering_side',
            'gearbox_type',
            'gearbox_code',
            'engine_power_kw',
            'engine_capacity_cm3',
        ];
    }

    private static function escapedLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }

    public static function carPickerOptionHtml(Car $car): string
    {
        $details = self::carDetails($car);

        return '<div class="gps-vehicle-option"><span class="gps-vehicle-option__icon">🚗</span><span><strong>'.e(self::carLabel($car)).'</strong>'.($details ? '<small>'.e(implode(' · ', $details)).'</small>' : '').'</span></div>';
    }

    public static function partTitleCharacterCounter(): HtmlString
    {
        $limit = self::PART_TITLE_MAX_LENGTH;

        $html = <<<'HTML'
            <span
                class="text-xs font-normal tabular-nums whitespace-nowrap text-gray-400 dark:text-gray-500"
                x-data="{
                    limit: __LIMIT__,
                    update() {
                        const input = this.$el.closest('.fi-input-wrp')?.querySelector('input');
                        const currentLength = input?.value?.length ?? 0;

                        this.$el.textContent = `${currentLength}/${this.limit}`;
                        this.$el.classList.toggle('text-amber-600', currentLength > this.limit);
                        this.$el.classList.toggle('dark:text-amber-400', currentLength > this.limit);
                        this.$el.classList.toggle('text-gray-400', currentLength <= this.limit);
                        this.$el.classList.toggle('dark:text-gray-500', currentLength <= this.limit);
                    },
                }"
                x-init="
                    update();
                    const input = $el.closest('.fi-input-wrp')?.querySelector('input');
                    input?.addEventListener('input', () => update());
                "
            >0/__LIMIT__</span>
        HTML;

        return new HtmlString(str_replace('__LIMIT__', (string) $limit, $html));
    }

    public static function categoryTreeAction(): Action
    {
        return Action::make('chooseCategoryFromTree')
            ->label(fn (Forms\Get $get): string => count((array) ($get('category_suggestions') ?? [])) > 0 ? (string) count((array) ($get('category_suggestions') ?? [])) : '')
            ->icon('heroicon-m-bars-3')
            ->tooltip('Wybierz kategorię z drzewa')
            ->modalHeading('Kategorie')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Zamknij')
            ->extraModalWindowAttributes(['class' => 'gps-category-picker-modal'])
            ->slideOver()
            ->form([
                Forms\Components\ViewField::make('category_picker')
                    ->hiddenLabel()
                    ->dehydrated(false)
                    ->view('filament.forms.category-picker')
                    ->viewData(fn (Forms\Get $get): array => [
                        'categories' => [],
                        'lazyChildrenUrl' => route('tools.part-category-children', ['token' => 'gps_images_import_2026']),
                        'lazyLoadOnInit' => true,
                        'suggestions' => array_values((array) ($get('category_suggestions') ?? [])),
                    ]),
            ]);
    }

    public static function categoryPickerCategories(): array
    {
        $categories = PartCategory::query()
            ->select(['id', 'parent_id', 'name', 'full_slug_path', 'sort_order', 'woo_product_count'])
            ->where(function (Builder $query): void {
                $query->whereNull('name')
                    ->orWhereRaw('LOWER(TRIM(name)) <> ?', ['bez kategorii']);
            })
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


    /**
     * @return array<int, string>
     */
    public static function partImagePaths(Part $part): array
    {
        $images = $part->relationLoaded('images') ? $part->images : $part->images()->get();

        return $images
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->pluck('path')
            ->filter()
            ->values()
            ->all();
    }

    public static function publicProductUrl(Part $part, bool $absolute = true): ?string
    {
        if (! $part->storefrontVisible()->whereKey($part->getKey())->exists()) {
            return null;
        }

        $slug = filled($part->slug) ? $part->slug : $part->getKey();

        return route('storefront.product', $slug, absolute: $absolute);
    }

    public static function syncPartImages(Part $part, mixed $paths): void
    {
        app(PartImageUploadService::class)->syncStoredImages($part, $paths);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'images:id,part_id,path,sort_order,is_primary',
                'marketplaceListings:id,part_id,marketplace,external_offer_id,external_listing_id,price,currency,status,sync_status,match_status,last_error,url,last_api_status,last_seen_at,not_seen_in_active_api_at',
                'storageLocation:id,name,description',
                'category:id,name',
                'car:id,make,model,model_variant,production_year,first_registration_year',
            ]))
            ->columns([
            Tables\Columns\ViewColumn::make('admin_part_image')->label('Zdjęcie')->view('filament.resources.parts.table-image')->viewData(fn (Part $record): array => ['part' => $record])->extraHeaderAttributes(['class' => 'gps-col-image'])->extraCellAttributes(['class' => 'gps-col-image'])->extraAttributes(['class' => 'gps-col-image-content']),
            Tables\Columns\ViewColumn::make('id')->label('ID')->view('filament.resources.parts.table-id')->viewData(fn (Part $record): array => ['part' => $record])->sortable()->searchable()->extraHeaderAttributes(['class' => 'gps-col-id'])->extraCellAttributes(['class' => 'gps-col-id'])->extraAttributes(['class' => 'gps-col-id-content']),
            Tables\Columns\ViewColumn::make('admin_part_title')->label('Nazwa części')->view('filament.resources.parts.table-title')->viewData(fn (Part $record): array => ['part' => $record])->searchable(['name', 'sku'])->extraHeaderAttributes(['class' => 'gps-col-title'])->extraCellAttributes(['class' => 'gps-col-title'])->extraAttributes(['class' => 'gps-col-title-content']),
            Tables\Columns\ViewColumn::make('admin_part_channels')->label('Kanały sprzedaży')->view('filament.resources.parts.table-channels')->viewData(fn (Part $record): array => ['part' => $record])->extraHeaderAttributes(['class' => 'gps-col-channels'])->extraCellAttributes(['class' => 'gps-col-channels'])->extraAttributes(['class' => 'gps-col-channels-content']),
            Tables\Columns\ViewColumn::make('admin_part_manual_marketplace_links')->label('Mapowanie')->view('filament.resources.parts.table-manual-marketplace-links')->viewData(fn (Part $record): array => ['part' => $record])->extraHeaderAttributes(['class' => 'gps-col-manual-marketplace-links'])->extraCellAttributes(['class' => 'gps-col-manual-marketplace-links'])->extraAttributes(['class' => 'gps-col-manual-marketplace-links-content']),
            Tables\Columns\ViewColumn::make('admin_part_numbers')->label('Numer części')->view('filament.resources.parts.table-numbers')->viewData(fn (Part $record): array => ['part' => $record])->searchable(['part_number', 'oem_number', 'manufacturer_code'])->extraHeaderAttributes(['class' => 'gps-col-number'])->extraCellAttributes(['class' => 'gps-col-number'])->extraAttributes(['class' => 'gps-col-number-content']),
            Tables\Columns\TextColumn::make('quantity')->label('Ilość')->sortable(),
            Tables\Columns\TextColumn::make('status')->label('Status')->formatStateUsing(fn (?string $state) => Part::statusOptions()[$state] ?? $state)->color(fn (?string $state): string => Part::statusColor($state))->size('sm')->sortable()->extraHeaderAttributes(['class' => 'gps-col-status'])->extraCellAttributes(['class' => 'gps-col-status'])->extraAttributes(['class' => 'gps-col-status-content']),
            Tables\Columns\TextColumn::make('review_reason')->label('Powód wyjaśnienia')->toggleable(),
            Tables\Columns\TextColumn::make('review_detected_at')->label('Wykryto')->dateTime('Y-m-d H:i')->sortable()->toggleable(),
            Tables\Columns\TextColumn::make('review_source')->label('Źródło')->toggleable(),
            Tables\Columns\TextColumn::make('ovoko_listing_id')->label('Ovoko ID')->state(fn (Part $record): string => (string) ($record->marketplaceListings->firstWhere('marketplace', 'ovoko')?->external_offer_id ?? $record->marketplaceListings->firstWhere('marketplace', 'ovoko')?->external_listing_id ?? '—'))->toggleable(),
            Tables\Columns\TextColumn::make('category.name')->label('Kategoria')->searchable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('car_context')->label('Samochód')->state(fn (Part $record) => $record->car ? self::carLabel($record->car) : '—')->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('car', fn (Builder $q) => $q->where('make','like',"%{$search}%")->orWhere('model','like',"%{$search}%")))->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('created_at')->label('Utworzono')->dateTime('Y-m-d H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('updated_at')->label('Zaktualizowano')->dateTime('Y-m-d H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->label('Status')->options(Part::statusOptions()),
            Tables\Filters\SelectFilter::make('category_id')->label('Kategoria')->relationship('category', 'name'),
            Tables\Filters\TernaryFilter::make('category_needs_review')->label('Kategoria wymaga sprawdzenia'),
            Tables\Filters\TernaryFilter::make('is_visible_storefront')->label('Widoczna w sklepie'),
            Tables\Filters\TernaryFilter::make('needs_listing')->label('Część do wystawienia'),
            Tables\Filters\TernaryFilter::make('needs_review')->label('Do wyjaśnienia'),
            Tables\Filters\TernaryFilter::make('missing_images')->label('Brak zdjęć')->queries(true: fn (Builder $query): Builder => $query->doesntHave('images'), false: fn (Builder $query): Builder => $query->has('images')),
            Tables\Filters\TernaryFilter::make('missing_price')->label('Brak ceny')->queries(true: fn (Builder $query): Builder => $query->whereNull('price')->orWhere('price', '<=', 0), false: fn (Builder $query): Builder => $query->whereNotNull('price')->where('price', '>', 0)),
            Tables\Filters\TernaryFilter::make('missing_sku')->label('Brak SKU')->queries(true: fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->whereNull('sku')->orWhere('sku', '')), false: fn (Builder $query): Builder => $query->whereNotNull('sku')->where('sku', '<>', '')),
            Tables\Filters\TernaryFilter::make('missing_part_number')->label('Brak numeru części')->queries(true: fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->whereNull('part_number')->orWhere('part_number', '')), false: fn (Builder $query): Builder => $query->whereNotNull('part_number')->where('part_number', '<>', '')),
            Tables\Filters\Filter::make('created_at')->label('Data dodania')->form([Grid::make(2)->schema([Forms\Components\DatePicker::make('from')->label('Od'), Forms\Components\DatePicker::make('until')->label('Do')])])->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['from'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '>=', $data['from']))->when(filled($data['until'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '<=', $data['until']))),
            Tables\Filters\SelectFilter::make('car_id')->label('Samochód')->options(fn () => Car::query()->get()->mapWithKeys(fn (Car $car) => [$car->id => self::carLabel($car)])->all()),
            Tables\Filters\SelectFilter::make('storage_location_id')->label('Miejsce składowania')->relationship('storageLocation', 'name'),
            Tables\Filters\Filter::make('condition_notes')->label('Stan / uwagi')->form([Forms\Components\TextInput::make('value')->label('Stan / uwagi')])->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('condition_notes', 'like', '%'.$data['value'].'%') : $query),
            self::rangeFilter('price', 'Cena'), self::rangeFilter('allegro_price', 'Cena Allegro'), self::rangeFilter('ebay_price', 'Cena eBay'),
            Tables\Filters\Filter::make('created_by')->label('Utworzył')->form([Forms\Components\TextInput::make('value')->label('Utworzył')])->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->whereHas('createdBy', fn (Builder $q) => $q->where('name', 'like', '%'.$data['value'].'%')->orWhere('email', 'like', '%'.$data['value'].'%')) : $query),
        ])->filtersFormColumns(3)->actions([
            Tables\Actions\EditAction::make()
                ->label('Edytuj')
                ->url(fn (Part $record): string => static::getUrl('edit', ['record' => $record])),
            Tables\Actions\ViewAction::make()
                ->label('Podgląd')
                ->color('gray')
                ->url(fn (Part $record): string => static::getUrl('view', ['record' => $record])),
            Tables\Actions\Action::make('mark_listing_ready')
                ->label('Zapisz i wystaw')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (Part $record): bool => (bool) $record->needs_listing)
                ->action(function (Part $record, PreparePartMarketplaceListingService $prepareService): void {
                    if ($prepareService->localPublishBlockers($record) !== []) {
                        return;
                    }

                    $prepareService->preview($record, dryRun: true);
                    $prepareService->markLocallyListed($record);
                }),
        ])->defaultSort('id', 'desc');
    }

    public static function rangeFilter(string $field, string $label): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make($field)->label($label)->form([Grid::make(2)->schema([Forms\Components\TextInput::make('from')->label('Od')->numeric(), Forms\Components\TextInput::make('until')->label('Do')->numeric()])])->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['from'] ?? null), fn (Builder $q) => $q->where($field, '>=', $data['from']))->when(filled($data['until'] ?? null), fn (Builder $q) => $q->where($field, '<=', $data['until'])));
    }

    public static function carLabel(Car $car): string
    {
        $name = trim(implode(' ', array_filter([$car->make, $car->model])));
        $year = $car->production_year ?: $car->first_registration_year;

        return trim($name.($year ? ' ('.$year.')' : '')) ?: '#'.$car->id;
    }

    public static function carDetails(Car $car): array
    {
        return array_values(array_filter([
            $car->production_year ? 'rok '.$car->production_year : null,
            $car->body_type,
            $car->fuel_type,
            $car->engine_code ? 'kod '.$car->engine_code : null,
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
            $name = trim(implode(' ', array_filter([$snapshot['make'] ?? null, $snapshot['model'] ?? null])));

            return '<div class="gps-selected-vehicle"><span>Wybrano: '.e($name ?: 'samochód z zapisanej migawki').'</span></div>';
        }

        return '<span class="gps-selected-vehicle gps-selected-vehicle--empty">Nie wybrano samochodu.</span>';
    }

    public static function marketplacePriceLinksHtml(Forms\Get $get): string
    {
        $query = trim((string) ($get('part_number') ?: $get('name') ?: ''));

        return view('filament.resources.parts.marketplace-price-links', [
            'query' => $query,
            'links' => self::marketplacePriceSearchLinks($query),
        ])->render();
    }

    public static function marketplacePriceSearchLinks(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $encoded = rawurlencode($query);

        return [
            'allegro' => 'https://allegro.pl/listing?string='.$encoded,
            'ovoko' => 'https://ovoko.pl/szukaj?q='.$encoded,
            'ebay' => 'https://www.ebay.com/sch/i.html?_nkw='.$encoded,
        ];
    }

    public static function adminAllPartsQuery(): Builder
    {
        return Part::query()->where('needs_listing', false)->where(fn (Builder $query) => $query->where('needs_review', false)->orWhereNull('needs_review'));
    }

    public static function getAllPartsNavigationCount(): int
    {
        if (! Schema::hasTable('parts')) {
            return 0;
        }

        return static::adminAllPartsQuery()->count();
    }

    public static function getPartsNeedsReviewNavigationCount(): int
    {
        if (! Schema::hasTable('parts')) {
            return 0;
        }

        return Part::query()->where('needs_review', true)->count();
    }

    public static function adminPartsToListQuery(): Builder
    {
        return Part::query()->where('needs_listing', true);
    }

    public static function getPartsToListNavigationCount(): int
    {
        if (! Schema::hasTable('parts')) {
            return 0;
        }

        return static::adminPartsToListQuery()->count();
    }

    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make(static::navigationLabelWithCount('Części', static::getAllPartsNavigationCount()))->group(static::getNavigationGroup())->sort(static::getNavigationSort())->url(static::getUrl('index'))->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.parts.index')),
            NavigationItem::make(static::navigationLabelWithCount('Do wystawienia', static::getPartsToListNavigationCount()))->group(static::getNavigationGroup())->sort((static::getNavigationSort() ?? 20) + 1)->url(static::getUrl('to-list'))->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.parts.to-list')),
            NavigationItem::make('Dodaj część')->group(static::getNavigationGroup())->sort((static::getNavigationSort() ?? 20) + 2)->url(static::getUrl('create'))->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.parts.create')),
            NavigationItem::make('Sprzedane części')->group(static::getNavigationGroup())->sort((static::getNavigationSort() ?? 20) + 3)->url(static::getUrl('sold'))->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.parts.sold')),
        ];
    }

    private static function navigationLabelWithCount(string $label, int $count): string
    {
        return sprintf('%s (%d)', $label, $count);
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
    public static function getPages(): array { return ['index' => Pages\ListParts::route('/'), 'create' => Pages\CreatePart::route('/create'), 'to-list' => Pages\PartsToList::route('/to-list'), 'sold' => Pages\SoldParts::route('/sold'), 'needs-review' => Pages\PartsNeedsReview::route('/needs-review'), 'view' => Pages\ViewPart::route('/{record}'), 'edit' => Pages\EditPart::route('/{record}/edit')]; }
}
