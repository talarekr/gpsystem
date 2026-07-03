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
use Illuminate\Support\Facades\Schema;
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
        return PartResource::form($form);
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
            NavigationItem::make(static::navigationLabelWithCount(static::getNavigationLabel(), static::getJarekGearboxesNavigationCount()))
                ->group(static::getNavigationGroup())
                ->sort(static::getNavigationSort())
                ->url(static::getUrl('index'))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.jarek-gearboxes.*')),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getJarekGearboxesNavigationCount();
    }

    public static function getJarekGearboxesNavigationCount(): int
    {
        if (! Schema::hasTable('jarek_gearboxes')) {
            return 0;
        }

        return JarekGearbox::count();
    }

    private static function navigationLabelWithCount(string $label, int $count): string
    {
        return sprintf('%s (%d)', $label, $count);
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


    private static function jarekMarketplacePreviewHtml(?JarekGearbox $record): string
    {
        if (! $record || ! $record->exists) {
            return '<div class="text-sm text-gray-500">Zapisz skrzynię, aby zobaczyć podgląd gotowości eBay. Ovoko i Allegro main są wyłączone.</div>';
        }

        $previewUrl = route('admin.tools.jarek-gearboxes.ebay-preview', $record);
        $allegroStatus = e($record->allegro_status ?: '—');
        $ebayStatus = e($record->ebay_status ?: 'not_ready');

        return '<div class="space-y-2 text-sm text-gray-700">'
            .'<div><strong>Allegro Jarka:</strong> '. $allegroStatus .' (źródło/import, bez Allegro main write)</div>'
            .'<div><strong>Ovoko:</strong> wyłączone dla Skrzyń Jarka</div>'
            .'<div><strong>eBay:</strong> '. $ebayStatus .' · <a class="text-primary-600 underline" href="'.e($previewUrl).'" target="_blank" rel="noopener">preview / dry-run</a></div>'
            .'</div>';
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
