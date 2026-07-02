<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JarekGearboxResource\Pages;
use App\Models\JarekGearbox;
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

class JarekGearboxResource extends Resource
{
    protected static ?string $model = JarekGearbox::class;
    protected static ?string $navigationGroup = 'Części';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Skrzynie Jarka';
    protected static ?string $modelLabel = 'Skrzynia Jarka';
    protected static ?string $pluralModelLabel = 'Skrzynie Jarka';
    protected static ?int $navigationSort = 24;

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->extraAttributes(['class' => 'gps-part-form gps-jarek-gearbox-form'])
            ->schema([
                Section::make('Zdjęcia skrzyni')
                    ->collapsible()
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--photos'])
                    ->schema([
                        Forms\Components\Placeholder::make('images_preview')
                            ->hiddenLabel()
                            ->content(fn (?JarekGearbox $record): HtmlString => new HtmlString(self::imagesPreviewHtml($record)))
                            ->columnSpanFull(),
                    ]),

                Section::make('Informacje o skrzyni')
                    ->collapsible()
                    ->columns(12)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--part-info'])
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Tytuł produktu')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->label('Opis')
                            ->rows(8)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('plain_description')
                            ->label('Opis prosty')
                            ->rows(5)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('category_name')
                            ->label('Kategoria Allegro')
                            ->maxLength(255)
                            ->columnSpan(6),
                        Forms\Components\TextInput::make('category_id')
                            ->label('ID kategorii Allegro')
                            ->maxLength(255)
                            ->columnSpan(6),
                    ]),

