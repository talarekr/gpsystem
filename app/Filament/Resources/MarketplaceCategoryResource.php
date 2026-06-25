<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketplaceCategoryResource\Pages;
use App\Models\MarketplaceCategoryMapping;
use App\Models\PartCategory;
use App\Services\Marketplace\LocalCategoryDeleteSafetyService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class MarketplaceCategoryResource extends Resource
{
    protected static ?string $model = PartCategory::class;
    protected static ?string $modelLabel = 'Kategoria Marketplace';
    protected static ?string $pluralModelLabel = 'Kategorie Marketplace';
    protected static ?string $navigationLabel = 'Kategorie Marketplace';
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?int $navigationSort = 30;
    protected static ?string $slug = 'marketplace-categories';
    protected static ?string $navigationIcon = null;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Kategoria lokalna')->schema([
                Grid::make(3)->schema([
                    Placeholder::make('local_category_id')->label('local_category_id')->content(fn (PartCategory $record): int => $record->id),
                    Placeholder::make('name')->label('Obecny name')->content(fn (PartCategory $record): ?string => $record->name),
                    TextInput::make('local_category_name')
                        ->label('Local category name')
                        ->helperText('Zmienia tylko lokalną nazwę kategorii w GP System. Nie zmienia marketplace ani produktów.')
                        ->default(fn (PartCategory $record): ?string => $record->name)
                        ->maxLength(255)
                        ->required()
                        ->dehydrated(false),
                    Actions::make([
                        Action::make('saveLocalCategoryName')
                            ->label('Zapisz nazwę lokalną')
                            ->color('primary')
                            ->action(fn (PartCategory $record, Forms\Get $get) => static::saveLocalCategoryName($record, $get('local_category_name'))),
                    ]),
                    Placeholder::make('slug')->label('slug')->content(fn (PartCategory $record): ?string => $record->slug),
                    Placeholder::make('path')->label('Aktualny path')->content(fn (PartCategory $record): ?string => $record->category_path ?: $record->full_slug_path),
                    Placeholder::make('old_category_id')->label('old_category_id / external_id')->content(fn (PartCategory $record): ?string => $record->external_id),
                    Placeholder::make('parts_count')->label('products_count')->content(fn (PartCategory $record): int => $record->parts()->count()),
                    Placeholder::make('children_count')->label('children_count')->content(fn (PartCategory $record): int => $record->children()->count()),
                    Placeholder::make('legacy_source')->label('legacy source')->content(fn (PartCategory $record): ?string => $record->source_system),
                ]),
            ]),
            static::mappingSection('Ovoko', 'ovoko', false),
            static::mappingSection('Allegro', 'allegro_main', false),
            static::mappingSection('eBay DE', 'ebay_de', true),
            static::mappingSection('eBay FR', 'ebay_fr', true),
            static::mappingSection('eBay generic', 'ebay', true),
        ]);
    }


    public static function saveLocalCategoryName(PartCategory $record, mixed $value): void
    {
        $newName = trim((string) ($value ?? ''));

        if ($newName === '') {
            throw ValidationException::withMessages([
                'local_category_name' => 'Nazwa kategorii jest wymagana.',
            ]);
        }

        if (mb_strlen($newName) > 255) {
            throw ValidationException::withMessages([
                'local_category_name' => 'Nazwa kategorii może mieć maksymalnie 255 znaków.',
            ]);
        }

        $exists = PartCategory::query()
            ->where('name', $newName)
            ->whereKeyNot($record->getKey())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'local_category_name' => 'Taka nazwa kategorii już istnieje. Zmień nazwę inaczej albo użyj public display helpera.',
            ]);
        }

        $oldName = (string) $record->name;
        $productsCount = $record->parts()->count();
        $childrenCount = $record->children()->count();

        if ($oldName !== $newName) {
            $record->forceFill(['name' => $newName])->save();
        }

        $cacheCleared = static::clearLocalCategoryNameCaches();

        Log::info('marketplace_categories.local_category_name_updated', [
            'user_id' => Auth::id(),
            'marketplace_category_id' => $record->id,
            'local_category_id' => $record->id,
            'old_name' => $oldName,
            'new_name' => $newName,
            'products_count' => $productsCount,
            'children_count' => $childrenCount,
            'cache_keys_cleared' => $cacheCleared,
            'local_update' => true,
            'products_changed' => false,
            'children_changed' => false,
            'mappings_changed' => false,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'marketplace_writes' => false,
            'offers_changed' => false,
        ]);

        Notification::make()
            ->title('Lokalna nazwa kategorii została zaktualizowana.')
            ->body('Wyczyszczono cache: '.implode(', ', $cacheCleared))
            ->success()
            ->send();
    }

    private static function clearLocalCategoryNameCaches(): array
    {
        $keys = ['storefront.category_tree.v2', 'storefront.category_tree.v1', 'marketplace_mapper.local_tree'];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Artisan::call('view:clear');

        return array_merge($keys, ['view:clear']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['parts', 'children'])->with('marketplaceMappings'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()->searchable(),
                TextColumn::make('name')->label('Nazwa')->sortable()->searchable(),
                TextColumn::make('category_path')->label('Ścieżka')->searchable()->wrap()->formatStateUsing(fn ($state, PartCategory $record) => $state ?: $record->full_slug_path),
                TextColumn::make('parts_count')->label('Produkty')->sortable(),
                TextColumn::make('shop_status')->label('Sklep')->state(fn (PartCategory $record): string => 'lokalna / aktywna'),
                TextColumn::make('ovoko_status')->label('Ovoko')->state(fn (PartCategory $record): HtmlString => static::mappingStatus($record, 'ovoko'))->html()->searchable(query: static::externalSearch('ovoko')),
                TextColumn::make('allegro_status')->label('Allegro')->state(fn (PartCategory $record): HtmlString => static::mappingStatus($record, 'allegro_main'))->html()->searchable(query: static::externalSearch('allegro_main')),
                TextColumn::make('ebay_de_status')->label('eBay DE')->state(fn (PartCategory $record): HtmlString => static::mappingStatus($record, 'ebay_de'))->html()->searchable(query: static::externalSearch('ebay_de')),
                TextColumn::make('ebay_fr_status')->label('eBay FR')->state(fn (PartCategory $record): HtmlString => static::mappingStatus($record, 'ebay_fr'))->html()->searchable(query: static::externalSearch('ebay_fr')),
                TextColumn::make('ebay_blocked')->label('eBay blokada')->state(fn (PartCategory $record): string => $record->marketplaceMappings->whereIn('channel', ['ebay', 'ebay_de', 'ebay_fr'])->contains('is_blocked', true) ? 'tak' : 'nie'),
                TextColumn::make('ebay_fulfillment')->label('Wysyłka eBay / fulfillment_policy_id')->state(fn (PartCategory $record): string => $record->marketplaceMappings->whereIn('channel', ['ebay_de', 'ebay_fr', 'ebay'])->pluck('fulfillment_policy_id')->filter()->implode(', ')),
            ])
            ->filters([
                SelectFilter::make('channel')->options(['ovoko'=>'ovoko','allegro_main'=>'allegro_main','ebay'=>'ebay','ebay_de'=>'ebay_de','ebay_fr'=>'ebay_fr'])->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->whereHas('marketplaceMappings', fn ($q) => $q->where('channel', $data['value'])) : $query),
                SelectFilter::make('missing')->options(['missing_any'=>'missing_any','missing_ebay_de'=>'missing_ebay_de','missing_ebay_fr'=>'missing_ebay_fr','missing_ovoko'=>'missing_ovoko','missing_allegro'=>'missing_allegro'])->query(function (Builder $query, array $data): Builder { $v=$data['value']??null; $map=['missing_ebay_de'=>'ebay_de','missing_ebay_fr'=>'ebay_fr','missing_ovoko'=>'ovoko','missing_allegro'=>'allegro_main']; if ($v==='missing_any') return $query->where(fn($q)=>collect(['ovoko','allegro_main','ebay_de','ebay_fr'])->each(fn($ch)=>$q->orWhereDoesntHave('marketplaceMappings', fn($m)=>$m->where('channel',$ch)->whereNotNull('external_category_id')))); return isset($map[$v]) ? $query->whereDoesntHave('marketplaceMappings', fn($m)=>$m->where('channel',$map[$v])->whereNotNull('external_category_id')) : $query; }),
                SelectFilter::make('blocked')->options(['blocked'=>'blocked','not_blocked'=>'not_blocked'])->query(fn (Builder $query, array $data): Builder => ($data['value']??null)==='blocked' ? $query->whereHas('marketplaceMappings', fn($q)=>$q->where('is_blocked', true)) : ((($data['value']??null)==='not_blocked') ? $query->whereDoesntHave('marketplaceMappings', fn($q)=>$q->where('is_blocked', true)) : $query)),
                SelectFilter::make('used')->options(['used_by_parts'=>'used_by_parts','unused'=>'unused'])->query(fn (Builder $query, array $data): Builder => ($data['value']??null)==='used_by_parts' ? $query->has('parts') : ((($data['value']??null)==='unused') ? $query->doesntHave('parts') : $query)),
                SelectFilter::make('leaf')->options(['leaf'=>'leaf','parent'=>'parent'])->query(fn (Builder $query, array $data): Builder => ($data['value']??null)==='leaf' ? $query->doesntHave('children') : ((($data['value']??null)==='parent') ? $query->has('children') : $query)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Edytuj'),
                Tables\Actions\Action::make('deleteLocalCategory')
                    ->label('Usuń kategorię')
                    ->modalHeading('Usuń kategorię')
                    ->modalSubmitActionLabel('Usuń kategorię')
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->form([
                        Checkbox::make('confirm')
                            ->label('Potwierdzam lokalny hard delete PartCategory bez usuwania ofert, produktów, dzieci, mappingów i bez cascade delete.')
                            ->accepted()
                            ->required(),
                    ])
                    ->modalContent(fn (PartCategory $record) => view('filament.resources.marketplace-categories.delete-safety-preview', [
                        'safety' => app(LocalCategoryDeleteSafetyService::class)->inspect((int) $record->id),
                    ]))
                    ->modalSubmitAction(fn ($action, PartCategory $record) => $action->disabled(! (app(LocalCategoryDeleteSafetyService::class)->inspect((int) $record->id)['can_delete'] ?? false)))
                    ->action(function (PartCategory $record): void {
                        $service = app(LocalCategoryDeleteSafetyService::class);
                        $safety = $service->inspect((int) $record->id);

                        if (! ($safety['can_delete'] ?? false)) {
                            Notification::make()
                                ->title('Usunięcie kategorii jest zablokowane')
                                ->body(collect($safety['blockers'] ?? [])->implode(', ') ?: 'Brak pozytywnej walidacji bezpieczeństwa.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $categoryId = (int) $record->id;
                        $name = (string) $record->name;
                        $path = $record->category_path ?: $record->full_slug_path ?: $record->name;
                        $cacheCleared = $service->hardDelete($record);

                        Log::info('marketplace_categories.local_category_hard_deleted_from_list', [
                            'user_id' => Auth::id(),
                            'category_id' => $categoryId,
                            'name' => $name,
                            'category_path' => $path,
                            'counts_before_delete' => $safety['counts'] ?? [],
                            'cache_keys_cleared' => $cacheCleared,
                        ]);

                        Notification::make()
                            ->title('Kategoria została usunięta')
                            ->body('Wyczyszczono cache: '.implode(', ', $cacheCleared))
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMarketplaceCategories::route('/'), 'edit' => Pages\EditMarketplaceCategory::route('/{record}/edit')];
    }

    private static function mappingSection(string $label, string $channel, bool $ebay): Section
    {
        $fields = [TextInput::make("mappings.$channel.external_category_id")->label('external_category_id')->maxLength(255), TextInput::make("mappings.$channel.external_category_name")->label('external_category_name')->maxLength(255), TextInput::make("mappings.$channel.external_category_path")->label('external_category_path')->maxLength(255), Textarea::make("mappings.$channel.notes")->label('notes')->rows(2)];
        if ($ebay) array_splice($fields, 3, 0, [Toggle::make("mappings.$channel.is_blocked")->label('is_blocked'), TextInput::make("mappings.$channel.block_reason")->label('block_reason')->maxLength(255), TextInput::make("mappings.$channel.shipping_group")->label('shipping_group')->maxLength(255), TextInput::make("mappings.$channel.fulfillment_policy_id")->label('fulfillment_policy_id')->maxLength(255)]);
        return Section::make($label.' (channel: '.$channel.')')->schema($fields)->columns(2);
    }

    private static function mappingStatus(PartCategory $record, string $channel): HtmlString
    {
        $m = $record->marketplaceMappings->firstWhere('channel', $channel);
        if (! $m || blank($m->external_category_id)) return new HtmlString('<span style="color:#b91c1c">✕ brak</span>');
        $blocked = $m->is_blocked ? ' <strong>blocked</strong>' : '';
        return new HtmlString('<span style="color:#15803d">✓ '.e($m->external_category_id).'</span>'.$blocked);
    }

    private static function externalSearch(string $channel): \Closure
    {
        return fn (Builder $query, string $search): Builder => $query->orWhereHas('marketplaceMappings', fn ($q) => $q->where('channel', $channel)->where('external_category_id', 'like', "%{$search}%"));
    }
}