                Section::make('Ceny i ilość')
                    ->collapsible()
                    ->columns(4)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--prices'])
                    ->schema([
                        Forms\Components\TextInput::make('price')
                            ->label('Cena')
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0),
                        Forms\Components\TextInput::make('currency')
                            ->label('Waluta')
                            ->maxLength(3)
                            ->default('PLN'),
                        Forms\Components\TextInput::make('quantity')
                            ->label('Ilość')
                            ->numeric()
                            ->minValue(0),
                    ]),

                Section::make('Kanały sprzedaży')
                    ->collapsible()
                    ->columns(2)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--marketplace-preparation'])
                    ->schema([
                        Forms\Components\Select::make('allegro_status')
                            ->label('Status Allegro')
                            ->options(fn (?JarekGearbox $record): array => self::statusOptions('allegro_status', $record?->allegro_status))
                            ->native(false),
                        Forms\Components\Select::make('ebay_status')
                            ->label('Status eBay')
                            ->options(self::ebayStatusOptions())
                            ->default('not_ready')
                            ->native(false),
                        Forms\Components\TextInput::make('ebay_listing_id')->label('eBay listing ID')->maxLength(255),
                        Forms\Components\TextInput::make('ebay_offer_id')->label('eBay offer ID')->maxLength(255),
                        Forms\Components\TextInput::make('ebay_inventory_sku')->label('eBay SKU')->maxLength(255),
                        Forms\Components\Placeholder::make('ebay_preview')
                            ->label('eBay preview/dry-run')
                            ->content(fn (?JarekGearbox $record): HtmlString => new HtmlString($record ? '<a class="text-primary-600 underline" href="'.e(route('admin.tools.jarek-gearboxes.ebay-preview', $record)).'" target="_blank" rel="noopener">Otwórz podgląd eBay</a>' : 'Zapisz rekord, aby zobaczyć URL preview.')),
                    ]),

                Section::make('Źródło importu')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->extraAttributes(['class' => 'gps-part-form-section gps-part-form-section--source'])
                    ->schema([
                        Forms\Components\TextInput::make('source_account')->label('Konto źródłowe')->disabled()->dehydrated(false),
                        Forms\Components\TextInput::make('allegro_account')->label('Konto Allegro')->disabled()->dehydrated(false),
                        Forms\Components\TextInput::make('allegro_offer_id')->label('ID oferty Allegro')->disabled()->dehydrated(false),
                        Forms\Components\TextInput::make('allegro_offer_url')->label('URL oferty Allegro')->disabled()->dehydrated(false)->columnSpanFull(),
                        Forms\Components\Placeholder::make('import_dates')
                            ->label('Daty')
                            ->content(fn (?JarekGearbox $record): string => $record ? 'Import: '.($record->imported_at?->format('Y-m-d H:i') ?? '—').' · Aktualizacja Allegro: '.($record->updated_from_allegro_at?->format('Y-m-d H:i') ?? '—') : '—')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('ebay_payload_snapshot')
                            ->label('eBay payload snapshot')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('main_image_url')->label('Zdjęcie')->size(56)->extraHeaderAttributes(['class' => 'gps-col-image'])->extraCellAttributes(['class' => 'gps-col-image']),
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable()->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('title')->label('Nazwa części')->searchable()->wrap()->limit(80)->description(fn (JarekGearbox $record): string => trim(($record->category_name ?: 'Brak kategorii').' · Allegro #'.($record->allegro_offer_id ?: '—'))),
                Tables\Columns\TextColumn::make('price')->label('Cena')->money('PLN')->sortable(),
                Tables\Columns\TextColumn::make('allegro_status')->label('Status Allegro')->badge()->sortable()->searchable(),
                Tables\Columns\TextColumn::make('quantity')->label('Ilość')->sortable(),
                Tables\Columns\TextColumn::make('ebay_status')->label('eBay')->badge()->sortable(),
                Tables\Columns\TextColumn::make('imported_at')->label('Import')->dateTime('Y-m-d H:i')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('updated_from_allegro_at')->label('Akt. Allegro')->dateTime('Y-m-d H:i')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Zaktualizowano')->dateTime('Y-m-d H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('allegro_status')->label('Status Allegro')->options(fn (): array => self::statusOptions('allegro_status')),
                Tables\Filters\SelectFilter::make('ebay_status')->label('Status eBay')->options(self::ebayStatusOptions()),
                Tables\Filters\TernaryFilter::make('missing_images')->label('Brak zdjęć')->queries(true: fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->whereNull('main_image_url')->orWhere('main_image_url', '')), false: fn (Builder $query): Builder => $query->whereNotNull('main_image_url')->where('main_image_url', '<>', '')),
                Tables\Filters\TernaryFilter::make('missing_price')->label('Brak ceny')->queries(true: fn (Builder $query): Builder => $query->whereNull('price')->orWhere('price', '<=', 0), false: fn (Builder $query): Builder => $query->whereNotNull('price')->where('price', '>', 0)),
                self::rangeFilter('price', 'Cena'),
                Tables\Filters\Filter::make('imported_at')->label('Data importu')->form([Grid::make(2)->schema([Forms\Components\DatePicker::make('from')->label('Od'), Forms\Components\DatePicker::make('until')->label('Do')])])->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['from'] ?? null), fn (Builder $q) => $q->whereDate('imported_at', '>=', $data['from']))->when(filled($data['until'] ?? null), fn (Builder $q) => $q->whereDate('imported_at', '<=', $data['until']))),
            ])
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\EditAction::make()->label('Edytuj')->url(fn (JarekGearbox $record): string => static::getUrl('edit', ['record' => $record])),
                Tables\Actions\ViewAction::make()->label('Podgląd')->color('gray')->url(fn (JarekGearbox $record): string => static::getUrl('view', ['record' => $record])),
                Tables\Actions\Action::make('ebay_preview')->label('eBay preview/dry-run')->url(fn (JarekGearbox $record): string => route('admin.tools.jarek-gearboxes.ebay-preview', $record))->openUrlInNewTab(),
            ])
            ->defaultSort('updated_from_allegro_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListJarekGearboxes::route('/'), 'view' => Pages\ViewJarekGearbox::route('/{record}'), 'edit' => Pages\EditJarekGearbox::route('/{record}/edit')];
    }

    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make(static::getNavigationLabel())
                ->group(static::getNavigationGroup())
                ->sort(static::getNavigationSort())
                ->url(static::getUrl('index'))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.jarek-gearboxes.*')),
        ];
    }

    public static function canCreate(): bool { return false; }
    public static function canViewAny(): bool { return auth()->check(); }
    public static function canView(Model $record): bool { return auth()->check(); }
    public static function canEdit(Model $record): bool { return auth()->check(); }
    public static function canDelete(Model $record): bool { return false; }

    public static function rangeFilter(string $field, string $label): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make($field)->label($label)->form([Grid::make(2)->schema([Forms\Components\TextInput::make('from')->label('Od')->numeric(), Forms\Components\TextInput::make('until')->label('Do')->numeric()])])->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['from'] ?? null), fn (Builder $q) => $q->where($field, '>=', $data['from']))->when(filled($data['until'] ?? null), fn (Builder $q) => $q->where($field, '<=', $data['until'])));
    }

    private static function ebayStatusOptions(): array
    {
        return ['not_ready' => 'not_ready', 'previewed' => 'previewed', 'ready' => 'ready', 'published' => 'published'];
    }

    private static function statusOptions(string $field, ?string $current = null): array
    {
        $values = JarekGearbox::query()->whereNotNull($field)->distinct()->orderBy($field)->pluck($field)->filter()->values()->all();

        if (filled($current) && ! in_array($current, $values, true)) {
            $values[] = $current;
        }

        return collect($values)->mapWithKeys(fn (string $value): array => [$value => $value])->all();
    }

    private static function imagesPreviewHtml(?JarekGearbox $record): string
    {
        $images = collect([$record?->main_image_url])->merge((array) ($record?->images ?? []))->filter()->unique()->take(12);

        if ($images->isEmpty()) {
            return '<div class="text-sm text-gray-500">Brak zdjęć.</div>';
        }

        return '<div class="flex flex-wrap gap-3">'.$images->map(fn (string $url): string => '<a href="'.e($url).'" target="_blank" rel="noopener" class="block"><img src="'.e($url).'" alt="Zdjęcie skrzyni" class="h-24 w-24 rounded-lg object-cover ring-1 ring-gray-200" loading="lazy"></a>')->implode('').'</div>';
    }
}
